<?php

declare(strict_types=1);

namespace Filagora\Plugins\Api;

use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Contracts\ApiFields;
use Filagora\Plugins\Contracts\Compatibility;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginType;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/**
 * The JSON the API answers with, in one place. A profile package adds fields through `ApiFields`.
 */
class ApiPayloads
{
    public function __construct(
        private readonly DownloadLinks $downloads,
        private readonly Compatibility $compatibility,
        private readonly ApiFields $fields,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function type(PluginType $type): array
    {
        return [
            'id' => (int) $type->getKey(),
            'name' => $type->name,
            'slug' => $type->slug,
            'description' => (string) $type->description,
            'position' => $type->position,
            'requires_entitlement' => filled($type->required_entitlement_scope),
            'active_plugins_count' => (int) ($type->getAttribute('active_plugins_count') ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function plugin(Plugin $plugin, Request $request): array
    {
        $latest = $plugin->relationLoaded('releases') ? $plugin->releases->first() : null;
        $owner = $plugin->owner;
        $title = UserModel::titleAttribute();

        return [
            'id' => (int) $plugin->getKey(),
            'name' => $plugin->name,
            'slug' => $plugin->slug,
            'status' => $plugin->status->value,
            'description' => (string) $plugin->description,
            'github_repository_url' => (string) $plugin->github_repository_url,
            'active_releases_count' => (int) ($plugin->getAttribute('active_releases_count') ?? 0),
            'type' => [
                'id' => (int) $plugin->type->getKey(),
                'name' => $plugin->type->name,
                'slug' => $plugin->type->slug,
            ],
            'owner' => $owner === null ? null : ['id' => (int) $owner->getKey(), 'name' => (string) $owner->getAttribute($title)],
            'forum_thread' => $plugin->forum_thread_id === null ? null : ['id' => (int) $plugin->forum_thread_id, 'url' => $this->url('thread', $plugin->forum_thread_id)],
            'latest_release' => $latest === null ? null : [
                'id' => (int) $latest->getKey(),
                'version' => $latest->version,
                'status' => $latest->status->value,
                'download_url' => $this->downloads->release($request, $latest),
                'created_at' => $latest->created_at?->toISOString(),
            ],
            'url' => $this->url('plugin', $plugin->slug),
            'download_url' => $latest === null ? null : $this->downloads->plugin($request, $plugin),
            'created_at' => $plugin->created_at?->toISOString(),
            'updated_at' => $plugin->updated_at?->toISOString(),
            ...$this->fields->pluginFields($plugin),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function release(PluginRelease $release, Request $request): array
    {
        return [
            'id' => (int) $release->getKey(),
            'version' => $release->version,
            'status' => $release->status->value,
            'internal_version' => $release->internal_version,
            'changelog' => (string) $release->changelog,
            'zip_filename' => (string) $release->zip_filename,
            'zip_size' => $release->zip_size,
            'checksum_sha256' => (string) $release->checksum_sha256,
            'plugin_slug' => $release->plugin->slug,
            'download_url' => $this->downloads->release($request, $release),
            'compatibility' => $this->compatibility->targets($release),
            'created_at' => $release->created_at?->toISOString(),
            'updated_at' => $release->updated_at?->toISOString(),
            ...$this->fields->releaseFields($release),
        ];
    }

    /**
     * The link to a page of the host's, when it named a route for it in `routes`.
     */
    private function url(string $key, int|string $parameter): ?string
    {
        $name = config("filagora-plugins.routes.{$key}");

        if (! is_string($name) || $name === '' || ! Route::has($name)) {
            return null;
        }

        return route($name, [$key === 'thread' ? 'thread' : 'plugin' => $parameter]);
    }
}
