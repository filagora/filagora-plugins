<?php

declare(strict_types=1);

namespace Filagora\Plugins\Tests\Support;

use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Database\Eloquent\Builder;

/**
 * A compatibility list kept in memory: release id to the targets it works with.
 */
final class FakeCompatibility implements Compatibility
{
    /** @var array<int, list<string>> */
    public array $targets = [];

    public function declare(PluginRelease $release, string ...$targets): void
    {
        $this->targets[$release->getKey()] = array_values($targets);
    }

    public function relations(): array
    {
        return [];
    }

    public function targets(PluginRelease $release): array
    {
        return $this->targets[$release->getKey()] ?? [];
    }

    public function supports(PluginRelease $release, string $target): bool
    {
        return in_array($target, $this->targets($release), true);
    }

    public function restrict(Builder $releases, string $target): void
    {
        $ids = array_keys(array_filter($this->targets, fn (array $targets): bool => in_array($target, $targets, true)));

        $releases->whereIn('id', $ids ?: [0]);
    }
}
