<?php

declare(strict_types=1);

namespace Filagora\Plugins\Api;

use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Contracts\PluginIdentifiers;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Services\ReleaseSelection;
use Filagora\Plugins\Services\Visibility;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Given what a client has installed, says which of those plugins have a newer release the client
 * can use.
 */
class UpdateCheck
{
    public function __construct(
        private readonly PluginIdentifiers $identifiers,
        private readonly Visibility $visibility,
        private readonly ReleaseSelection $selection,
        private readonly DownloadLinks $downloads,
        private readonly Compatibility $compatibility,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $inventory  Each row names a plugin and gives the `version` installed.
     * @param  string|null  $target  What the client runs on (a version), to pick releases that work with it.
     * @return list<array<string, mixed>>
     */
    public function rows(Request $request, ?Authenticatable $viewer, array $inventory, ?string $target = null): array
    {
        $rows = [];

        foreach ($inventory as $item) {
            $plugin = $this->identifiers->find($item, $viewer);

            if ($plugin === null) {
                continue;
            }

            $release = $this->selection->latest($this->visibility->publicReleases($plugin)->get(), $target);

            if ($release === null) {
                continue;
            }

            $installed = trim((string) ($item['version'] ?? ''));

            if (! $this->selection->isUpdateAvailable($installed, $release->version)) {
                continue;
            }

            $rows[] = [
                'slug' => $plugin->slug,
                'id' => (int) $plugin->getKey(),
                'installed_version' => $installed,
                'update_available' => true,
                'latest_compatible_release' => $this->release($request, $release),
            ];
        }

        return $rows;
    }

    /**
     * @return array<string, mixed>
     */
    private function release(Request $request, PluginRelease $release): array
    {
        return [
            'version' => $release->version,
            'status' => $release->status->value,
            'changelog' => (string) $release->changelog,
            'checksum_sha256' => (string) $release->checksum_sha256,
            'download_url' => $this->downloads->release($request, $release),
            'compatibility' => $this->compatibility->targets($release),
            'published_at' => ($release->created_at ?? $release->updated_at)?->toISOString(),
        ];
    }
}
