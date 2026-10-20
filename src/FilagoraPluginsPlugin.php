<?php

declare(strict_types=1);

namespace Filagora\Plugins;

use Filagora\Plugins\Filament\Resources\PluginReleases\PluginReleaseResource;
use Filagora\Plugins\Filament\Resources\PluginReports\PluginReportResource;
use Filagora\Plugins\Filament\Resources\Plugins\PluginResource;
use Filagora\Plugins\Filament\Resources\PluginTypes\PluginTypeResource;
use Filament\Contracts\Plugin;
use Filament\Panel;

class FilagoraPluginsPlugin implements Plugin
{
    public static function make(): static
    {
        return app(static::class);
    }

    public function getId(): string
    {
        return 'filagora-plugins';
    }

    public function register(Panel $panel): void
    {
        $panel->resources([
            PluginResource::class,
            PluginReleaseResource::class,
            PluginReportResource::class,
            PluginTypeResource::class,
        ]);
    }

    public function boot(Panel $panel): void {}
}
