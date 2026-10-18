<?php

declare(strict_types=1);

namespace Filagora\Plugins\Search;

use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Services\Visibility;
use Filagora\Plugins\Support\Models;
use Filagora\Search\Contracts\Filter;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Search;
use Filagora\Search\Support\SearchQuery;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

/**
 * Finds plugins. The engine finds the releases that match (so a version or a changelog line can
 * match), the releases are collapsed to their plugins with every active release loaded, anything
 * the viewer may not see is dropped, and the plugins are ranked and paged.
 */
class PluginSearch
{
    public function __construct(
        private readonly Search $search,
        private readonly Visibility $visibility,
        private readonly PluginRanking $ranking,
        private readonly Compatibility $compatibility,
    ) {}

    /**
     * @param  string  $target  Only plugins with a release that works with this (see `Compatibility`).
     * @return LengthAwarePaginator<int, Plugin>
     */
    public function plugins(string $text, ?Authenticatable $viewer = null, ?int $typeId = null, string $target = '', int $page = 1, int $perPage = 20, ?Filter $extra = null): LengthAwarePaginator
    {
        $ranked = $this->ranked($text, $viewer, $typeId, $target, $extra);

        return new LengthAwarePaginator(
            $ranked->forPage($page, $perPage)->values(),
            $ranked->count(),
            $perPage,
            $page,
            ['path' => LengthAwarePaginator::resolveCurrentPath()],
        );
    }

    /**
     * Every matching plugin the viewer may see, best match first.
     *
     * @return Collection<int, Plugin>
     */
    public function ranked(string $text, ?Authenticatable $viewer = null, ?int $typeId = null, string $target = '', ?Filter $extra = null): Collection
    {
        $filters = [
            new Equals('status', PluginStatus::ACTIVE->value),
            new Equals('plugin_status', PluginStatus::ACTIVE->value),
            new Equals('type_is_active', true),
        ];

        if ($typeId !== null) {
            $filters[] = new Equals('plugin_type_id', $typeId);
        }

        if ($target !== '') {
            $filters[] = new Equals('compatibility', $target);
        }

        if ($extra !== null) {
            $filters[] = $extra;
        }

        $result = $this->search->search(ReleaseSearchIndex::NAME, new SearchQuery(
            text: $text,
            filter: new All(...$filters),
            page: 1,
            perPage: max(1, (int) config('filagora-plugins.search.candidates', 300)),
        ));

        return $this->ranking->rank($this->collapse($result->ids(), $viewer), $this->ranking->tokens($text));
    }

    /**
     * The plugins behind some matched releases, each with all its active releases (not only the ones
     * that matched), so ranking picks the true current release.
     *
     * @param  list<int|string>  $releaseIds
     * @return Collection<int, Plugin>
     */
    private function collapse(array $releaseIds, ?Authenticatable $viewer): Collection
    {
        if ($releaseIds === []) {
            return new Collection;
        }

        $pluginIds = Models::class('release')::query()->whereIn('id', $releaseIds)->distinct()->pluck('plugin_id')->all();

        $releases = Models::class('release')::query()
            ->with($this->compatibility->relations())
            ->where('status', PluginStatus::ACTIVE->value)
            ->whereIn('plugin_id', $pluginIds)
            ->get()
            ->groupBy('plugin_id');

        return Models::class('plugin')::query()
            ->with('type')
            ->whereIn('id', $pluginIds)
            ->where('status', PluginStatus::ACTIVE->value)
            ->whereIn('plugin_type_id', $this->visibility->visibleTypeIds($viewer) ?: [0])
            ->get()
            ->filter(fn (Plugin $plugin): bool => $releases->has($plugin->getKey()))
            ->each(fn (Plugin $plugin) => $plugin->setRelation('releases', $releases->get($plugin->getKey(), new Collection)))
            ->values();
    }
}
