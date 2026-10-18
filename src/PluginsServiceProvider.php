<?php

declare(strict_types=1);

namespace Filagora\Plugins;

use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaExtension;
use Filagora\Core\Schema\SchemaRegistry;
use Illuminate\Support\ServiceProvider;

class PluginsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-plugins.php', 'filagora-plugins');
    }

    public function boot(): void
    {
        $this->app->make(ModuleRegistry::class)->register(
            new Module('plugins', 'Filagora Plugins', 'filagora/filagora-plugins', requires: ['core']),
        );

        $this->app->make(SchemaRegistry::class)->register(
            PackageSchema::fromFile('plugins', __DIR__.'/../database/schema.php', __DIR__.'/../database/migrations'),
        );

        // Once every provider has booted, so it does not matter which of the two packages is first.
        $this->app->booted(function (): void {
            if ($this->app->make(ModuleRegistry::class)->has('forums')) {
                $this->app->make(SchemaRegistry::class)->extend(SchemaExtension::fromFile(
                    'plugins',
                    'plugins',
                    __DIR__.'/../database/extensions/forums.php',
                    __DIR__.'/../database/migrations/forums',
                ));
            }
        });

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filagora-plugins');
        $this->loadMigrationsFrom([__DIR__.'/../database/migrations', __DIR__.'/../database/migrations/forums']);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filagora-plugins.php' => config_path('filagora-plugins.php'),
            ], 'filagora-plugins-config');
        }
    }
}
