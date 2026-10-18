<?php

declare(strict_types=1);

namespace Filagora\Plugins\Events;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A new plugin was submitted with its first release.
 */
final class PluginSubmitted
{
    use Dispatchable;

    public function __construct(
        public readonly Plugin $plugin,
        public readonly PluginRelease $release,
        public readonly Authenticatable $submittedBy,
    ) {}
}
