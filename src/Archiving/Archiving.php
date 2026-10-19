<?php

declare(strict_types=1);

namespace Filagora\Plugins\Archiving;

use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Events\ReleasesArchived;
use Filagora\Plugins\Events\ReleasesRestored;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Moves historical releases out of the live list ("archived") so they leave the security sweep,
 * and brings them back. Archived releases stay listed and, by default, downloadable.
 *
 * Hard rule, above every other: no path through here ever leaves a plugin without an active
 * release. A plugin's newest active release always stays active, so someone can still take the
 * plugin over from its source.
 *
 * Nothing here checks who is asking; a screen or command that offers it must.
 */
class Archiving
{
    public function __construct(
        private readonly Compatibility $compatibility,
        private readonly ArchiveGuards $guards,
    ) {}

    /**
     * Work out which active releases could be archived. A release stays active when it is:
     *
     * 1. the plugin's newest active release;
     * 2. recent (uploaded within `keepMonths`);
     * 3. the newest for a version line of what the plugin still works with (`keepPerTargetLine`), so
     *    nobody on an older version is left with no build they can use;
     * 4. kept by a guard, such as an unresolved serious security finding.
     *
     * A plugin that is dead weight (rejected, in an inactive type, moderated and untouched for a
     * year, or ownerless with no recent release) keeps only its newest release (`wholePlugin`).
     *
     * @param  array{pluginIds?: list<int>|null, keepMonths?: int|null, keepPerTargetLine?: bool|null, wholePlugin?: bool|null, limit?: int|null}  $options
     */
    public function plan(array $options = []): ArchivePlan
    {
        $keepMonths = max(0, (int) ($options['keepMonths'] ?? config('filagora-plugins.archiving.keep_months', 18)));
        $perLine = (bool) ($options['keepPerTargetLine'] ?? config('filagora-plugins.archiving.keep_per_target_line', true));
        $wholePlugin = (bool) ($options['wholePlugin'] ?? true);
        $limit = $options['limit'] ?? null;
        $pluginIds = $options['pluginIds'] ?? null;
        $cutoff = Carbon::now()->subMonths($keepMonths);

        $plugins = [];
        $totals = ['plugins' => 0, 'active' => 0, 'keep' => 0, 'eligible' => 0, 'selected' => 0, 'whole_plugin' => 0];

        // The whole catalogue is walked first and `limit` applied to the result, so the printed plan
        // and its totals match what apply() would do.
        Models::class('plugin')::query()
            ->with('type')
            ->when($pluginIds !== null, fn ($query) => $query->whereIn('id', $pluginIds))
            ->whereHas('releases', fn ($query) => $query->where('status', PluginStatus::ACTIVE->value))
            ->orderBy('id')
            ->chunk(100, function (Collection $chunk) use (&$plugins, &$totals, $cutoff, $keepMonths, $perLine, $wholePlugin): void {
                foreach ($chunk as $plugin) {
                    $row = $this->planPlugin($plugin, $cutoff, $keepMonths, $perLine, $wholePlugin, $totals);

                    if ($row !== null && $row['archive'] !== []) {
                        $plugins[(int) $plugin->getKey()] = $row;
                    }
                }
            });

        $archiveIds = array_merge(...array_map(static fn (array $row): array => $row['archive'], array_values($plugins)));

        if ($limit !== null && count($archiveIds) > $limit) {
            $archiveIds = array_slice($archiveIds, 0, $limit);
            $selected = array_fill_keys($archiveIds, true);

            foreach ($plugins as $pluginId => $row) {
                $row['archive'] = array_values(array_filter($row['archive'], static fn (int $id): bool => isset($selected[$id])));

                if ($row['archive'] === []) {
                    unset($plugins[$pluginId]);
                } else {
                    $plugins[$pluginId] = $row;
                }
            }
        }

        $totals['selected'] = count($archiveIds);

        return new ArchivePlan($plugins, $archiveIds, $totals);
    }

    /**
     * Archive the given releases, except each plugin's newest active release. Safe with any list of
     * ids, such as a screen's selection. Each plugin touched gets one note.
     *
     * @param  list<int>  $releaseIds
     * @return array{archived: int, kept_back: int}
     */
    public function archive(array $releaseIds, ?Authenticatable $actor = null): array
    {
        if ($releaseIds === []) {
            return ['archived' => 0, 'kept_back' => 0];
        }

        $now = Carbon::now();
        $archived = [];
        $keptBack = 0;

        DB::transaction(function () use ($releaseIds, $actor, $now, &$archived, &$keptBack): void {
            $releases = Models::class('release')::query()
                ->whereIn('id', $releaseIds)
                ->where('status', PluginStatus::ACTIVE->value)
                ->orderBy('id')
                ->get();

            $newest = Models::class('plugin')::query()
                ->whereIn('id', $releases->pluck('plugin_id')->unique()->all())
                ->with('latestActiveRelease')
                ->get()
                ->mapWithKeys(fn (Plugin $plugin): array => [(int) $plugin->getKey() => (int) ($plugin->latestActiveRelease->id ?? 0)]);

            foreach ($releases as $release) {
                if ((int) $release->getKey() === $newest->get((int) $release->plugin_id) || ! $this->hasOtherActiveRelease($release)) {
                    $keptBack++;

                    continue;
                }

                $release->forceFill([
                    'status_before_archive' => $release->status->value,
                    'status' => PluginStatus::ARCHIVED,
                    'archived_at' => $now,
                    'archived_by_id' => $actor?->getAuthIdentifier(),
                ])->save();

                $archived[(int) $release->plugin_id][] = $release;
            }

            foreach ($archived as $pluginId => $done) {
                Models::class('moderator_note')::query()->create([
                    'plugin_id' => $pluginId,
                    'moderator_id' => $actor?->getAuthIdentifier(),
                    'note' => __('filagora-plugins::moderation.notes.releases_archived', [
                        'count' => count($done),
                        'versions' => collect($done)->map(fn (PluginRelease $release): string => 'v'.$release->displayVersion())->implode(', '),
                    ]),
                ]);
            }
        });

        $ids = array_merge(...array_map(static fn (array $done): array => array_map(static fn (PluginRelease $release): int => (int) $release->getKey(), $done), array_values($archived)));

        if ($ids !== []) {
            ReleasesArchived::dispatch($ids, $actor);
        }

        return ['archived' => count($ids), 'kept_back' => $keptBack];
    }

