<?php

declare(strict_types=1);

namespace Filagora\Plugins\Events;

use Filagora\Plugins\Enums\ModerationAction;
use Filagora\Plugins\Models\Plugin;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A moderator approved a plugin, or rejected it for good.
 */
final class PluginModerated
{
    use Dispatchable;

    public function __construct(
        public readonly Plugin $plugin,
        public readonly ModerationAction $action,
        public readonly Authenticatable $moderator,
        public readonly string $note,
    ) {}
}
