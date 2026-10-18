<?php

declare(strict_types=1);

namespace Filagora\Plugins\Services;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Enums\ModerationRefusal;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Exceptions\ModerationRefused;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Support\Gates;
use Filagora\Plugins\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * The one place that decides who sees which plugins and releases. There are three audiences:
 *
 * - **public**: active plugins of active types the viewer may see, and their active releases. A type
 *   that requires an entitlement scope is seen only by someone the access checker allows it.
 * - **owner**: the public view, plus the viewer's own plugins in any status with all their releases.
 * - **staff**: everything in an active type; for someone who may moderate.
 *
 * Every query, page, API response and search result should come through here.
 */
class Visibility
{
    public function __construct(
        private readonly AccessChecker $access,
        private readonly Compatibility $compatibility,
    ) {}

    /**
     * The ids of the active types the viewer may see. There are few types, so the access checker is
     * asked once per type with a scope, and queries then use a plain `whereIn`.
     *
     * @return list<int>
     */
    public function visibleTypeIds(?Authenticatable $viewer): array
    {
        return Models::class('type')::query()
            ->where('is_active', true)
            ->get()
            ->filter(function (PluginType $type) use ($viewer): bool {
                $scope = trim((string) $type->required_entitlement_scope);

                return $scope === '' || $this->access->allows($viewer, $scope);
            })
            ->map(static fn (PluginType $type): int => (int) $type->getKey())
            ->values()
            ->all();
    }

    /**
     * The active types the viewer may see, with a count of their active plugins.
     *
     * @return Builder<PluginType>
     */
    public function types(?Authenticatable $viewer): Builder
    {
        return Models::class('type')::query()
            ->whereIn('id', $this->visibleTypeIds($viewer) ?: [0])
            ->withCount(['plugins as active_plugins_count' => fn (Builder $plugins) => $plugins->where('status', PluginStatus::ACTIVE->value)])
            ->orderBy('position')
            ->orderBy('name');
    }

    /**
     * @return Builder<Plugin>
     */
    public function publicPlugins(?Authenticatable $viewer = null, ?string $typeSlug = null, string $search = ''): Builder
    {
        return $this->plugins($viewer, $typeSlug, $search, includeOwned: false);
    }

    /**
     * @return Builder<Plugin>
     */
    public function ownerPlugins(Authenticatable $owner, ?string $typeSlug = null, string $search = ''): Builder
    {
        return $this->plugins($owner, $typeSlug, $search, includeOwned: true);
    }

    /**
     * @return Builder<Plugin>
     *
     * @throws ModerationRefused
     */
    public function staffPlugins(Authenticatable $staff, ?string $typeSlug = null, string $search = ''): Builder
    {
        $this->assertStaff($staff);

        $query = $this->base($typeSlug, $search)
            ->with(['releases' => fn ($releases) => $releases->with($this->compatibility->relations())->orderByDesc('created_at')]);

        return $this->order($query);
    }

    public function findPublicPlugin(string $slug, ?Authenticatable $viewer = null): ?Plugin
    {
        return $this->publicPlugins($viewer)->where('slug', $slug)->first();
    }

    public function findOwnerPlugin(string $slug, Authenticatable $owner): ?Plugin
    {
        return $this->ownerPlugins($owner)->where('slug', $slug)->first();
    }

    public function findStaffPlugin(string $slug, Authenticatable $staff): ?Plugin
    {
        return $this->staffPlugins($staff)->where('slug', $slug)->first();
    }

    /**
     * @return Builder<PluginRelease>
     */
    public function publicReleases(Plugin $plugin): Builder
    {
        return $this->releases($plugin, allStatuses: false);
    }

    /**
     * All of a plugin's releases for its owner; the public ones for anyone else.
     *
     * @return Builder<PluginRelease>
     */
    public function ownerReleases(Plugin $plugin, Authenticatable $viewer): Builder
    {
        return $this->releases($plugin, allStatuses: $this->owns($viewer, $plugin));
    }

    /**
     * @return Builder<PluginRelease>
     *
     * @throws ModerationRefused
     */
    public function staffReleases(Plugin $plugin, Authenticatable $staff): Builder
    {
        $this->assertStaff($staff);

        return $this->releases($plugin, allStatuses: true);
    }

