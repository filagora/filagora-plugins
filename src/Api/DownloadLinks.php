<?php

declare(strict_types=1);

namespace Filagora\Plugins\Api;

use Filagora\Plugins\Contracts\DownloadAccess;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Models;
use Illuminate\Http\Request;

/**
 * The download links a response offers, only when the downloads route is on and the person asking
 * could use it.
 */
class DownloadLinks
{
    public function __construct(
        private readonly ApiAccess $api,
        private readonly DownloadAccess $access,
    ) {}

    public function release(Request $request, PluginRelease $release): ?string
    {
        $release->loadMissing('plugin.type');

        if (! $this->enabled() || ! $this->api->can($request, ApiAccess::DOWNLOAD) || ! $this->access->canDownloadRelease($this->api->user($request), $release)) {
            return null;
        }

        return route('filagora-plugins.download.release', ['slug' => $release->plugin->slug, 'release' => $release->getKey()]);
    }

    /**
     * The link for the plugin's newest active release, when it has one.
     */
    public function plugin(Request $request, Plugin $plugin): ?string
    {
        $plugin->loadMissing('type');

        if (! $this->enabled() || ! $this->api->can($request, ApiAccess::DOWNLOAD) || ! $this->access->canDownloadPlugin($this->api->user($request), $plugin)) {
            return null;
        }

        $hasRelease = Models::class('release')::query()->where('plugin_id', $plugin->getKey())->where('status', PluginStatus::ACTIVE->value)->exists();

        return $hasRelease ? route('filagora-plugins.download', ['slug' => $plugin->slug]) : null;
    }

    private function enabled(): bool
    {
        return (bool) config('filagora-plugins.routes.downloads.enabled', false);
    }
}
