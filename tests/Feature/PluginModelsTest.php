<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\PluginReportStatus;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginModeratorNote;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginReport;
use Filagora\Plugins\Models\PluginSubscription;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Support\Models;
use Filagora\Plugins\Tests\Support\TestUser;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

function pluginFixture(array $attributes = []): Plugin
{
    $type = PluginType::firstOrCreate(['slug' => 'tools'], ['name' => 'Tools']);

    return Plugin::create($attributes + ['name' => 'Widget', 'slug' => 'widget', 'plugin_type_id' => $type->id]);
}

function releaseFixture(Plugin $plugin, array $attributes = []): PluginRelease
{
    return PluginRelease::create($attributes + ['plugin_id' => $plugin->id, 'version' => '1.0.0', 'zip_path' => 'plugins/widget/1.0.0.zip'])->refresh();
}

it('lists its models in the configuration by default', function () {
    expect(array_keys(config('filagora-plugins.models')))->toBe(['type', 'plugin', 'release', 'report', 'subscription', 'moderator_note'])
        ->and(Models::class('release'))->toBe(PluginRelease::class);

    config(['filagora.models.release' => 'Custom\\Release']);
    expect(Models::class('release'))->toBe('Custom\\Release');
    expect(fn () => Models::class('nope'))->toThrow(InvalidArgumentException::class);
});

it('starts a plugin and a release moderated, and a report open', function () {
    $plugin = pluginFixture()->refresh();
    $release = releaseFixture($plugin);
    $report = PluginReport::create(['plugin_id' => $plugin->id, 'reporter_id' => TestUser::make('Rita')->id, 'reason' => 'Broken'])->refresh();

    expect($plugin->status)->toBe(PluginStatus::MODERATED)
        ->and($release->status)->toBe(PluginStatus::MODERATED)
        ->and($release->download_count)->toBe(0)
        ->and($report->status)->toBe(PluginReportStatus::OPEN);
});

it('numbers a plugin\'s releases one after the other, each plugin on its own', function () {
    $first = pluginFixture();
    $second = pluginFixture(['slug' => 'gadget', 'name' => 'Gadget']);

    expect(releaseFixture($first)->internal_version)->toBe(1)
        ->and(releaseFixture($first, ['version' => '1.1.0'])->internal_version)->toBe(2)
        ->and(releaseFixture($second)->internal_version)->toBe(1);
});

it('records who uploaded a release when someone is signed in', function () {
    $uploader = TestUser::make('Uma');
    $plugin = pluginFixture();

    Auth::login($uploader);

    expect(releaseFixture($plugin)->uploaded_by_id)->toBe($uploader->id);
});

it('refuses a second release with the same version of the same plugin', function () {
    $plugin = pluginFixture();
    releaseFixture($plugin);

    expect(fn () => releaseFixture($plugin))->toThrow(QueryException::class);
    expect(releaseFixture(pluginFixture(['slug' => 'other', 'name' => 'Other']))->version)->toBe('1.0.0');
});

it('stamps approved_at when a release becomes active, and clears it when it is rejected', function () {
    $release = releaseFixture(pluginFixture());

    expect($release->approved_at)->toBeNull();

    $release->update(['status' => PluginStatus::ACTIVE]);
    expect($release->approved_at)->not->toBeNull();

    $release->update(['status' => PluginStatus::REJECTED_FINAL]);
    expect($release->approved_at)->toBeNull();
});

it('keeps approved_at through a quarantine or an archive, so lifting the hold restores it', function () {
    $release = releaseFixture(pluginFixture(), ['status' => PluginStatus::ACTIVE]);
    $approvedAt = $release->approved_at;

    $release->update(['status' => PluginStatus::QUARANTINED, 'quarantined_at' => now()]);
    expect($release->approved_at?->equalTo($approvedAt))->toBeTrue()
        ->and($release->isQuarantined())->toBeTrue();

    $release->update(['status' => PluginStatus::ARCHIVED, 'status_before_archive' => 'active', 'archived_at' => now()]);
    expect($release->approved_at?->equalTo($approvedAt))->toBeTrue()
        ->and($release->isArchived())->toBeTrue();
});

it('clears the archive bookkeeping when a release leaves the archive', function () {
    $archiver = TestUser::make('Ann');
    $release = releaseFixture(pluginFixture(), ['status' => PluginStatus::ARCHIVED, 'status_before_archive' => 'active', 'archived_at' => now(), 'archived_by_id' => $archiver->id]);

    $release->update(['status' => PluginStatus::ACTIVE]);

    expect($release->fresh()->status_before_archive)->toBeNull()
        ->and($release->fresh()->archived_at)->toBeNull()
        ->and($release->fresh()->archived_by_id)->toBeNull();
});

it('fills the size, checksum and filename from the stored file', function () {
    Storage::fake('local');
    Storage::disk('local')->put('plugins/widget/1.0.0.zip', 'zip-bytes');

    $release = releaseFixture(pluginFixture());

    expect($release->zip_size)->toBe(9)
        ->and($release->checksum_sha256)->toBe(hash('sha256', 'zip-bytes'))
        ->and($release->zip_filename)->toBe('1.0.0.zip');
});

