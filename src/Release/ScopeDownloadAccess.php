<?php

declare(strict_types=1);

namespace Filagora\Plugins\Release;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Plugins\Contracts\DownloadAccess;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * An active plugin of an active type can be downloaded by anyone, unless its type requires an
 * entitlement scope, which then has to be allowed by the access checker. A release must be active,
 * or archived when `downloads.archived_releases` is on.
 */
final class ScopeDownloadAccess implements DownloadAccess
{
    public function __construct(private readonly AccessChecker $access) {}

    public function requiredScope(Plugin $plugin): string
    {
        return trim((string) $plugin->type?->required_entitlement_scope);
    }

    public function canDownloadPlugin(?Authenticatable $user, Plugin $plugin): bool
    {
        if ($plugin->status !== PluginStatus::ACTIVE || ! $plugin->type->is_active) {
            return false;
        }

        $scope = $this->requiredScope($plugin);

        return $scope === '' || $this->access->allows($user, $scope);
    }

    public function canDownloadRelease(?Authenticatable $user, PluginRelease $release): bool
    {
        $release->loadMissing('plugin.type');

        $downloadable = $release->status === PluginStatus::ACTIVE
            || ($release->status === PluginStatus::ARCHIVED && config('filagora-plugins.downloads.archived_releases', true));

        return $downloadable && $release->plugin !== null && $this->canDownloadPlugin($user, $release->plugin);
    }
}
