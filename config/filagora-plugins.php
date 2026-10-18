<?php

declare(strict_types=1);

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginModeratorNote;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginReport;
use Filagora\Plugins\Models\PluginSubscription;
use Filagora\Plugins\Models\PluginType;

return [

    /*
    |--------------------------------------------------------------------------
    | Swappable models
    |--------------------------------------------------------------------------
    |
    | The classes this package uses for its tables. Point a key at your own subclass to
    | replace one (here, or under `filagora.models.<key>`, which wins). Subclass the package
    | model; the package always asks for the class through its Models helper.
    |
    */
    'models' => [
        'type' => PluginType::class,
        'plugin' => Plugin::class,
        'release' => PluginRelease::class,
        'report' => PluginReport::class,
        'subscription' => PluginSubscription::class,
        'moderator_note' => PluginModeratorNote::class,
    ],

    /*
    |--------------------------------------------------------------------------
    | Release storage
    |--------------------------------------------------------------------------
    |
    | The filesystem disk release archives are stored on. A release's `zip_path` is relative
    | to it. When a release is saved and its file exists, its size and SHA-256 checksum are
    | filled in from the file.
    |
    */
    'storage' => [
        'disk' => env('FILAGORA_PLUGINS_DISK', 'local'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Navigation group
    |--------------------------------------------------------------------------
    |
    | The Filament navigation group this package's resources are placed in.
    |
    */
    'navigation_group' => env('FILAGORA_PLUGINS_NAVIGATION_GROUP', 'Plugins'),

];
