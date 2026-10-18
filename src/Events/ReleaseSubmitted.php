<?php

declare(strict_types=1);

namespace Filagora\Plugins\Events;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A release was uploaded: for a new plugin's first release, and for every later one. Its status
 * says whether it went live or is waiting for review. A security scan listens here.
 */
final class ReleaseSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly Plugin $plugin,
        public readonly PluginRelease $release,
        public readonly Authenticatable $submittedBy,
    ) {}
}
