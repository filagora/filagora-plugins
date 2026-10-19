<?php

declare(strict_types=1);

use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Release\ReleaseInput;
use Filagora\Plugins\Search\PluginRanking;
use Filagora\Plugins\Search\PluginSearch;
use Filagora\Plugins\Search\ReleaseDatabaseSearch;
use Filagora\Plugins\Search\ReleaseSearchIndex;
use Filagora\Plugins\Services\ReleaseSelection;
use Filagora\Plugins\Services\Visibility;
use Filagora\Plugins\Tests\Support\FakeCompatibility;
use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;
use Filagora\Plugins\Tests\Support\Zips;
use Filagora\Search\Support\SearchIndexRegistry;

function pluginType(): PluginType
{
    return PluginType::firstOrCreate(['slug' => 'tools'], ['name' => 'Tools']);
}

function moderator(string $name = 'Mo'): TestUser
{
    $user = TestUser::make($name);
    app(GrantedKeys::class)->give($user, 'filagora.plugins.moderate');

    return $user;
}

function releaseInput(string $version = '1.0.0', array $extra = [], bool $supersede = false): ReleaseInput
{
    return new ReleaseInput($version, Zips::make(), 'Changes', $supersede, $extra);
}

/**
 * A plugin with one release, both in the given status.
 */
function livePlugin(?TestUser $owner = null, PluginStatus $status = PluginStatus::ACTIVE, string $slug = 'widget'): Plugin
{
    $plugin = Plugin::create([
        'name' => ucfirst($slug),
        'slug' => $slug,
        'plugin_type_id' => pluginType()->id,
        'owner_id' => $owner?->id,
        'status' => $status,
    ]);
    PluginRelease::create(['plugin_id' => $plugin->id, 'uploaded_by_id' => $owner?->id, 'version' => 'v1.0.0', 'zip_path' => "plugins/{$slug}.zip", 'status' => $status]);

    return $plugin->refresh();
}

/**
 * Bind an in-memory compatibility list and forget the services that took the default.
 */
function fakeCompatibility(): FakeCompatibility
{
    $fake = new FakeCompatibility;
    app()->instance(Compatibility::class, $fake);

    foreach ([ReleaseSelection::class, Visibility::class, PluginRanking::class, ReleaseSearchIndex::class, ReleaseDatabaseSearch::class, PluginSearch::class] as $service) {
        app()->forgetInstance($service);
    }

    // The search registry still holds the index built with the old list.
    app(SearchIndexRegistry::class)->register(app(ReleaseSearchIndex::class));

    return $fake;
}

/**
 * A release of a plugin, active unless said otherwise.
 */
function addRelease(Plugin $plugin, string $version, PluginStatus $status = PluginStatus::ACTIVE, array $attributes = []): PluginRelease
{
    return PluginRelease::create($attributes + ['plugin_id' => $plugin->id, 'version' => $version, 'zip_path' => "plugins/{$plugin->slug}-{$version}.zip", 'status' => $status])->refresh();
}

/**
 * A plugin with active releases uploaded the given number of months ago (newest last in the list).
 *
 * @param  array<string, int>  $versionsToAge  version => months ago
 * @param  array<string, mixed>  $plugin
 */
function archivable(string $slug, array $versionsToAge, array $plugin = []): Plugin
{
    $touched = $plugin['updated_at'] ?? null;
    unset($plugin['updated_at']);

    $model = Plugin::create($plugin + [
        'name' => ucfirst($slug),
        'slug' => $slug,
        'plugin_type_id' => pluginType()->id,
        'status' => PluginStatus::ACTIVE,
        'owner_id' => TestUser::make('Owner'.$slug)->id,
    ]);

    foreach ($versionsToAge as $version => $monthsAgo) {
        $release = addRelease($model, (string) $version);
        PluginRelease::query()->whereKey($release->id)->update(['created_at' => now()->subMonths($monthsAgo)]);
    }

    if ($touched !== null) {
        Plugin::query()->whereKey($model->id)->update(['updated_at' => $touched]);
    }

    return $model->refresh();
}

/**
 * The versions of a plugin's releases in a status.
 *
 * @return list<string>
 */
function versionsWhere(Plugin $plugin, PluginStatus $status): array
{
    return PluginRelease::query()->where('plugin_id', $plugin->id)->where('status', $status->value)->orderBy('id')->pluck('version')->all();
}
