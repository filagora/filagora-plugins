<?php

declare(strict_types=1);

use Filagora\Plugins\Archiving\ArchiveGuards;
use Filagora\Plugins\Archiving\Archiving;
use Filagora\Plugins\Contracts\ArchiveGuard;
use Filagora\Plugins\Contracts\DownloadAccess;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Events\ReleasesArchived;
use Filagora\Plugins\Events\ReleasesRestored;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginModeratorNote;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Search\ReleaseSearchIndex;
use Filagora\Plugins\Services\Visibility;
use Filagora\Plugins\Tests\Support\TestUser;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;

beforeEach(function () {
    Carbon::setTestNow('2026-10-19 12:00:00');
});

afterEach(function () {
    Carbon::setTestNow();
});

function planned(array $options = []): array
{
    return app(Archiving::class)->plan($options)->plugins;
}

/** @return list<string> versions */
function versionsOf(Plugin $plugin, array $ids): array
{
    return PluginRelease::query()->whereKey($ids)->orderBy('id')->pluck('version')->all();
}

it('keeps a plugin\'s newest release and anything uploaded recently, and archives the rest', function () {
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 40, '3.0.0' => 17, '4.0.0' => 2]);

    $row = planned()[$plugin->id];

    expect(versionsOf($plugin, array_keys($row['keep'])))->toBe(['3.0.0', '4.0.0'])
        ->and(versionsOf($plugin, $row['archive']))->toBe(['1.0.0', '2.0.0'])
        ->and($row['keep'][$plugin->releases()->where('version', '4.0.0')->value('id')])->toBe('current build')
        ->and($row['keep'][$plugin->releases()->where('version', '3.0.0')->value('id')])->toBe('uploaded within 18mo');
});

it('has nothing to say about a plugin whose releases are all recent, or that has only one', function () {
    archivable('fresh', ['1.0.0' => 10, '1.1.0' => 2]);
    archivable('single', ['1.0.0' => 100]);

    expect(planned())->toBe([]);
});

it('counts only active releases, and skips plugins with none', function () {
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 2]);
    addRelease($plugin, '0.5.0', PluginStatus::QUARANTINED);
    addRelease($plugin, '0.6.0', PluginStatus::MODERATED);
    Plugin::create(['name' => 'Empty', 'slug' => 'empty', 'plugin_type_id' => pluginType()->id, 'status' => PluginStatus::ACTIVE]);

    $plan = app(Archiving::class)->plan();

    expect(array_keys($plan->plugins))->toBe([$plugin->id])
        ->and($plan->totals['plugins'])->toBe(1)
        ->and($plan->totals['active'])->toBe(2)
        ->and($plan->totals['eligible'])->toBe(1);
});

it('honours the number of months it is given, over the configuration', function () {
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 17, '3.0.0' => 2]);

    expect(versionsOf($plugin, planned(['keepMonths' => 0])[$plugin->id]['archive']))->toBe(['1.0.0', '2.0.0'])
        ->and(versionsOf($plugin, planned(['keepMonths' => 6])[$plugin->id]['archive']))->toBe(['1.0.0', '2.0.0'])
        ->and(versionsOf($plugin, planned(['keepMonths' => 24])[$plugin->id]['archive']))->toBe(['1.0.0']);

    config(['filagora-plugins.archiving.keep_months' => 6]);

    expect(versionsOf($plugin, planned()[$plugin->id]['archive']))->toBe(['1.0.0', '2.0.0']);
});

it('keeps the newest build for each version line the plugin still works with', function () {
    $compatibility = fakeCompatibility();
    $plugin = archivable('widget', ['1.0.0' => 70, '1.1.0' => 60, '2.0.0' => 50, '3.0.0' => 40]);
    $byVersion = $plugin->releases->keyBy('version');
    $compatibility->declare($byVersion['1.0.0'], '1.4.2');
    $compatibility->declare($byVersion['1.1.0'], '1.5.7');
    $compatibility->declare($byVersion['2.0.0'], '1.5.8');
    $compatibility->declare($byVersion['3.0.0'], '2.0.0', '2.1.0');

    $row = planned()[$plugin->id];

    expect(versionsOf($plugin, array_keys($row['keep'])))->toBe(['1.0.0', '2.0.0', '3.0.0'])
        ->and(versionsOf($plugin, $row['archive']))->toBe(['1.1.0'])
        ->and($row['keep'][$byVersion['1.0.0']->id])->toBe('last active build for 1.4')
        ->and($row['keep'][$byVersion['2.0.0']->id])->toBe('last active build for 1.5')
        ->and($row['keep'][$byVersion['3.0.0']->id])->toBe('current build');
});

