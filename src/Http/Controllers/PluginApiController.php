<?php

declare(strict_types=1);

namespace Filagora\Plugins\Http\Controllers;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Plugins\Api\ApiAccess;
use Filagora\Plugins\Api\DownloadLinks;
use Filagora\Plugins\Api\UpdateCheck;
use Filagora\Plugins\Contracts\ApiFields;
use Filagora\Plugins\Contracts\PluginIdentifiers;
use Filagora\Plugins\Enums\ModerationAction;
use Filagora\Plugins\Enums\ModerationRefusal;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Enums\ReleaseRefusal;
use Filagora\Plugins\Exceptions\ModerationRefused;
use Filagora\Plugins\Exceptions\ReleaseRefused;
use Filagora\Plugins\Http\Resources\PluginDetailResource;
use Filagora\Plugins\Http\Resources\PluginReleaseResource;
use Filagora\Plugins\Http\Resources\PluginSummaryResource;
use Filagora\Plugins\Http\Resources\PluginTypeResource;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Release\ReleaseInput;
use Filagora\Plugins\Services\Moderation;
use Filagora\Plugins\Services\Releases;
use Filagora\Plugins\Services\Subscriptions;
use Filagora\Plugins\Services\Visibility;
use Filagora\Plugins\Support\Gates;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * The optional JSON API (`routes.api.enabled`). Reading the catalogue, an update check, following a
 * plugin, and, for signed-in people, changing a plugin, uploading a release and (for staff) moderating
 * one. What anyone sees comes from `Visibility`; who may act from `ApiAccess` and the services.
 */
class PluginApiController extends Controller
{
    public function __construct(
        private readonly ApiAccess $api,
        private readonly Visibility $visibility,
        private readonly Releases $releases,
        private readonly Moderation $moderation,
        private readonly Subscriptions $subscriptions,
        private readonly UpdateCheck $updates,
        private readonly DownloadLinks $downloads,
        private readonly PluginIdentifiers $identifiers,
        private readonly ApiFields $fields,
        private readonly AccessChecker $access,
    ) {}

