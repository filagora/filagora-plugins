<?php

declare(strict_types=1);

use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Plugins\Enums\PluginReportStatus;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginModeratorNote;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginReport;
use Filagora\Plugins\Models\PluginSubscription;
use Filagora\Plugins\Privacy\PluginsDataContributor;
use Filagora\Plugins\Services\Reports;
use Filagora\Plugins\Services\Subscriptions;
use Filagora\Plugins\Tests\Support\TestUser;

/**
 * Ada owns a plugin, uploaded a release of it, follows another plugin and reported a third.
 *
 * @return array{TestUser, Plugin, Plugin, Plugin}
 */
function adasLibrary(): array
{
    $ada = TestUser::make('Ada');
    $bo = TestUser::make('Bo');
    $mine = livePlugin($ada, PluginStatus::ACTIVE, 'mine');
    $followed = livePlugin($bo, PluginStatus::ACTIVE, 'followed');
    $reported = livePlugin($bo, PluginStatus::ACTIVE, 'reported');
    addRelease($followed, '2.0.0', PluginStatus::ACTIVE, ['uploaded_by_id' => $ada->id, 'changelog' => 'Ada\'s change']);
    app(Subscriptions::class)->subscribe($ada, $followed);
    app(Reports::class)->report($ada, $reported, 'Phones home');
    app(Reports::class)->report($bo, $mine, 'Bo\'s own report');
    PluginModeratorNote::create(['plugin_id' => $mine->id, 'moderator_id' => moderator('Mo')->id, 'note' => 'Checked']);

    return [$ada, $mine, $followed, $reported];
}

it('registers itself with the privacy contributors under its own key', function () {
    expect(app(PrivacyContributorRegistry::class)->all())->toHaveKey('plugins');
});

it('exports what the library holds about a person, and only about them', function () {
    [$ada, $mine, $followed, $reported] = adasLibrary();

    $export = (new PluginsDataContributor)->export($ada);

    expect($export['plugins'])->toHaveCount(1)
        ->and($export['plugins'][0])->toMatchArray(['id' => $mine->id, 'slug' => 'mine', 'status' => 'active'])
        ->and(array_column($export['releases'], 'version'))->toEqualCanonicalizing(['v1.0.0', '2.0.0'])
        ->and(collect($export['releases'])->firstWhere('version', '2.0.0')['changelog'])->toBe('Ada\'s change')
        ->and(array_column($export['subscriptions'], 'plugin_id'))->toBe([$followed->id])
        ->and($export['reports'])->toHaveCount(1)
        ->and($export['reports'][0])->toMatchArray(['plugin_id' => $reported->id, 'reason' => 'Phones home', 'status' => 'open']);
});

it('exports empty sections for someone with nothing in the library', function () {
    adasLibrary();

    expect((new PluginsDataContributor)->export(TestUser::make('Nobody')))->toBe(['plugins' => [], 'releases' => [], 'subscriptions' => [], 'reports' => []]);
});

it('erases what is only theirs, and leaves the public work and the staff notes', function () {
    [$ada, $mine, $followed] = adasLibrary();

    (new PluginsDataContributor)->erase($ada);

    expect(PluginSubscription::where('user_id', $ada->id)->count())->toBe(0)
        ->and(PluginReport::where('reporter_id', $ada->id)->count())->toBe(0)
        ->and(PluginReport::count())->toBe(1)
        ->and(Plugin::whereKey($mine->id)->exists())->toBeTrue()
        ->and($mine->fresh()->owner_id)->toBe($ada->id)
        ->and(PluginRelease::where('uploaded_by_id', $ada->id)->count())->toBe(2)
        ->and(PluginModeratorNote::count())->toBe(1);
});

it('does not touch another person\'s subscriptions or reports when it erases', function () {
    [$ada, , $followed] = adasLibrary();
    $bo = TestUser::make('Bo2');
    app(Subscriptions::class)->subscribe($bo, $followed);
    $report = app(Reports::class)->report($bo, $followed, 'Other');

    (new PluginsDataContributor)->erase($ada);

    expect(PluginSubscription::where('user_id', $bo->id)->count())->toBe(1)
        ->and($report->fresh()->status)->toBe(PluginReportStatus::OPEN);
});