    public function findPublicRelease(Plugin $plugin, string $version): ?PluginRelease
    {
        return $this->publicReleases($plugin)->where('version', $version)->first();
    }

    public function findOwnerRelease(Plugin $plugin, Authenticatable $viewer, string $version): ?PluginRelease
    {
        return $this->ownerReleases($plugin, $viewer)->where('version', $version)->first();
    }

    public function findStaffRelease(Plugin $plugin, Authenticatable $staff, string $version): ?PluginRelease
    {
        return $this->staffReleases($plugin, $staff)->where('version', $version)->first();
    }

    /**
     * @return Builder<Plugin>
     */
    private function plugins(?Authenticatable $viewer, ?string $typeSlug, string $search, bool $includeOwned): Builder
    {
        $owned = $includeOwned && $viewer !== null;
        $typeIds = $this->visibleTypeIds($viewer) ?: [0];

        $query = $this->base($typeSlug, $search)
            ->where(function (Builder $visible) use ($typeIds, $owned, $viewer): void {
                $visible->where(fn (Builder $public) => $public->where('status', PluginStatus::ACTIVE->value)->whereIn('plugin_type_id', $typeIds));

                if ($owned) {
                    $visible->orWhere('owner_id', $viewer->getAuthIdentifier());
                }
            })
            ->with(['releases' => function ($releases) use ($owned, $viewer): void {
                $releases
                    ->when(
                        $owned,
                        fn (Builder $query) => $query->where(fn (Builder $inner) => $inner
                            ->where('status', PluginStatus::ACTIVE->value)
                            ->orWhereHas('plugin', fn (Builder $plugin) => $plugin->where('owner_id', $viewer->getAuthIdentifier()))),
                        fn (Builder $query) => $query->where('status', PluginStatus::ACTIVE->value),
                    )
                    ->with($this->compatibility->relations())
                    ->orderByDesc('created_at');
            }]);

        return $this->order($query);
    }

    /**
     * Plugins in an active type (of the given slug, if any) matching the search words.
     *
     * @return Builder<Plugin>
     */
    private function base(?string $typeSlug, string $search): Builder
    {
        $query = Models::class('plugin')::query()
            ->whereHas('type', function (Builder $type) use ($typeSlug): void {
                $type->where('is_active', true);

                if ($typeSlug !== null && $typeSlug !== '') {
                    $type->where('slug', $typeSlug);
                }
            })
            ->with(['type', 'owner'])
            ->withCount(['releases as active_releases_count' => fn (Builder $releases) => $releases->where('status', PluginStatus::ACTIVE->value)]);

        if ($search !== '') {
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(fn (Builder $match) => $match
                ->where('name', 'like', $like)
                ->orWhere('slug', 'like', $like)
                ->orWhere('description', 'like', $like));
        }

        return $query;
    }

    /**
     * Newest first. The id breaks a tie between plugins touched in the same second, which a
     * database would otherwise order however it likes.
     *
     * @param  Builder<Plugin>  $query
     * @return Builder<Plugin>
     */
    private function order(Builder $query): Builder
    {
        return $query->orderByDesc('updated_at')->orderByDesc('created_at')->orderByDesc('id');
    }

    /**
     * @return Builder<PluginRelease>
     */
    private function releases(Plugin $plugin, bool $allStatuses): Builder
    {
        return Models::class('release')::query()
            ->where('plugin_id', $plugin->getKey())
            ->when(! $allStatuses, fn (Builder $query) => $query->where('status', PluginStatus::ACTIVE->value))
            ->with(['plugin.type', ...$this->compatibility->relations()])
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    private function owns(Authenticatable $viewer, Plugin $plugin): bool
    {
        return $plugin->owner_id !== null && $plugin->owner_id === $viewer->getAuthIdentifier();
    }

    private function assertStaff(Authenticatable $staff): void
    {
        if (! $this->access->allows($staff, Gates::key(Gates::MODERATE))) {
            throw new ModerationRefused(ModerationRefusal::NOT_PERMITTED);
        }
    }
}
