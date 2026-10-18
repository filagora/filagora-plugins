<?php

declare(strict_types=1);

namespace Filagora\Plugins\Search;

use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Models;
use Filagora\Search\Contracts\Filter;
use Filagora\Search\Filters\All;
use Filagora\Search\Filters\Any;
use Filagora\Search\Filters\Equals;
use Filagora\Search\Filters\In;
use Filagora\Search\Filters\Not;
use Filagora\Search\Support\Hit;
use Filagora\Search\Support\SearchQuery;
use Filagora\Search\Support\SearchResult;
use Illuminate\Database\Eloquent\Builder;
use InvalidArgumentException;

/**
 * Answers a release search from the database: the `database` engine, and the fallback when the
 * search server fails. It understands the filter tree the engines get, over the fields a plugin
 * search uses, and returns the newest releases first (relevance has no database equivalent).
 */
class ReleaseDatabaseSearch
{
    public function __construct(private readonly Compatibility $compatibility) {}

    public function search(SearchQuery $query): SearchResult
    {
        $builder = Models::class('release')::query()
            ->where('status', PluginStatus::ACTIVE->value)
            ->whereHas('plugin', fn (Builder $plugin) => $plugin
                ->where('status', PluginStatus::ACTIVE->value)
                ->whereHas('type', fn (Builder $type) => $type->where('is_active', true)));

        // Every word has to be found somewhere, as the other engines do.
        foreach (preg_split('/\s+/u', trim($query->text), -1, PREG_SPLIT_NO_EMPTY) ?: [] as $word) {
            $like = '%'.addcslashes($word, '\\%_').'%';

            $builder->where(fn (Builder $match) => $match
                ->where('version', 'like', $like)
                ->orWhere('changelog', 'like', $like)
                ->orWhereHas('plugin', fn (Builder $plugin) => $plugin
                    ->where('name', 'like', $like)
                    ->orWhere('slug', 'like', $like)
                    ->orWhere('description', 'like', $like)));
        }

        if ($query->filter !== null) {
            $this->apply($builder, $query->filter);
        }

        $total = (clone $builder)->count();
        $ids = $builder->orderByDesc('created_at')->orderByDesc('id')->forPage($query->page, $query->perPage)->pluck('id');

        return new SearchResult($ids->map(static fn (mixed $id): Hit => new Hit((int) $id))->all(), $total);
    }

    /**
     * @param  Builder<PluginRelease>  $builder
     */
    private function apply(Builder $builder, Filter $filter): void
    {
        match (true) {
            $filter instanceof All => $builder->where(function (Builder $all) use ($filter): void {
                foreach ($filter->filters as $inner) {
                    $all->where(fn (Builder $group) => $this->apply($group, $inner));
                }
            }),
            $filter instanceof Any => $builder->where(function (Builder $any) use ($filter): void {
                foreach ($filter->filters as $inner) {
                    $any->orWhere(fn (Builder $group) => $this->apply($group, $inner));
                }
            }),
            $filter instanceof Not => $builder->whereNot(fn (Builder $group) => $this->apply($group, $filter->filter)),
            $filter instanceof Equals => $this->field($builder, $filter->field, [$filter->value]),
            $filter instanceof In => $this->field($builder, $filter->field, $filter->values),
            default => throw new InvalidArgumentException('The release search does not understand that filter.'),
        };
    }

    /**
     * @param  Builder<PluginRelease>  $builder
     * @param  list<mixed>  $values
     */
    private function field(Builder $builder, string $field, array $values): void
    {
        match ($field) {
            'status', 'plugin_id' => $builder->whereIn($field, $values),
            'plugin_status' => $builder->whereHas('plugin', fn (Builder $plugin) => $plugin->whereIn('status', $values)),
            'plugin_type_id' => $builder->whereHas('plugin', fn (Builder $plugin) => $plugin->whereIn('plugin_type_id', $values)),
            'type_is_active' => $builder->whereHas('plugin.type', fn (Builder $type) => $type->whereIn('is_active', array_map('boolval', $values))),
            'compatibility' => $builder->where(function (Builder $any) use ($values): void {
                foreach ($values as $target) {
                    $any->orWhere(fn (Builder $one) => $this->compatibility->restrict($one, (string) $target));
                }
            }),
            default => throw new InvalidArgumentException("The release search cannot filter on [{$field}]."),
        };
    }
}