it('can leave the version lines out', function () {
    $compatibility = fakeCompatibility();
    $plugin = archivable('widget', ['1.0.0' => 70, '2.0.0' => 40]);
    $compatibility->declare($plugin->releases->firstWhere('version', '1.0.0'), '1.5.7');
    $compatibility->declare($plugin->releases->firstWhere('version', '2.0.0'), '2.0.0');

    expect(planned()[$plugin->id] ?? null)->toBeNull()
        ->and(versionsOf($plugin, planned(['keepPerTargetLine' => false])[$plugin->id]['archive']))->toBe(['1.0.0']);

    config(['filagora-plugins.archiving.keep_per_target_line' => false]);

    expect(versionsOf($plugin, planned()[$plugin->id]['archive']))->toBe(['1.0.0']);
});

it('groups a label that is not a version by itself', function () {
    $compatibility = fakeCompatibility();
    $plugin = archivable('widget', ['1.0.0' => 70, '2.0.0' => 40]);
    $compatibility->declare($plugin->releases->firstWhere('version', '1.0.0'), 'legacy');

    expect(planned()[$plugin->id] ?? null)->toBeNull();
});

it('keeps a release a guard asks to keep', function () {
    $plugin = archivable('widget', ['1.0.0' => 70, '2.0.0' => 60, '3.0.0' => 2]);
    app(ArchiveGuards::class)->register(new class implements ArchiveGuard
    {
        public function reasonToKeep(Plugin $plugin, PluginRelease $release): ?string
        {
            return $release->version === '1.0.0' ? 'unresolved high finding' : null;
        }
    });

    $row = planned()[$plugin->id];

    expect(versionsOf($plugin, $row['archive']))->toBe(['2.0.0'])
        ->and($row['keep'][$plugin->releases->firstWhere('version', '1.0.0')->id])->toBe('unresolved high finding');
});

it('archives all but the newest release of a plugin that is dead weight', function (array $plugin, array $ages) {
    $model = archivable('dead', $ages, $plugin);

    $row = planned()[$model->id];

    expect(count($row['keep']))->toBe(1)
        ->and(array_values($row['keep']))->toBe(['last release (plugin archived)'])
        ->and(count($row['archive']))->toBe(count($ages) - 1);
})->with([
    'rejected' => [['status' => PluginStatus::REJECTED_FINAL], ['1.0.0' => 5, '2.0.0' => 2, '3.0.0' => 1]],
    'moderated for over a year' => [['status' => PluginStatus::MODERATED, 'updated_at' => '2025-01-01 00:00:00'], ['1.0.0' => 5, '2.0.0' => 1]],
    'ownerless and old' => [['owner_id' => null], ['1.0.0' => 60, '2.0.0' => 30]],
]);

it('does not treat an active, owned plugin or a recent moderated one as dead', function () {
    $owner = TestUser::make('Ada');
    $owned = archivable('owned', ['1.0.0' => 60, '2.0.0' => 30], ['owner_id' => $owner->id]);
    $recent = archivable('recent', ['1.0.0' => 60, '2.0.0' => 30], ['status' => PluginStatus::MODERATED, 'owner_id' => $owner->id]);
    $ownerlessButCurrent = archivable('current', ['1.0.0' => 60, '2.0.0' => 3], ['owner_id' => null]);

    expect(versionsOf($owned, planned()[$owned->id]['archive']))->toBe(['1.0.0'])
        ->and(versionsOf($recent, planned()[$recent->id]['archive']))->toBe(['1.0.0'])
        ->and(array_values(planned()[$ownerlessButCurrent->id]['keep']))->toBe(['current build'])
        ->and(versionsOf($ownerlessButCurrent, planned()[$ownerlessButCurrent->id]['archive']))->toBe(['1.0.0']);
});

