<?php

declare(strict_types=1);

namespace Filagora\Plugins\Search;

use Carbon\CarbonImmutable;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Services\ReleaseSelection;
use Illuminate\Support\Collection;

/**
 * How plugins are scored against a search: the text match against the plugin and its canonical
 * release (the newest, or the newest that fits a version typed in the query), nudged by
 * popularity, recency and how well it fits, so equal matches come back in a sensible, stable order.
 *
 * A profile package adds its own nudges by subclassing and overriding `extraBonus()`, and binds
 * its subclass.
 */
class PluginRanking
{
    public function __construct(private readonly ReleaseSelection $selection) {}

    /**
     * @return list<string>
     */
    public function tokens(string $query): array
    {
        $tokens = preg_split('/\s+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY);

        return $tokens === false ? [] : array_values(array_unique($tokens));
    }

    /**
     * Order plugins best match first. Each plugin must already have its active `releases` loaded.
     * Each carries `search_score`, `search_activity`, `search_downloads` and `search_canonical_release`.
     *
     * @param  Collection<int, Plugin>  $plugins
     * @param  list<string>  $tokens
     * @return Collection<int, Plugin>
     */
    public function rank(Collection $plugins, array $tokens): Collection
    {
        return $plugins
            ->map(function (Plugin $plugin) use ($tokens): Plugin {
                $releases = $plugin->releases->all();
                $canonical = $this->canonicalRelease($releases, $tokens);
                $downloads = (int) $plugin->releases->sum('download_count');

                $plugin->setAttribute('search_canonical_release', $canonical);
                $plugin->setAttribute('search_downloads', $downloads);
                $plugin->setAttribute('search_activity', $canonical !== null ? $this->activity($canonical) : 0);
                $plugin->setAttribute('search_score', $canonical !== null
                    ? $this->score($plugin, $canonical, $downloads, $tokens)
                    : $this->scoreText((string) $plugin->name, $tokens, 12));

                return $plugin;
            })
            ->sort(fn (Plugin $left, Plugin $right): int => [
                $right->getAttribute('search_score'),
                $right->getAttribute('search_activity'),
                $right->getAttribute('search_downloads'),
                $left->getKey(),
            ] <=> [
                $left->getAttribute('search_score'),
                $left->getAttribute('search_activity'),
                $left->getAttribute('search_downloads'),
                $right->getKey(),
            ])
            ->values();
    }

    /**
     * @param  list<PluginRelease>  $releases
     * @param  list<string>  $tokens
     */
    public function canonicalRelease(array $releases, array $tokens): ?PluginRelease
    {
        if ($releases === []) {
            return null;
        }

        foreach ($this->versionTokens($tokens) as $target) {
            $fitting = $this->selection->latest($releases, $target);

            if ($fitting !== null) {
                return $fitting;
            }
        }

        return $this->selection->latest($releases) ?? $releases[0];
    }

    /**
     * Words in the query that read as a version ("2.1", "1.5.7"), which are taken as the version
     * the person wants a plugin for.
     *
     * @param  list<string>  $tokens
     * @return list<string>
     */
    public function versionTokens(array $tokens): array
    {
        return array_values(array_filter($tokens, static fn (string $token): bool => preg_match('/^\d+\.\d+(?:\.\d+)?$/', $token) === 1));
    }

    /**
     * @param  list<string>  $tokens
     */
    public function score(Plugin $plugin, PluginRelease $canonical, int $downloads, array $tokens): int
    {
        $freeText = max(
            $this->scoreText((string) $plugin->description, $tokens, 3),
            $this->scoreText((string) $canonical->changelog, $tokens, 2),
        );

        return $this->scoreText((string) $plugin->name, $tokens, 12)
            + $freeText
            + $this->scoreText($canonical->version, $tokens, 4)
            + $this->fitBonus($canonical, $tokens)
            + $this->downloadBonus($downloads)
            + $this->recencyBonus($canonical)
            + $this->extraBonus($plugin, $canonical);
    }

    /**
     * What a profile package adds to a plugin's score.
     */
    protected function extraBonus(Plugin $plugin, PluginRelease $canonical): int
    {
        return 0;
    }

    public function activity(PluginRelease $release): int
    {
        return ($release->approved_at ?? $release->created_at)?->getTimestamp() ?? 0;
    }

    /**
     * @param  list<string>  $tokens
     */
    private function fitBonus(PluginRelease $release, array $tokens): int
    {
        return 45 * count(array_filter($this->versionTokens($tokens), fn (string $token): bool => $this->selection->supports($release, $token)));
    }

    private function downloadBonus(int $downloads): int
    {
        return $downloads <= 0 ? 0 : (int) min(20, floor(log10($downloads + 1) * 5));
    }

    private function recencyBonus(PluginRelease $release): int
    {
        $activity = $this->activity($release);

        if ($activity <= 0) {
            return 0;
        }

        $days = max(0, CarbonImmutable::createFromTimestamp($activity)->diffInDays(now()));

        return match (true) {
            $days <= 90 => 55,
            $days <= 365 => 40,
            $days <= 365 * 3 => 24,
            $days <= 365 * 7 => 12,
            default => 0,
        };
    }

    /**
     * @param  list<string>  $tokens
     */
    private function scoreText(string $text, array $tokens, int $weight): int
    {
        if ($text === '' || $tokens === []) {
            return 0;
        }

        $normalized = mb_strtolower($text);
        $padded = ' '.$normalized.' ';
        $score = 0;

        foreach ($tokens as $token) {
            $score += match (true) {
                $token === '' => 0,
                $normalized === $token => 8 * $weight,
                str_starts_with($normalized, $token) => 6 * $weight,
                str_contains($padded, ' '.$token.' ') => 5 * $weight,
                str_contains($normalized, $token) => 3 * $weight,
                default => 0,
            };
        }

        return $score;
    }
}
