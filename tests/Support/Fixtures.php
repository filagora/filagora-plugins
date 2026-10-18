<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Release\ReleaseInput;
use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;
use Filagora\Plugins\Tests\Support\Zips;

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
