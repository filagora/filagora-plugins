<?php

declare(strict_types=1);

namespace Filagora\Plugins\Events;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * People who follow a plugin and want to hear about a new release. The default listener emails
 * them; a host sending its own (a bell, browser push) listens here and switches that off.
 */
final class SubscribersToNotify
{
    use Dispatchable;

    /**
     * @param  list<Authenticatable>  $recipients
     */
    public function __construct(
        public readonly Plugin $plugin,
        public readonly PluginRelease $release,
        public readonly array $recipients,
    ) {}
}
