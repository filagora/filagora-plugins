<?php

declare(strict_types=1);

namespace Filagora\Plugins\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Archived releases went back to the status they had before.
 */
final class ReleasesRestored
{
    use Dispatchable;

    /**
     * @param  list<int>  $releaseIds
     */
    public function __construct(public readonly array $releaseIds) {}
}