    /**
     * Put archived releases back to the status they had before. There is no limit on restoring.
     *
     * @param  list<int>  $releaseIds
     */
    public function restore(array $releaseIds): int
    {
        if ($releaseIds === []) {
            return 0;
        }

        $restored = [];

        DB::transaction(function () use ($releaseIds, &$restored): void {
            foreach (Models::class('release')::query()->whereIn('id', $releaseIds)->where('status', PluginStatus::ARCHIVED->value)->orderBy('id')->get() as $release) {
                $release->forceFill([
                    'status' => PluginStatus::tryFrom((string) $release->status_before_archive) ?? PluginStatus::ACTIVE,
                    'status_before_archive' => null,
                    'archived_at' => null,
                    'archived_by_id' => null,
                ])->save();

                $restored[] = (int) $release->getKey();
            }
        });

        if ($restored !== []) {
            ReleasesRestored::dispatch($restored);
        }

        return count($restored);
    }

    /**
     * @param  array{plugins: int, active: int, keep: int, eligible: int, selected: int, whole_plugin: int}  $totals
     * @return array{slug: string, name: string, keep: array<int, string>, archive: list<int>}|null
     */
    private function planPlugin(Plugin $plugin, Carbon $cutoff, int $keepMonths, bool $perLine, bool $wholePlugin, array &$totals): ?array
    {
        $active = Models::class('release')::query()
            ->where('plugin_id', $plugin->getKey())
            ->where('status', PluginStatus::ACTIVE->value)
            ->with($this->compatibility->relations())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get();

        if ($active->isEmpty()) {
            return null;
        }

        $totals['plugins']++;
        $totals['active'] += $active->count();

        $dead = $wholePlugin && $this->isDead($plugin, $active);

        if ($dead) {
            $totals['whole_plugin']++;
        }

        $keep = [(int) $active->first()->getKey() => $dead ? 'last release (plugin archived)' : 'current build'];

        if (! $dead) {
            foreach ($active as $release) {
                if ($release->created_at !== null && $release->created_at->greaterThanOrEqualTo($cutoff)) {
                    $keep[(int) $release->getKey()] ??= "uploaded within {$keepMonths}mo";
                }
            }

            if ($perLine) {
                $owner = [];

                foreach ($active as $release) { // newest first
                    foreach ($this->compatibility->targets($release) as $target) {
                        $line = $this->line($target);

                        if ($line !== null && ! isset($owner[$line])) {
                            $owner[$line] = (int) $release->getKey();
                        }
                    }
                }

                foreach ($owner as $line => $releaseId) {
                    $keep[$releaseId] ??= "last active build for {$line}";
                }
            }

            foreach ($active as $release) {
                $reason = $this->guards->reasonToKeep($plugin, $release);

                if ($reason !== null) {
                    $keep[(int) $release->getKey()] ??= $reason;
                }
            }
        }

        $archive = $active->pluck('id')->map(static fn ($id): int => (int) $id)->reject(static fn (int $id): bool => isset($keep[$id]))->values()->all();

        $totals['keep'] += count($keep);
        $totals['eligible'] += count($archive);

        return ['slug' => (string) $plugin->slug, 'name' => (string) $plugin->name, 'keep' => $keep, 'archive' => $archive];
    }

    private function hasOtherActiveRelease(PluginRelease $release): bool
    {
        return Models::class('release')::query()
            ->where('plugin_id', $release->plugin_id)
            ->where('status', PluginStatus::ACTIVE->value)
            ->whereKeyNot($release->getKey())
            ->exists();
    }

    /**
     * @param  Collection<int, PluginRelease>  $active
     */
    private function isDead(Plugin $plugin, Collection $active): bool
    {
        if ($plugin->status === PluginStatus::REJECTED_FINAL || ! $plugin->type->is_active) {
            return true;
        }

        $staleModerated = Carbon::now()->subMonths((int) config('filagora-plugins.archiving.stale_moderated_months', 12));

        if ($plugin->status === PluginStatus::MODERATED && $plugin->updated_at !== null && $plugin->updated_at->lessThan($staleModerated)) {
            return true;
        }

        $ownerless = Carbon::now()->subMonths((int) config('filagora-plugins.archiving.ownerless_months', 24));

        return $plugin->owner_id === null
            && $active->every(static fn (PluginRelease $release): bool => $release->created_at === null || $release->created_at->lessThan($ownerless));
    }

    /**
     * The first two parts of a version ("2.1.3" is "2.1"), or the label itself when it is not one.
     */
    private function line(string $target): ?string
    {
        $parts = explode('.', trim($target));

        if (count($parts) < 2 || ! ctype_digit($parts[0]) || ! ctype_digit($parts[1])) {
            return $target !== '' ? $target : null;
        }

        return $parts[0].'.'.$parts[1];
    }
}
