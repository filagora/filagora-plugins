<?php

declare(strict_types=1);

namespace Filagora\Plugins\Http\Controllers;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Plugins\Api\ApiAccess;
use Filagora\Plugins\Contracts\DownloadAccess;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Gates;
use Filagora\Plugins\Support\Models;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The optional download endpoints (`routes.downloads.enabled`): a plugin's newest active release, or
 * one release. Staff who may moderate (in a browser session, not with a token) can fetch anything for
 * review. Everyone else gets what `DownloadAccess` allows; a guest is sent to sign in when a type
 * needs it. The count goes up only for downloads the public could have made.
 */
class DownloadController extends Controller
{
    public function __construct(
        private readonly ApiAccess $api,
        private readonly DownloadAccess $access,
        private readonly AccessChecker $checker,
    ) {}

    public function plugin(Request $request, string $slug): StreamedResponse
    {
        $plugin = $this->find($slug);
        $reviewer = $this->reviewer($request);

        abort_unless($this->isPublic($plugin) || $reviewer, 404);
        $this->authorizeDownload($request, $reviewer, fn (?Authenticatable $user): bool => $this->access->canDownloadPlugin($user, $plugin), $plugin);

        $release = Models::class('release')::query()
            ->where('plugin_id', $plugin->getKey())
            ->unless($reviewer, fn ($query) => $query->where('status', PluginStatus::ACTIVE->value))
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->first();

        abort_if($release === null, 404);

        return $this->respond($plugin, $release);
    }

    public function release(Request $request, string $slug, int $release): StreamedResponse
    {
        $plugin = $this->find($slug);
        $reviewer = $this->reviewer($request);

        abort_unless($this->isPublic($plugin) || $reviewer, 404);

        $found = Models::class('release')::query()->whereKey($release)->where('plugin_id', $plugin->getKey())->first();
        abort_if($found === null, 404);
        $found->setRelation('plugin', $plugin);

        abort_unless($reviewer || $this->downloadableStatus($found), 404);
        $this->authorizeDownload($request, $reviewer, fn (?Authenticatable $user): bool => $this->access->canDownloadRelease($user, $found), $plugin);

        return $this->respond($plugin, $found);
    }

    private function find(string $slug): Plugin
    {
        $plugin = Models::class('plugin')::query()->with('type')->where('slug', $slug)->first();

        abort_if($plugin === null, 404);

        return $plugin;
    }

    /**
     * Whether the public may download a release in this status at all (archived ones while that is on).
     */
    private function downloadableStatus(PluginRelease $release): bool
    {
        return $release->status === PluginStatus::ACTIVE
            || ($release->status === PluginStatus::ARCHIVED && config('filagora-plugins.downloads.archived_releases', true));
    }

    /**
     * Staff review: a browser session, never a token, from someone who may moderate.
     */
    private function reviewer(Request $request): bool
    {
        if ($this->api->hasToken($request)) {
            return false;
        }

        $user = $this->api->user($request);

        return $user !== null && $this->checker->allows($user, Gates::key(Gates::MODERATE));
    }

    /**
     * @param  \Closure(?Authenticatable): bool  $allowed
     */
    private function authorizeDownload(Request $request, bool $reviewer, \Closure $allowed, Plugin $plugin): void
    {
        if ($reviewer) {
            return;
        }

        $user = $this->api->authorize($request, ApiAccess::DOWNLOAD);

        if ($allowed($user)) {
            return;
        }

        if ($user === null) {
            throw new AuthenticationException;
        }

        throw new HttpException(403, __('filagora-plugins::api.download_not_allowed', ['entitlement' => $this->access->requiredScope($plugin)]));
    }

    private function isPublic(Plugin $plugin): bool
    {
        return $plugin->status === PluginStatus::ACTIVE && $plugin->type->is_active;
    }

    private function respond(Plugin $plugin, PluginRelease $release): StreamedResponse
    {
        $disk = Storage::disk((string) config('filagora-plugins.storage.disk'));
        $path = ltrim($release->zip_path, '/');

        abort_if($path === '' || ! $disk->exists($path), 404, __('filagora-plugins::api.download_unavailable'));

        if ($this->isPublic($plugin) && $release->status === PluginStatus::ACTIVE) {
            $release->increment('download_count');
        }

        return $disk->download($path, $release->zip_filename ?: basename($path));
    }
}
