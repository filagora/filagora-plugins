<?php

declare(strict_types=1);

namespace Filagora\Plugins\Search;

use Filagora\Plugins\Support\Models;
use Filagora\Search\Indexing\Indexer;
use Filagora\Search\Support\SearchIndexRegistry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * A plugin was renamed, moved or switched on or off, or a type was: bring the documents of its
 * releases up to date (the plugin's name and status are copied into each). Logged and swallowed on
 * failure, like every sync.
 */
final class SyncReleaseDocuments implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    /**
     * @param  'plugin'|'type'  $scope
     */
    public function __construct(public readonly string $scope, public readonly int $id) {}

    public function handle(Indexer $indexer, SearchIndexRegistry $indexes): void
    {
        if (! $indexes->has(ReleaseSearchIndex::NAME) || ! $indexer->isActive()) {
            return;
        }

        $index = $indexes->get(ReleaseSearchIndex::NAME);
        $releases = Models::class('release')::query()->with('plugin.type');

        $this->scope === 'type'
            ? $releases->whereHas('plugin', fn ($plugin) => $plugin->where('plugin_type_id', $this->id))
            : $releases->where('plugin_id', $this->id);

        try {
            $releases->chunkById(200, function ($chunk) use ($indexer, $index): void {
                foreach ($chunk as $release) {
                    $indexer->sync($index, $release);
                }
            });
        } catch (Throwable $e) {
            Log::warning('Plugin release documents could not be synced.', ['scope' => $this->scope, 'id' => $this->id, 'exception' => $e->getMessage()]);
        }
    }
}
