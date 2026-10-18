<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\ModerationAction;
use Filagora\Plugins\Enums\PluginReportStatus;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Notifications\PluginNotification;
use Filagora\Plugins\Services\Moderation;
use Filagora\Plugins\Services\Releases;
use Filagora\Plugins\Services\Reports;
use Filagora\Plugins\Services\Subscriptions;
use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

beforeEach(function () {
    Storage::fake('local');
    Notification::fake();
    config(['filagora-plugins.mail.support_email' => 'support@example.test']);
});

function kindsSentTo(object $notifiable): array
{
    return Notification::sent($notifiable, PluginNotification::class)->map(fn (PluginNotification $n): string => $n->kind)->all();
}

function newPluginBy(TestUser $author): PluginRelease
{
    return app(Releases::class)->submitPlugin($author, ['name' => 'Mail Widget', 'plugin_type_id' => pluginType()->id], releaseInput());
}

it('thanks the author and tells support when a plugin is submitted', function () {
    $author = TestUser::make('Ada');

    newPluginBy($author);

    expect(kindsSentTo($author))->toBe(['submitted']);
    Notification::assertSentOnDemand(PluginNotification::class, fn (PluginNotification $n, array $channels, AnonymousNotifiable $notifiable): bool => $n->kind === 'submitted_support' && $notifiable->routes['mail'] === 'support@example.test' && $n->replace['user'] === 'Ada');
});

it('says "published" to an author who went live at once', function () {
    $author = TestUser::make('Ada');
    app(GrantedKeys::class)->give($author, 'filagora.plugins.auto_publish');

    newPluginBy($author);

    expect(kindsSentTo($author))->toBe(['submitted_live']);
});

it('keeps quiet about receipts when they are switched off, and about support when no address is set', function () {
    config(['filagora-plugins.mail.acknowledge_submissions' => false, 'filagora-plugins.mail.support_email' => '']);

    newPluginBy($author = TestUser::make('Ada'));

    Notification::assertNothingSent();
});

it('sends nothing at all when mail is switched off', function () {
    config(['filagora-plugins.mail.enabled' => false]);
    $author = TestUser::make('Ada');
    $release = newPluginBy($author);

    app(Moderation::class)->moderateRelease(moderator(), $release->plugin, $release, ModerationAction::APPROVE);
    app(Reports::class)->report(TestUser::make('Rita'), $release->plugin, 'x');

    Notification::assertNothingSent();
});

it('tells the author when a moderator approves a release, and followers hear too', function () {
    $author = TestUser::make('Ada');
    $fan = TestUser::make('Fan');
    $release = newPluginBy($author);
    app(Subscriptions::class)->subscribe($fan, $release->plugin);
    app(Subscriptions::class)->subscribe($author, $release->plugin);

    app(Moderation::class)->moderateRelease(moderator(), $release->plugin, $release, ModerationAction::APPROVE);

    expect(kindsSentTo($author))->toBe(['submitted', 'release_approved'])
        ->and(kindsSentTo($fan))->toBe(['release_updated']);
});

it('does not send an approval to an author who published without review, but followers still hear', function () {
    $author = TestUser::make('Ada');
    $fan = TestUser::make('Fan');
    app(GrantedKeys::class)->give($author, 'filagora.plugins.moderate');
    $plugin = livePlugin($author);
    app(Subscriptions::class)->subscribe($fan, $plugin);

    app(Releases::class)->submitRelease($author, $plugin, releaseInput('1.1.0'));

    expect(kindsSentTo($author))->toBe(['submitted_live'])
        ->and(kindsSentTo($fan))->toBe(['release_updated']);
});

it('tells the author, with the reason, when a release is rejected', function () {
    $author = TestUser::make('Ada');
    $release = newPluginBy($author);

    app(Moderation::class)->moderateRelease(moderator(), $release->plugin, $release, ModerationAction::REJECT_PERMANENTLY, 'Copied code');

    $sent = Notification::sent($author, PluginNotification::class)->last();

    expect($sent->kind)->toBe('release_rejected')->and($sent->details)->toBe(['Copied code']);
});

it('tells the owner when their plugin is approved or rejected', function () {
    $owner = TestUser::make('Ada');
    $approved = livePlugin($owner, PluginStatus::MODERATED);
    $rejected = livePlugin($owner, PluginStatus::MODERATED, 'other');

    app(Moderation::class)->moderatePlugin(moderator(), $approved, ModerationAction::APPROVE);
    app(Moderation::class)->moderatePlugin(moderator('Mo2'), $rejected, ModerationAction::REJECT_PERMANENTLY, 'No');

    expect(kindsSentTo($owner))->toBe(['plugin_approved', 'plugin_rejected']);
});

it('tells the author of a replaced release, but not the person who replaced it', function () {
    $first = TestUser::make('Ada');
    $second = TestUser::make('Bo');
    $plugin = livePlugin($first, PluginStatus::MODERATED);

    app(Releases::class)->submitRelease($second, $plugin, releaseInput('1.1.0', supersede: true));

    expect(kindsSentTo($first))->toBe(['release_rejected'])
        ->and(kindsSentTo($second))->toBe(['submitted']);

    $plugin = livePlugin($second, PluginStatus::MODERATED, 'solo');
    app(Releases::class)->submitRelease($second, $plugin, releaseInput('1.1.0', supersede: true));

    expect(kindsSentTo($second))->toBe(['submitted', 'submitted']);
});

it('tells support of a report, and the reporter when it is dealt with', function () {
    $reporter = TestUser::make('Rita');
    $plugin = livePlugin();

    $report = app(Reports::class)->report($reporter, $plugin, 'Spam');

    Notification::assertSentOnDemand(PluginNotification::class, fn (PluginNotification $n): bool => $n->kind === 'report_submitted' && $n->details === ['Spam']);

    app(Reports::class)->review(moderator(), $report, PluginReportStatus::REVIEWED, 'Removed');

    $sent = Notification::sent($reporter, PluginNotification::class)->first();

    expect($sent->kind)->toBe('report_reviewed')->and($sent->replace['status'])->toBe('Reviewed');
});

it('writes the mail from the language file and links to the plugin page when a route is named', function () {
    Route::get('/plugins/{plugin}', fn () => 'ok')->name('plugin.page');
    Route::getRoutes()->refreshNameLookups();
    config(['filagora-plugins.routes.plugin' => 'plugin.page']);
    $author = TestUser::make('Ada');
    $release = newPluginBy($author);

    $mail = Notification::sent($author, PluginNotification::class)->first()->toMail($author);

    expect($mail->subject)->toBe('Plugin submission received: Mail Widget v1.0.0')
        ->and($mail->actionUrl)->toEndWith('/plugins/'.$release->plugin->id)
        ->and($mail->actionText)->toBe('View the plugin');
});

it('sends the mail without a link when no route is named', function () {
    $author = TestUser::make('Ada');
    newPluginBy($author);

    expect(Notification::sent($author, PluginNotification::class)->first()->toMail($author)->actionUrl)->toBeNull();
});
