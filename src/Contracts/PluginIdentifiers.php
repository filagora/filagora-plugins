<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Filagora\Plugins\Models\Plugin;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Finds the plugin a client means from what it sends. The default understands `slug` and `id`; a
 * profile package adds the identifiers its clients already use.
 */
interface PluginIdentifiers
{
    /**
     * @param  array<string, mixed>  $row
     */
    public function find(array $row, ?Authenticatable $viewer): ?Plugin;

    /**
     * Whether the row names a plugin at all, so a request with no way to find one can be refused.
     *
     * @param  array<string, mixed>  $row
     */
    public function identifies(array $row): bool;
}
