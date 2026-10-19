<?php

declare(strict_types=1);

namespace Filagora\Plugins\Events;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Releases left the live list and the security sweep. `$releaseIds` are the ones archived.
 */
final class ReleasesArchived
{
    use Dispatchable;

    /**
     * @param  list<int>  $releaseIds
     */
    public function __construct(public readonly array $releaseIds, public readonly ?Authenticatable $actor = null) {}
}
