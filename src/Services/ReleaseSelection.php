<?php

declare(strict_types=1);

namespace Filagora\Plugins\Services;

use Carbon\CarbonInterface;
use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\PluginVersion;

/**
 * Which release is the one to offer: the newest, optionally the newest that works with a target,
 * and whether one version is newer than another.
 */
class ReleaseSelection
{
    public function __construct(private readonly Compatibility $compatibility) {}

    /**
     * The release with the highest version; a tie goes to the one touched last, then the later row.
     *
     * @param  iterable<PluginRelease>  $releases
     */
    public function latest(iterable $releases, ?string $target = null): ?PluginRelease
    {
        $releases = $releases instanceof \Traversable ? iterator_to_array($releases, false) : array_values($releases);
        $target = $this->normalizeTarget($target);

        if ($target !== null) {
            $releases = array_values(array_filter($releases, fn (PluginRelease $release): bool => $this->supports($release, $target)));
        }

        if ($releases === []) {
            return null;
        }

        usort($releases, fn (PluginRelease $left, PluginRelease $right): int => $this->compare($left, $right));

        return $releases[0];
    }

    public function supports(PluginRelease $release, string $target): bool
    {
        $target = $this->normalizeTarget($target);

        return $target === null || $this->compatibility->supports($release, $target);
    }

    public function isUpdateAvailable(string $installedVersion, string $availableVersion): bool
    {
        return version_compare(PluginVersion::comparable($availableVersion), PluginVersion::comparable($installedVersion), '>');
    }

    /**
     * A target label without a leading "v", or null for none. It does not go through
     * `PluginVersion`, which would make every unreadable label equal "0".
     */
    public function normalizeTarget(?string $target): ?string
    {
        $normalized = ltrim(trim((string) $target), 'vV');

        return $normalized !== '' ? $normalized : null;
    }

    private function compare(PluginRelease $left, PluginRelease $right): int
    {
        $byVersion = version_compare(PluginVersion::comparable($right->version), PluginVersion::comparable($left->version));

        if ($byVersion !== 0) {
            return $byVersion;
        }

        $byTime = $this->timestamp($right->updated_at ?? $right->created_at) <=> $this->timestamp($left->updated_at ?? $left->created_at);

        return $byTime !== 0 ? $byTime : $right->getKey() <=> $left->getKey();
    }

    private function timestamp(?CarbonInterface $date): int
    {
        return $date?->getTimestamp() ?? 0;
    }
}