    public function types(Request $request): AnonymousResourceCollection
    {
        $user = $this->api->authorize($request, ApiAccess::READ);

        return PluginTypeResource::collection($this->visibility->types($user)->get());
    }

    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:255'],
            'type' => ['nullable', 'string', 'max:255'],
            'page' => ['nullable', 'integer', 'min:1'],
            'visibility' => ['nullable', 'in:public,owner,staff'],
        ]);

        $user = $this->api->authorize($request, ApiAccess::READ);
        $type = trim((string) ($validated['type'] ?? '')) ?: null;
        $search = trim((string) ($validated['q'] ?? ''));
        $audience = (string) ($validated['visibility'] ?? 'public');

        $query = $this->guarded(fn () => match (true) {
            $audience === 'staff' && $user !== null => $this->visibility->staffPlugins($user, $type, $search),
            $audience === 'owner' && $user !== null => $this->visibility->ownerPlugins($user, $type, $search),
            default => $this->visibility->publicPlugins($user, $type, $search),
        });

        return PluginSummaryResource::collection($query->paginate((int) config('filagora-plugins.routes.api.per_page', 15))->withQueryString());
    }

    public function show(Request $request, string $slug): PluginDetailResource
    {
        [$plugin] = $this->resolve($request, $slug);

        return new PluginDetailResource($plugin);
    }

    public function releases(Request $request, string $slug): AnonymousResourceCollection
    {
        [$plugin, $user, $audience] = $this->resolve($request, $slug);

        return PluginReleaseResource::collection($this->guarded(fn () => match (true) {
            $audience === 'staff' && $user !== null => $this->visibility->staffReleases($plugin, $user),
            $audience === 'owner' && $user !== null => $this->visibility->ownerReleases($plugin, $user),
            default => $this->visibility->publicReleases($plugin),
        })->get());
    }

    public function release(Request $request, string $slug, string $version): PluginReleaseResource
    {
        [, $release] = $this->resolveRelease($request, $slug, $version);

        return new PluginReleaseResource($release);
    }

    public function downloadMetadata(Request $request, string $slug, string $version): JsonResponse
    {
        [$plugin, $release] = $this->resolveRelease($request, $slug, $version);

        return response()->json(['data' => [
            'plugin_slug' => $plugin->slug,
            'plugin_name' => $plugin->name,
            'version' => $release->version,
            'status' => $release->status->value,
            'zip_filename' => (string) $release->zip_filename,
            'zip_size' => $release->zip_size,
            'checksum_sha256' => (string) $release->checksum_sha256,
            'download_count' => $release->download_count,
            'download_url' => $this->downloads->release($request, $release),
            'published_at' => ($release->created_at ?? $release->updated_at)?->toISOString(),
            'updated_at' => $release->updated_at?->toISOString(),
            ...$this->fields->releaseFields($release),
        ]]);
    }

    public function updates(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target' => ['nullable', 'string', 'max:255'],
            'plugins' => ['required', 'array', 'min:1'],
            'plugins.*' => ['array'],
            'plugins.*.version' => ['required', 'string', 'max:255'],
        ]);

        foreach ($request->input('plugins', []) as $index => $row) {
            if (! is_array($row) || ! $this->identifiers->identifies($row)) {
                throw ValidationException::withMessages(["plugins.{$index}.slug" => __('filagora-plugins::api.plugin_not_identified')]);
            }
        }

        $user = $this->api->authorize($request, ApiAccess::READ);
        $target = trim((string) ($validated['target'] ?? '')) ?: null;

        return response()->json([
            'data' => $this->updates->rows($request, $user, $request->input('plugins', []), $target),
            'meta' => ['target' => $target],
        ]);
    }

    public function update(Request $request, string $slug): JsonResponse
    {
        $user = $this->api->requireVerifiedUser($request, ApiAccess::WRITE);
        $plugin = $this->visibility->findOwnerPlugin($slug, $user);

        abort_if($plugin === null, 404);
        abort_unless($this->ownsOrModerates($user, $plugin), 403);

        $threads = Schema::hasTable('threads') ? ['exists:threads,id'] : [];

        $plugin->fill($request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:180'],
            'description' => ['sometimes', 'nullable', 'string'],
            'github_repository_url' => ['sometimes', 'nullable', 'url', 'max:500'],
            'forum_thread_id' => ['sometimes', 'nullable', 'integer', 'min:1', ...$threads],
        ]))->save();

        $fresh = $this->visibility->findOwnerPlugin($slug, $user);

        abort_if($fresh === null, 404);

        return response()->json([
            'data' => (new PluginDetailResource($fresh))->resolve($request),
            'meta' => ['message' => __('filagora-plugins::api.plugin_updated')],
        ]);
    }

    public function subscription(Request $request, string $slug): JsonResponse
    {
        $user = $this->api->requireVerifiedUser($request, ApiAccess::READ);
        $plugin = $this->publicPlugin($slug, $user);

        return $this->subscribed($plugin, $this->subscriptions->isSubscribed($user, $plugin));
    }

    public function subscribe(Request $request, string $slug): JsonResponse
    {
        $user = $this->api->requireVerifiedUser($request, ApiAccess::WRITE);
        $plugin = $this->publicPlugin($slug, $user);
        $this->subscriptions->subscribe($user, $plugin);

        return $this->subscribed($plugin, true);
    }

    public function unsubscribe(Request $request, string $slug): JsonResponse
    {
        $user = $this->api->requireVerifiedUser($request, ApiAccess::WRITE);
        $plugin = $this->publicPlugin($slug, $user);
        $this->subscriptions->unsubscribe($user, $plugin);

        return $this->subscribed($plugin, false);
    }

    public function storeRelease(Request $request, string $slug): JsonResponse
    {
        $user = $this->api->requireVerifiedUser($request, ApiAccess::WRITE);
        $plugin = $this->publicPlugin($slug, $user);
        $extraRules = $this->fields->releaseRules();

        $validated = $request->validate([
            'version' => ['required', 'string', 'max:40'],
            'zip_file' => ['required', 'file', 'mimes:zip', 'max:'.(int) config('filagora-plugins.routes.api.max_upload_kb', 102400)],
            'changelog' => ['nullable', 'string'],
            'supersede_pending_releases' => ['sometimes', 'boolean'],
            ...$extraRules,
        ]);

        $superseded = $this->releases->pendingFor($plugin)->map(static fn (PluginRelease $pending): string => $pending->displayVersion())->values()->all();
        $status = $this->releases->statusFor($user);

        try {
            $release = $this->releases->submitRelease($user, $plugin, new ReleaseInput(
                version: (string) $validated['version'],
                file: $request->file('zip_file'),
                changelog: $validated['changelog'] ?? null,
                supersedePending: $request->boolean('supersede_pending_releases'),
                extra: array_intersect_key($validated, $extraRules),
            ));
        } catch (ReleaseRefused $refused) {
            $this->refuse($refused);
        }

        $release->load(['plugin.type']);

        return response()->json([
            'data' => (new PluginReleaseResource($release))->resolve($request),
            'meta' => [
                'plugin_slug' => $plugin->slug,
                'superseded_versions' => $superseded,
                'message' => $status === PluginStatus::ACTIVE ? __('filagora-plugins::api.release_live') : __('filagora-plugins::api.release_pending_review'),
            ],
        ], 201);
    }

    public function moderateRelease(Request $request, string $slug, string $version): JsonResponse
    {
        $user = $this->api->requireVerifiedUser($request, ApiAccess::WRITE);

        $plugin = $this->guarded(fn () => $this->visibility->findStaffPlugin($slug, $user));
        abort_if($plugin === null, 404);

        $release = $this->guarded(fn () => $this->visibility->findStaffRelease($plugin, $user, $version));
        abort_if($release === null, 404);

        $validated = $request->validate([
            'action' => ['required', Rule::enum(ModerationAction::class)],
            'note' => ['nullable', 'string', 'required_if:action,reject_permanently'],
        ]);

        try {
            $this->moderation->moderateRelease($user, $plugin, $release, ModerationAction::from($validated['action']), $validated['note'] ?? null);
        } catch (ModerationRefused $refused) {
            $refused->reason === ModerationRefusal::NOT_PERMITTED
                ? abort(403, $refused->getMessage())
                : throw ValidationException::withMessages(['note' => $refused->getMessage()]);
        }

        return response()->json([
            'data' => (new PluginReleaseResource($release->refresh()->load('plugin.type')))->resolve($request),
            'meta' => ['plugin_slug' => $plugin->slug],
        ]);
    }

    /**
     * The plugin the request asks for, in the audience it asked for, with who is asking.
     *
     * @return array{0: Plugin, 1: Authenticatable|null, 2: string}
     */
    private function resolve(Request $request, string $slug): array
    {
        $audience = (string) ($request->validate(['visibility' => ['nullable', 'in:public,owner,staff']])['visibility'] ?? 'public');
        $user = $this->api->authorize($request, ApiAccess::READ);

        $plugin = $this->guarded(fn () => match (true) {
            $audience === 'staff' && $user !== null => $this->visibility->findStaffPlugin($slug, $user),
            $audience === 'owner' && $user !== null => $this->visibility->findOwnerPlugin($slug, $user),
            default => $this->visibility->findPublicPlugin($slug, $user),
        });

        abort_if($plugin === null, 404);

        return [$plugin, $user, $audience];
    }

    /**
     * @return array{0: Plugin, 1: PluginRelease}
     */
    private function resolveRelease(Request $request, string $slug, string $version): array
    {
        [$plugin, $user, $audience] = $this->resolve($request, $slug);

        $release = $this->guarded(fn () => match (true) {
            $audience === 'staff' && $user !== null => $this->visibility->findStaffRelease($plugin, $user, $version),
            $audience === 'owner' && $user !== null => $this->visibility->findOwnerRelease($plugin, $user, $version),
            default => $this->visibility->findPublicRelease($plugin, $version),
        });

        abort_if($release === null, 404);

        return [$plugin, $release];
    }

    private function publicPlugin(string $slug, Authenticatable $user): Plugin
    {
        $plugin = $this->visibility->findPublicPlugin($slug, $user);

        abort_if($plugin === null, 404);

        return $plugin;
    }

    private function ownsOrModerates(Authenticatable $user, Plugin $plugin): bool
    {
        return ($plugin->owner_id !== null && $plugin->owner_id === $user->getAuthIdentifier())
            || $this->access->allows($user, Gates::key(Gates::MODERATE));
    }

    private function subscribed(Plugin $plugin, bool $subscribed): JsonResponse
    {
        return response()->json(['data' => ['plugin_slug' => $plugin->slug, 'subscribed' => $subscribed]]);
    }

    /**
     * Turn a refused staff view into a 403.
     *
     * @template T
     *
     * @param  \Closure(): T  $work
     * @return T
     */
    private function guarded(\Closure $work): mixed
    {
        try {
            return $work();
        } catch (ModerationRefused) {
            abort(403);
        }
    }

    private function refuse(ReleaseRefused $refused): never
    {
        if ($refused->reason === ReleaseRefusal::SUBMISSIONS_PAUSED) {
            throw new HttpException(503, $refused->getMessage());
        }

        throw ValidationException::withMessages([$refused->field() => $refused->getMessage()]);
    }
}
