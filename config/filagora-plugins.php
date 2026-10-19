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
    | Submissions and archives
    |--------------------------------------------------------------------------
    |
    | `submissions_enabled` is a kill switch: false refuses every new plugin and release. An
    | uploaded archive is refused when it holds more than `archive.max_entries` files or would
    | expand past `archive.max_uncompressed_bytes`, or holds a path that escapes its folder.
    |
    */
    'submissions_enabled' => (bool) env('FILAGORA_PLUGINS_SUBMISSIONS_ENABLED', true),

    'archive' => [
        'max_entries' => (int) env('FILAGORA_PLUGINS_ARCHIVE_MAX_ENTRIES', 5000),
        'max_uncompressed_bytes' => (int) env('FILAGORA_PLUGINS_ARCHIVE_MAX_UNCOMPRESSED_BYTES', 200 * 1024 * 1024),
    ],

    /*
    |--------------------------------------------------------------------------
    | Archiving
    |--------------------------------------------------------------------------
    |
    | `filagora:plugins:archive-releases` moves historical releases to "archived": still listed and
    | (see `downloads`) downloadable, but out of the security sweep. A release stays active when it is
    | a plugin's newest, younger than `keep_months`, or the newest for a version line of what the plugin
    | still works with (`keep_per_target_line`). A plugin moderated and untouched for
    | `stale_moderated_months`, or with no owner and no release newer than `ownerless_months`, is dead
    | weight and keeps only its newest release.
    |
    */
    'archiving' => [
        'keep_months' => (int) env('FILAGORA_PLUGINS_ARCHIVE_KEEP_MONTHS', 18),
        'keep_per_target_line' => (bool) env('FILAGORA_PLUGINS_ARCHIVE_KEEP_PER_LINE', true),
        'stale_moderated_months' => 12,
        'ownerless_months' => 24,
    ],

    /*
    |--------------------------------------------------------------------------
    | Downloads
    |--------------------------------------------------------------------------
    |
    | `archived_releases`: whether a release archived out of the security sweep can still be
    | downloaded (it stays listed either way).
    |
    */
    'downloads' => ['archived_releases' => true],

    /*
    |--------------------------------------------------------------------------
    | Search
    |--------------------------------------------------------------------------
    |
    | With `filagora-search` installed, releases are searchable. `candidates` is the most matching
    | releases the engine is asked for before they are collapsed to plugins and ranked.
    |
    */
    'search' => ['candidates' => 300],

    /*
    |--------------------------------------------------------------------------
    | Abilities
    |--------------------------------------------------------------------------
    |
    | Each ability the package defines, mapped to the key asked of the access checker
    | (`filagora-entitlements` answers from grants). `moderate` reviews plugins, releases and
    | reports and publishes without review; `auto_publish` publishes without review only.
    |
    */
    'gates' => [
        'filagora.plugins.moderate' => 'filagora.plugins.moderate',
        'filagora.plugins.auto_publish' => 'filagora.plugins.auto_publish',
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail
    |--------------------------------------------------------------------------
    |
    | `enabled` switches the default emails off (listen to the package's events instead).
    | `support_email` is told of new submissions and reports; leave it empty to tell no one.
    | `acknowledge_submissions` emails the author a receipt; turn it off when something else, such
    | as a security scan, writes to them when it is done. `routes.plugin` is the named route of
    | your plugin page (it receives `plugin`), used for the links in the mail.
    |
    */
    'mail' => [
        'enabled' => true,
        'support_email' => env('FILAGORA_PLUGINS_SUPPORT_EMAIL'),
        'acknowledge_submissions' => true,
    ],

    'routes' => ['plugin' => null],

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
