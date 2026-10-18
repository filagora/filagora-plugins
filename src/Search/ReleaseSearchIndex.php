<?php

declare(strict_types=1);

namespace Filagora\Plugins\Search;

use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Models;
use Filagora\Search\Contracts\SearchIndex;
use Filagora\Search\Support\Document;
use Filagora\Search\Support\IndexDefinition;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;

/**
 * Releases as search documents: the active releases of active plugins in active types, with the
 * plugin's name, slug and description copied in so the engine can rank without a join. A search
 * finds releases (so it can match a version or a changelog line) and `PluginSearch` collapses them
 * to plugins.
 *
 * A profile package that adds fields (a packaging flag, say) subclasses this, overrides
 * `extraFields()` and `extraFilterable()`, and registers its subclass under the same name.
 */
class ReleaseSearchIndex implements SearchIndex
{
    public const NAME = 'plugin_releases';

    public function __construct(
        private readonly Compatibility $compatibility,
        private readonly ReleaseDatabaseSearch $database,
    ) {}

    public function definition(): IndexDefinition
    {
        return new IndexDefinition(
            name: self::NAME,
            searchable: ['plugin_name', 'plugin_slug', 'plugin_description', 'version', 'changelog'],
            filterable: ['status', 'plugin_status', 'type_is_active', 'plugin_type_id', 'plugin_id', 'compatibility', ...$this->extraFilterable()],
            sortable: ['approved_at_ts', 'download_count'],
        );
    }

    public function model(): string
    {
        return Models::class('release');
    }

    public function shouldBeSearchable(Model $model): bool
    {
        if (! $model instanceof PluginRelease || $model->status !== PluginStatus::ACTIVE) {
            return false;
        }

        $plugin = $model->plugin;

        return $plugin->status === PluginStatus::ACTIVE && $plugin->type->is_active;
    }

    public function toDocument(Model $model): Document
    {
        assert($model instanceof PluginRelease);
        $plugin = $model->plugin;
        assert($plugin instanceof Plugin);
        $model->loadMissing($this->compatibility->relations());

        return new Document($model->getKey(), [
            'plugin_id' => (int) $model->plugin_id,
            'plugin_type_id' => (int) $plugin->plugin_type_id,
            'plugin_name' => (string) $plugin->name,
            'plugin_slug' => (string) $plugin->slug,
            'plugin_description' => (string) $plugin->description,
            'plugin_status' => $plugin->status->value,
            'type_is_active' => (bool) $plugin->type->is_active,
            'status' => $model->status->value,
            'version' => $model->version,
            'changelog' => (string) $model->changelog,
            'download_count' => (int) $model->download_count,
            'approved_at_ts' => ($model->approved_at ?? $model->created_at)?->getTimestamp() ?? 0,
            'compatibility' => $this->compatibility->targets($model),
            ...$this->extraFields($model),
        ]);
    }

    public function query(): Builder
    {
        return $this->searchable()->with(['plugin.type', ...$this->compatibility->relations()]);
    }

    public function expectedCount(): int
    {
        return $this->searchable()->count();
    }

    public function fallback(SearchQuery $query): SearchResult
    {
        return $this->database->search($query);
    }

    /**
     * Fields a profile package adds to every document.
     *
     * @return array<string, mixed>
     */
    protected function extraFields(PluginRelease $release): array
    {
        return [];
    }

    /**
     * Names of the extra fields a search may filter on.
     *
     * @return list<string>
     */
    protected function extraFilterable(): array
    {
        return [];
    }

    /**
     * @return Builder<Model>
     */
    private function searchable(): Builder
    {
        /** @var Builder<Model> $query */
        $query = Models::class('release')::query()
            ->where('status', PluginStatus::ACTIVE->value)
            ->whereHas('plugin', fn (Builder $plugin) => $plugin
                ->where('status', PluginStatus::ACTIVE->value)
                ->whereHas('type', fn (Builder $type) => $type->where('is_active', true)));

        return $query;
    }
}
