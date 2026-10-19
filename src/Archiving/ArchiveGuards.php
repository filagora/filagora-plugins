<?php

declare(strict_types=1);

namespace Filagora\Plugins\Archiving;

use Filagora\Plugins\Contracts\ArchiveGuard;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;

/**
 * The guards a package has registered; the first with a reason wins.
 */
final class ArchiveGuards
{
    /** @var list<ArchiveGuard> */
    private array $guards = [];

    public function register(ArchiveGuard $guard): void
    {
        $this->guards[] = $guard;
    }

    public function reasonToKeep(Plugin $plugin, PluginRelease $release): ?string
    {
        foreach ($this->guards as $guard) {
            $reason = $guard->reasonToKeep($plugin, $release);

            if ($reason !== null) {
                return $reason;
            }
        }

        return null;
    }
}
