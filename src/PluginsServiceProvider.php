<?php

declare(strict_types=1);

namespace Filagora\Plugins;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaExtension;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Support\GateAbilities;
use Filagora\Plugins\Contracts\ArchiveInspector;
use Filagora\Plugins\Contracts\SubmissionPolicy;
use Filagora\Plugins\Contracts\SubscriberPreferences;
use Filagora\Plugins\Events\PluginModerated;
use Filagora\Plugins\Events\PluginReported;
use Filagora\Plugins\Events\PluginReportReviewed;
use Filagora\Plugins\Events\ReleasePublished;
use Filagora\Plugins\Events\ReleaseRejected;
use Filagora\Plugins\Events\ReleaseSubmitted;
use Filagora\Plugins\Events\SubscribersToNotify;
use Filagora\Plugins\Listeners\NotifySubscribers;
use Filagora\Plugins\Listeners\SendPluginMail;
use Filagora\Plugins\Release\AccessCheckerSubmissionPolicy;
use Filagora\Plugins\Release\ReleaseExtensions;
use Filagora\Plugins\Release\WantsEveryUpdate;
use Filagora\Plugins\Release\ZipArchiveInspector;
use Filagora\Plugins\Services\Moderation;
use Filagora\Plugins\Services\Releases;
use Filagora\Plugins\Services\Reports;
use Filagora\Plugins\Services\Subscriptions;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class PluginsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/filagora-plugins.php', 'filagora-plugins');

        $this->app->bindIf(SubmissionPolicy::class, fn ($app): SubmissionPolicy => new AccessCheckerSubmissionPolicy($app->make(AccessChecker::class)));
        $this->app->bindIf(ArchiveInspector::class, ZipArchiveInspector::class);
        $this->app->bindIf(SubscriberPreferences::class, WantsEveryUpdate::class);
        $this->app->singleton(ReleaseExtensions::class);
        $this->app->singleton(Releases::class);
        $this->app->singleton(Moderation::class);
        $this->app->singleton(Reports::class);
        $this->app->singleton(Subscriptions::class);
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

        GateAbilities::define('filagora-plugins.gates');

        Event::listen(ReleaseSubmitted::class, [SendPluginMail::class, 'submitted']);
        Event::listen(ReleasePublished::class, [SendPluginMail::class, 'published']);
        Event::listen(ReleasePublished::class, NotifySubscribers::class);
        Event::listen(ReleaseRejected::class, [SendPluginMail::class, 'rejected']);
        Event::listen(PluginModerated::class, [SendPluginMail::class, 'moderated']);
        Event::listen(SubscribersToNotify::class, [SendPluginMail::class, 'subscribers']);
        Event::listen(PluginReported::class, [SendPluginMail::class, 'reported']);
        Event::listen(PluginReportReviewed::class, [SendPluginMail::class, 'reviewed']);

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filagora-plugins');
        $this->loadMigrationsFrom([__DIR__.'/../database/migrations', __DIR__.'/../database/migrations/forums']);

        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../config/filagora-plugins.php' => config_path('filagora-plugins.php'),
            ], 'filagora-plugins-config');
        }
    }
}
