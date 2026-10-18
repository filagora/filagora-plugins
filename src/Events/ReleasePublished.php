<?php

declare(strict_types=1);

namespace Filagora\Plugins\Events;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A release became active, because its author may publish without review or a moderator approved
 * it (`$moderator` is set only then). A profile package listens here to prepare the archive;
 * followers are told from here too.
 */
final class ReleasePublished
{
    use Dispatchable;

    public function __construct(
        public readonly Plugin $plugin,
        public readonly PluginRelease $release,
        public readonly ?Authenticatable $author,
        public readonly ?Authenticatable $moderator = null,
    ) {}
}
