<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may download a plugin or a release. The default asks the access checker for the entitlement
 * scope its type requires; bind your own to decide another way.
 */
interface DownloadAccess
{
    /**
     * The entitlement scope the plugin's type requires, or an empty string for none.
     */
    public function requiredScope(Plugin $plugin): string;

    public function canDownloadPlugin(?Authenticatable $user, Plugin $plugin): bool;

    public function canDownloadRelease(?Authenticatable $user, PluginRelease $release): bool;
}
