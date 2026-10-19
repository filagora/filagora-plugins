<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;

/**
 * A reason to keep a release active when old releases are archived: a security package keeps one
 * with an unresolved serious finding, for example. Register one with `ArchiveGuards::register()`.
 */
interface ArchiveGuard
{
    /**
     * Why the release must stay active, or null to have no say.
     */
    public function reasonToKeep(Plugin $plugin, PluginRelease $release): ?string;
}
