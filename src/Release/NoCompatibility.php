<?php

declare(strict_types=1);

namespace Filagora\Plugins\Release;

use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Database\Eloquent\Builder;

/**
 * Every release is compatible with every target, and none declares any.
 */
final class NoCompatibility implements Compatibility
{
    public function relations(): array
    {
        return [];
    }

    public function targets(PluginRelease $release): array
    {
        return [];
    }

    public function supports(PluginRelease $release, string $target): bool
    {
        return true;
    }

    public function restrict(Builder $releases, string $target): void {}
}
