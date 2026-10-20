<?php

declare(strict_types=1);

namespace Filagora\Plugins;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Core\Modules\Module;
use Filagora\Core\Modules\ModuleRegistry;
use Filagora\Core\Navigation\NavigationGroupDefinition;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Core\Privacy\PrivacyContributorRegistry;
use Filagora\Core\Schema\PackageSchema;
use Filagora\Core\Schema\SchemaExtension;
use Filagora\Core\Schema\SchemaRegistry;
use Filagora\Core\Support\GateAbilities;
use Filagora\Entitlements\Registry\Definition;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Filagora\Plugins\Archiving\ArchiveGuards;
use Filagora\Plugins\Archiving\Archiving;
use Filagora\Plugins\Console\ArchiveReleasesCommand;
use Filagora\Plugins\Contracts\ArchiveInspector;
use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Contracts\DownloadAccess;
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
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Privacy\PluginsDataContributor;
use Filagora\Plugins\Release\AccessCheckerSubmissionPolicy;
use Filagora\Plugins\Release\NoCompatibility;
use Filagora\Plugins\Release\ReleaseExtensions;
use Filagora\Plugins\Release\ScopeDownloadAccess;
use Filagora\Plugins\Release\WantsEveryUpdate;
use Filagora\Plugins\Release\ZipArchiveInspector;
use Filagora\Plugins\Search\PluginRanking;
use Filagora\Plugins\Search\PluginSearch;
use Filagora\Plugins\Search\ReleaseSearchIndex;
use Filagora\Plugins\Search\SyncReleaseDocuments;
use Filagora\Plugins\Services\Moderation;
use Filagora\Plugins\Services\Releases;
use Filagora\Plugins\Services\ReleaseSelection;
use Filagora\Plugins\Services\Reports;
use Filagora\Plugins\Services\Subscriptions;
use Filagora\Plugins\Services\Visibility;
use Filagora\Plugins\Support\PluginTypeEntitlementKeys;
use Filagora\Search\Support\SearchIndexRegistry;
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
        $this->app->bindIf(Compatibility::class, NoCompatibility::class);
        $this->app->bindIf(DownloadAccess::class, fn ($app): DownloadAccess => new ScopeDownloadAccess($app->make(AccessChecker::class)));
        $this->app->singleton(ReleaseSelection::class);
        $this->app->singleton(Visibility::class);
        $this->app->singleton(PluginRanking::class);
        $this->app->singleton(ReleaseSearchIndex::class);
        $this->app->singleton(ReleaseExtensions::class);
        $this->app->singleton(ArchiveGuards::class);
        $this->app->singleton(Archiving::class);
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

        $this->app->make(PrivacyContributorRegistry::class)->register(new PluginsDataContributor);
        $this->registerNavigationGroup();
        $this->declareEntitlements();
        $this->registerSearch();

        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'filagora-plugins');
        $this->loadMigrationsFrom([__DIR__.'/../database/migrations', __DIR__.'/../database/migrations/forums']);

        if ($this->app->runningInConsole()) {
            $this->commands([ArchiveReleasesCommand::class]);

            $this->publishes([
                __DIR__.'/../config/filagora-plugins.php' => config_path('filagora-plugins.php'),
            ], 'filagora-plugins-config');
        }
    }

    /**
     * With `filagora-search` installed, releases become a search index, and plugins and types keep
     * the release documents they feed right. Without it there is no plugin search to set up.
     */
    private function registerSearch(): void
    {
        if (! class_exists(SearchIndexRegistry::class) || ! $this->app->bound(SearchIndexRegistry::class)) {
            return;
        }

        $this->app->make(SearchIndexRegistry::class)->register($this->app->make(ReleaseSearchIndex::class));
        $this->app->singleton(PluginSearch::class);

        Event::listen('eloquent.saved: *', function (string $event, array $payload): void {
            $model = $payload[0] ?? null;

            if ($model instanceof Plugin && $model->wasChanged(['name', 'slug', 'description', 'status', 'plugin_type_id'])) {
                SyncReleaseDocuments::dispatch('plugin', (int) $model->getKey());
            }

            if ($model instanceof PluginType && $model->wasChanged('is_active')) {
                SyncReleaseDocuments::dispatch('type', (int) $model->getKey());
            }
        });
    }

    private function registerNavigationGroup(): void
    {
        $this->app->make(NavigationGroupRegistry::class)->register(new NavigationGroupDefinition(
            key: 'plugins',
            label: fn (): string => __('filagora-plugins::messages.navigation_groups.plugins'),
            icon: 'heroicon-o-puzzle-piece',
            sort: 30,
        ));
    }

    /**
     * With `filagora-entitlements` installed: the keys behind the abilities, and the scopes set on
     * plugin types, are declared so `filagora:entitlements:sync` creates their definitions. Without
     * it there is nothing to declare them to.
     */
    private function declareEntitlements(): void
    {
        if (! class_exists(EntitlementRegistry::class) || ! $this->app->bound(EntitlementRegistry::class)) {
            return;
        }

        $registry = $this->app->make(EntitlementRegistry::class);

        foreach (GateAbilities::keys('filagora-plugins.gates') as $entitlement) {
            $registry->define(new Definition($entitlement, wildcardChildren: false));
        }

        $registry->keySource(new PluginTypeEntitlementKeys);
    }
}