it('treats a plugin in an inactive type as dead weight, unless told not to', function () {
    $off = PluginType::create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);
    $owner = TestUser::make('Ada');
    $plugin = archivable('off', ['1.0.0' => 5, '2.0.0' => 2], ['plugin_type_id' => $off->id, 'owner_id' => $owner->id]);

    expect(versionsOf($plugin, planned()[$plugin->id]['archive']))->toBe(['1.0.0'])
        ->and(planned(['wholePlugin' => false]))->toBe([]);
});

it('caps a run, and reports what was eligible', function () {
    archivable('a', ['1.0.0' => 60, '1.1.0' => 50, '2.0.0' => 2]);
    archivable('b', ['1.0.0' => 60, '2.0.0' => 2]);

    $all = app(Archiving::class)->plan();
    $capped = app(Archiving::class)->plan(['limit' => 2]);

    expect($all->totals['selected'])->toBe(3)
        ->and($capped->totals['selected'])->toBe(2)
        ->and($capped->totals['eligible'])->toBe(3)
        ->and($capped->archiveIds)->toHaveCount(2)
        ->and(collect($capped->plugins)->sum(fn (array $row): int => count($row['archive'])))->toBe(2);

    $one = app(Archiving::class)->plan(['limit' => 1]);

    expect(array_keys($one->plugins))->toHaveCount(1);
});

it('can be limited to some plugins', function () {
    $first = archivable('a', ['1.0.0' => 60, '2.0.0' => 2]);
    archivable('b', ['1.0.0' => 60, '2.0.0' => 2]);

    expect(array_keys(planned(['pluginIds' => [$first->id]])))->toBe([$first->id])
        ->and(planned(['pluginIds' => [-1]]))->toBe([]);
});

it('archives releases, says who and when, remembers the status before, and leaves a note per plugin', function () {
    Event::fake([ReleasesArchived::class]);
    $actor = TestUser::make('Admin');
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 50, '3.0.0' => 2]);
    $old = $plugin->releases->whereIn('version', ['1.0.0', '2.0.0']);
    $approvedAt = $old->first()->approved_at;

    $result = app(Archiving::class)->archive($old->pluck('id')->all(), $actor);

    $archived = $plugin->releases()->where('status', 'archived')->get();

    expect($result)->toBe(['archived' => 2, 'kept_back' => 0])
        ->and($archived)->toHaveCount(2)
        ->and($archived->every(fn (PluginRelease $r): bool => $r->status_before_archive === 'active' && $r->archived_by_id === $actor->id && $r->archived_at->equalTo(now())))->toBeTrue()
        ->and($archived->first()->approved_at?->equalTo($approvedAt))->toBeTrue()
        ->and(PluginModeratorNote::where('plugin_id', $plugin->id)->count())->toBe(1)
        ->and(PluginModeratorNote::first()->note)->toBe('Archived 2 release(s): v1.0.0, v2.0.0.')
        ->and(PluginModeratorNote::first()->moderator_id)->toBe($actor->id);
    Event::assertDispatched(ReleasesArchived::class, fn (ReleasesArchived $e): bool => count($e->releaseIds) === 2 && $e->actor->is($actor));
});

it('never archives a plugin\'s newest active release, whatever it is asked', function () {
    Event::fake([ReleasesArchived::class]);
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 50, '3.0.0' => 2]);

    $result = app(Archiving::class)->archive($plugin->releases->pluck('id')->all());

    expect($result)->toBe(['archived' => 2, 'kept_back' => 1])
        ->and(versionsWhere($plugin, PluginStatus::ACTIVE))->toBe(['3.0.0'])
        ->and(PluginModeratorNote::first()->moderator_id)->toBeNull();
});

it('keeps the only active release even when asked for just that one', function () {
    $plugin = archivable('widget', ['1.0.0' => 60]);

    $result = app(Archiving::class)->archive($plugin->releases->pluck('id')->all());

    expect($result)->toBe(['archived' => 0, 'kept_back' => 1])
        ->and(versionsWhere($plugin, PluginStatus::ACTIVE))->toBe(['1.0.0'])
        ->and(PluginModeratorNote::count())->toBe(0);
});

