<?php

declare(strict_types=1);

namespace Filagora\Plugins\Events;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A release was rejected for good, by a moderator or because a newer upload replaced it (then
 * `$except` is the person who uploaded the new one, who needs no notice).
 */
final class ReleaseRejected
{
    use Dispatchable;

    public function __construct(
        public readonly Plugin $plugin,
        public readonly PluginRelease $release,
        public readonly string $note,
        public readonly bool $superseded = false,
        public readonly ?Authenticatable $except = null,
    ) {}
}
