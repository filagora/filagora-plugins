<?php

declare(strict_types=1);

namespace Filagora\Plugins;

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

    public function register(Panel $panel): void {}

    public function boot(Panel $panel): void {}
}