it('takes the newest release by upload time, then by row, as the one to keep', function () {
    $plugin = archivable('widget', ['1.0.0' => 5, '2.0.0' => 5]);
    $second = $plugin->releases()->where('version', '2.0.0')->first();
    PluginRelease::query()->update(['created_at' => '2026-01-01 00:00:00']);

    app(Archiving::class)->archive($plugin->releases()->pluck('id')->all());

    expect(versionsWhere($plugin, PluginStatus::ACTIVE))->toBe(['2.0.0'])
        ->and($second->fresh()->status)->toBe(PluginStatus::ACTIVE);
});

it('does not touch an unknown id, a release that is not active, or an empty list', function () {
    Event::fake([ReleasesArchived::class]);
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 2]);
    $waiting = addRelease($plugin, '0.1.0', PluginStatus::MODERATED);

    expect(app(Archiving::class)->archive([]))->toBe(['archived' => 0, 'kept_back' => 0])
        ->and(app(Archiving::class)->archive([999_999, $waiting->id]))->toBe(['archived' => 0, 'kept_back' => 0])
        ->and($waiting->fresh()->status)->toBe(PluginStatus::MODERATED);
    Event::assertNotDispatched(ReleasesArchived::class);
});

it('restores archived releases to the status they had, and clears the archive details', function () {
    Event::fake([ReleasesRestored::class]);
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 2]);
    app(Archiving::class)->archive($plugin->releases->pluck('id')->all(), TestUser::make('Admin'));
    $archivedId = $plugin->releases()->where('version', '1.0.0')->value('id');

    $count = app(Archiving::class)->restore([$archivedId, 999_999]);
    $release = PluginRelease::find($archivedId);

    expect($count)->toBe(1)
        ->and($release->status)->toBe(PluginStatus::ACTIVE)
        ->and($release->status_before_archive)->toBeNull()
        ->and($release->archived_at)->toBeNull()
        ->and($release->archived_by_id)->toBeNull();
    Event::assertDispatched(ReleasesRestored::class, fn (ReleasesRestored $e): bool => $e->releaseIds === [$archivedId]);
});

it('restores to active when it does not know the status before, and ignores what is not archived', function () {
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 2]);
    $live = $plugin->releases()->where('version', '2.0.0')->first();
    $odd = $plugin->releases()->where('version', '1.0.0')->first();
    $odd->forceFill(['status' => PluginStatus::ARCHIVED, 'status_before_archive' => 'nonsense', 'archived_at' => now()])->save();

    expect(app(Archiving::class)->restore([]))->toBe(0)
        ->and(app(Archiving::class)->restore([$live->id]))->toBe(0)
        ->and(app(Archiving::class)->restore([$odd->id]))->toBe(1)
        ->and($odd->fresh()->status)->toBe(PluginStatus::ACTIVE);
});

it('restores a release to a status other than active when that is what it had', function () {
    $plugin = archivable('widget', ['1.0.0' => 60]);
    $release = $plugin->releases->first();
    $release->forceFill(['status' => PluginStatus::ARCHIVED, 'status_before_archive' => 'moderated'])->save();

    app(Archiving::class)->restore([$release->id]);

    expect($release->fresh()->status)->toBe(PluginStatus::MODERATED);
});

it('leaves archived releases listed and downloadable, but out of search and the live list', function () {
    $plugin = archivable('widget', ['1.0.0' => 60, '2.0.0' => 2]);
    $old = $plugin->releases->firstWhere('version', '1.0.0');

    app(Archiving::class)->archive([$old->id]);

    $old->refresh();

    expect(app(Visibility::class)->publicReleases($plugin)->pluck('version')->all())->toBe(['2.0.0'])
        ->and(app(Visibility::class)->publicReleases($plugin, withArchived: true)->pluck('version')->sort()->values()->all())->toBe(['1.0.0', '2.0.0'])
        ->and(app(Visibility::class)->ownerReleases($plugin, TestUser::make('Other'))->count())->toBe(1)
        ->and(app(DownloadAccess::class)->canDownloadRelease(null, $old))->toBeTrue()
        ->and(app(ReleaseSearchIndex::class)->shouldBeSearchable($old))->toBeFalse();
});
