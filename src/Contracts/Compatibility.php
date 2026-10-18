<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Database\Eloquent\Builder;

/**
 * What a release is compatible with: the versions of the host software it runs on, for a profile
 * package that tracks that. The package asks, and never stores it, so it works with nothing bound
 * (every release then counts as compatible with every target).
 */
interface Compatibility
{
    /**
     * Release relations to load with a release, so `targets()` and `supports()` need no query.
     *
     * @return list<string>
     */
    public function relations(): array;

    /**
     * The targets a release declares, as labels such as "2.1.0".
     *
     * @return list<string>
     */
    public function targets(PluginRelease $release): array;

    /**
     * Whether the release works with a target. With no declared targets, a profile decides what
     * that means; the default says yes.
     */
    public function supports(PluginRelease $release, string $target): bool;

    /**
     * Narrow a release query to those compatible with a target; for the database search.
     *
     * @param  Builder<PluginRelease>  $releases
     */
    public function restrict(Builder $releases, string $target): void;
}
