<?php

declare(strict_types=1);

use Filagora\Plugins\Contracts\SubscriberPreferences;
use Filagora\Plugins\Enums\ModerationRefusal;
use Filagora\Plugins\Enums\PluginReportStatus;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Events\PluginReported;
use Filagora\Plugins\Events\PluginReportReviewed;
use Filagora\Plugins\Events\SubscribersToNotify;
use Filagora\Plugins\Exceptions\ModerationRefused;
use Filagora\Plugins\Models\PluginSubscription;
use Filagora\Plugins\Services\Reports;
use Filagora\Plugins\Services\Subscriptions;
use Filagora\Plugins\Tests\Support\TestUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Event::fake([SubscribersToNotify::class, PluginReported::class, PluginReportReviewed::class]);
});

it('follows a plugin once however often it is asked, and can stop', function () {
    $user = TestUser::make('Sam');
    $plugin = livePlugin();

    app(Subscriptions::class)->subscribe($user, $plugin);
    app(Subscriptions::class)->subscribe($user, $plugin);

    expect(PluginSubscription::count())->toBe(1)
        ->and(app(Subscriptions::class)->isSubscribed($user, $plugin))->toBeTrue()
        ->and(app(Subscriptions::class)->isSubscribed(TestUser::make('Other'), $plugin))->toBeFalse();

    app(Subscriptions::class)->unsubscribe($user, $plugin);

    expect(app(Subscriptions::class)->isSubscribed($user, $plugin))->toBeFalse();
});

it('tells followers of a new release, except the author', function () {
    $author = TestUser::make('Ada');
    $fan = TestUser::make('Fan');
    $plugin = livePlugin($author);
    app(Subscriptions::class)->subscribe($author, $plugin);
    app(Subscriptions::class)->subscribe($fan, $plugin);

    app(Subscriptions::class)->notifyOfNewRelease($plugin, $plugin->releases->first(), $author);

    Event::assertDispatched(SubscribersToNotify::class, fn (SubscribersToNotify $e): bool => count($e->recipients) === 1 && $e->recipients[0]->is($fan));
});

it('says nothing when nobody follows, or nobody wants to hear', function () {
    $fan = TestUser::make('Fan');
    $plugin = livePlugin();

    app(Subscriptions::class)->notifyOfNewRelease($plugin, $plugin->releases->first());
    Event::assertNotDispatched(SubscribersToNotify::class);

    app(Subscriptions::class)->subscribe($fan, $plugin);
    app()->instance(SubscriberPreferences::class, new class implements SubscriberPreferences
    {
        public function wantsReleaseUpdates(Authenticatable $user): bool
        {
            return false;
        }
    });
    app()->forgetInstance(Subscriptions::class);

    app(Subscriptions::class)->notifyOfNewRelease($plugin, $plugin->releases->first());
    Event::assertNotDispatched(SubscribersToNotify::class);
});

function reportRefusal(Closure $work): ?ModerationRefusal
{
    try {
        $work();
    } catch (ModerationRefused $e) {
        return $e->reason;
    }

    return null;
}

it('takes a report on a plugin or on one of its releases', function () {
    $reporter = TestUser::make('Rita');
    $plugin = livePlugin();

    $report = app(Reports::class)->report($reporter, $plugin, '  Broken download  ', $plugin->releases->first());

    expect($report->status)->toBe(PluginReportStatus::OPEN)
        ->and($report->reason)->toBe('Broken download')
        ->and($report->plugin_release_id)->toBe($plugin->releases->first()->id)
        ->and($report->reporter_id)->toBe($reporter->id);
    Event::assertDispatched(PluginReported::class);
});

it('takes one open report per person per plugin, and another once the first is dealt with', function () {
    $reporter = TestUser::make('Rita');
    $plugin = livePlugin();
    $first = app(Reports::class)->report($reporter, $plugin, 'One');

    expect(reportRefusal(fn () => app(Reports::class)->report($reporter, $plugin, 'Two')))->toBe(ModerationRefusal::ALREADY_REPORTED)
        ->and(app(Reports::class)->report(TestUser::make('Other'), $plugin, 'Two'))->not->toBeNull();

    app(Reports::class)->review(moderator(), $first, PluginReportStatus::DISMISSED);

    expect(app(Reports::class)->report($reporter, $plugin, 'Again')->status)->toBe(PluginReportStatus::OPEN);
});

it('refuses a report on a release of another plugin', function () {
    $plugin = livePlugin();
    $other = livePlugin(null, PluginStatus::ACTIVE, 'other');

    expect(reportRefusal(fn () => app(Reports::class)->report(TestUser::make('Rita'), $plugin, 'x', $other->releases->first())))->toBe(ModerationRefusal::RELEASE_MISMATCH);
});

it('records who reviewed a report, when, and why', function () {
    $mo = moderator();
    $report = app(Reports::class)->report(TestUser::make('Rita'), livePlugin(), 'Spam');

    app(Reports::class)->review($mo, $report, PluginReportStatus::REVIEWED, ' Removed the link ');

    $report->refresh();

    expect($report->status)->toBe(PluginReportStatus::REVIEWED)
        ->and($report->resolution_note)->toBe('Removed the link')
        ->and($report->reviewed_by_id)->toBe($mo->id)
        ->and($report->reviewed_at)->not->toBeNull();
    Event::assertDispatched(PluginReportReviewed::class);
});

it('only tells the reporter when the status actually changed', function () {
    $mo = moderator();
    $report = app(Reports::class)->report(TestUser::make('Rita'), livePlugin(), 'Spam');

    app(Reports::class)->review($mo, $report, PluginReportStatus::REVIEWED);
    app(Reports::class)->review($mo, $report, PluginReportStatus::REVIEWED, 'Added a note');

    Event::assertDispatchedTimes(PluginReportReviewed::class, 1);
});

it('lets only a moderator review a report', function () {
    $report = app(Reports::class)->report(TestUser::make('Rita'), livePlugin(), 'Spam');

    expect(reportRefusal(fn () => app(Reports::class)->review(TestUser::make('Nobody'), $report, PluginReportStatus::DISMISSED)))->toBe(ModerationRefusal::NOT_PERMITTED)
        ->and($report->fresh()->status)->toBe(PluginReportStatus::OPEN);
});