it('reads the file from the configured disk', function () {
    config(['filagora-plugins.storage.disk' => 'public']);
    Storage::fake('public');
    Storage::disk('public')->put('plugins/widget/1.0.0.zip', 'abc');

    expect(releaseFixture(pluginFixture())->zip_size)->toBe(3);
});

it('leaves the size alone when there is no file', function () {
    Storage::fake('local');

    expect(releaseFixture(pluginFixture())->zip_size)->toBeNull();
});

it('finds the latest active release, ignoring newer ones that are not', function () {
    $plugin = pluginFixture();
    releaseFixture($plugin, ['version' => '1.0.0', 'status' => PluginStatus::ACTIVE]);
    $current = releaseFixture($plugin, ['version' => '1.1.0', 'status' => PluginStatus::ACTIVE]);
    releaseFixture($plugin, ['version' => '2.0.0', 'status' => PluginStatus::MODERATED]);

    expect($plugin->latestActiveRelease->is($current))->toBeTrue()
        ->and($plugin->releases)->toHaveCount(3);
});

it('prints a version without its leading v', function () {
    expect(releaseFixture(pluginFixture(), ['version' => 'v2.1.2'])->displayVersion())->toBe('2.1.2');
});

it('turns a short GitHub name into a link and leaves full links alone', function () {
    expect(pluginFixture(['github_repository_url' => 'filagora/filagora-plugins'])->github_repository_url)->toBe('https://github.com/filagora/filagora-plugins')
        ->and(pluginFixture(['slug' => 'b', 'github_repository_url' => 'git@github.com:filagora/x.git'])->github_repository_url)->toBe('https://github.com/filagora/x.git')
        ->and(pluginFixture(['slug' => 'c', 'github_repository_url' => 'https://example.test/x'])->github_repository_url)->toBe('https://example.test/x')
        ->and(pluginFixture(['slug' => 'd', 'github_repository_url' => '   '])->github_repository_url)->toBeNull();
});

it('uses the row key as the plugin\'s public number', function () {
    $plugin = pluginFixture();

    expect($plugin->canonicalId())->toBe($plugin->id);
});

it('deletes a plugin\'s releases, reports, notes and subscriptions with it, and keeps the people', function () {
    $owner = TestUser::make('Olive');
    $plugin = pluginFixture(['owner_id' => $owner->id]);
    $release = releaseFixture($plugin);
    PluginReport::create(['plugin_id' => $plugin->id, 'plugin_release_id' => $release->id, 'reporter_id' => $owner->id, 'reason' => 'x']);
    PluginModeratorNote::create(['plugin_id' => $plugin->id, 'moderator_id' => $owner->id, 'note' => 'n']);
    PluginSubscription::create(['plugin_id' => $plugin->id, 'user_id' => $owner->id]);

    $plugin->delete();

    expect(PluginRelease::count() + PluginReport::count() + PluginModeratorNote::count() + PluginSubscription::count())->toBe(0)
        ->and(TestUser::find($owner->id))->not->toBeNull();
});

it('keeps a plugin and a note when their person is deleted, and drops the person\'s subscription and reports', function () {
    $owner = TestUser::make('Olive');
    $plugin = pluginFixture(['owner_id' => $owner->id]);
    $release = releaseFixture($plugin, ['uploaded_by_id' => $owner->id]);
    PluginModeratorNote::create(['plugin_id' => $plugin->id, 'moderator_id' => $owner->id, 'note' => 'n']);
    PluginSubscription::create(['plugin_id' => $plugin->id, 'user_id' => $owner->id]);
    PluginReport::create(['plugin_id' => $plugin->id, 'reporter_id' => $owner->id, 'reason' => 'x']);

    $owner->delete();

    expect($plugin->fresh()->owner_id)->toBeNull()
        ->and($release->fresh()->uploaded_by_id)->toBeNull()
        ->and(PluginModeratorNote::first()->moderator_id)->toBeNull()
        ->and(PluginSubscription::count())->toBe(0)
        ->and(PluginReport::count())->toBe(0);
});

it('refuses a person subscribing to the same plugin twice', function () {
    $user = TestUser::make('Sam');
    $plugin = pluginFixture();
    PluginSubscription::create(['plugin_id' => $plugin->id, 'user_id' => $user->id]);

    expect(fn () => PluginSubscription::create(['plugin_id' => $plugin->id, 'user_id' => $user->id]))->toThrow(QueryException::class)
        ->and($plugin->subscribedUsers)->toHaveCount(1);
});

it('keeps a report when its release is deleted', function () {
    $plugin = pluginFixture();
    $release = releaseFixture($plugin);
    $report = PluginReport::create(['plugin_id' => $plugin->id, 'plugin_release_id' => $release->id, 'reporter_id' => TestUser::make('Rita')->id, 'reason' => 'x']);

    $release->delete();

    expect($report->fresh()->plugin_release_id)->toBeNull();
});

it('groups plugins under a type and orders statuses for the screens', function () {
    $plugin = pluginFixture();

    expect($plugin->type->plugins)->toHaveCount(1)
        ->and(array_keys(PluginStatus::options()))->toBe(['active', 'moderated', 'rejected_final', 'quarantined', 'archived'])
        ->and(array_keys(PluginStatus::directlySettableOptions()))->not->toContain('archived')
        ->and(PluginStatus::QUARANTINED->color())->toBe('danger');
});
