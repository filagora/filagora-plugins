<?php

declare(strict_types=1);

namespace Filagora\Plugins\Services;

use Filagora\Plugins\Contracts\ArchiveInspector;
use Filagora\Plugins\Contracts\SubmissionPolicy;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Enums\ReleaseRefusal;
use Filagora\Plugins\Events\PluginSubmitted;
use Filagora\Plugins\Events\ReleasePublished;
use Filagora\Plugins\Events\ReleaseSubmitted;
use Filagora\Plugins\Exceptions\ReleaseRefused;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Release\ReleaseExtensions;
use Filagora\Plugins\Release\ReleaseInput;
use Filagora\Plugins\Release\ReleaseStorage;
use Filagora\Plugins\Support\Models;
use Filagora\Plugins\Support\PluginVersion;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Submitting a new plugin with its first release, and a new release of a plugin that exists.
 * Someone who may publish without review goes live at once; everyone else waits for a moderator.
 */
class Releases
{
    public function __construct(
        private readonly SubmissionPolicy $policy,
        private readonly ArchiveInspector $inspector,
        private readonly ReleaseStorage $storage,
        private readonly ReleaseExtensions $extensions,
        private readonly Moderation $moderation,
    ) {}

    /**
     * Submit a new plugin with its first release.
     *
     * @param  array{name: string, plugin_type_id: int, description?: string|null, forum_thread_id?: int|null, github_repository_url?: string|null}  $attributes
     *
     * @throws ReleaseRefused
     */
    public function submitPlugin(Authenticatable $user, array $attributes, ReleaseInput $input): PluginRelease
    {
        $this->assertOpen();

        if (! Models::class('type')::query()->whereKey($attributes['plugin_type_id'])->exists()) {
            throw new ReleaseRefused(ReleaseRefusal::UNKNOWN_TYPE);
        }

        $version = $this->validateVersion(null, $input->version);
        $this->inspector->assertSafe($input->file->getPathname());
        $extra = $this->extensions->prepare(null, $input, $version);
        $status = $this->statusFor($user);

        $release = $this->persist(function () use ($user, $attributes, $input, $version, $extra, $status): PluginRelease {
            $plugin = Models::class('plugin')::query()->create([
                'name' => $attributes['name'],
                'slug' => $this->uniqueSlug($attributes['name']),
                'plugin_type_id' => $attributes['plugin_type_id'],
                'description' => $attributes['description'] ?? null,
                'forum_thread_id' => $attributes['forum_thread_id'] ?? null,
                'github_repository_url' => $attributes['github_repository_url'] ?? null,
                'owner_id' => $user->getAuthIdentifier(),
                'status' => $status,
            ]);

            return $this->createRelease($plugin, $user, $input, $version, $extra, $status);
        });

        $plugin = $release->plugin;
        PluginSubmitted::dispatch($plugin, $release, $user);
        $this->announce($plugin, $release, $user, $status);

        return $release;
    }

    /**
     * Submit a new release of an existing plugin. When releases of it are still awaiting review, the
     * submitter must agree they are replaced (`supersedePending`); they are then rejected.
     *
     * @throws ReleaseRefused
     */
    public function submitRelease(Authenticatable $user, Plugin $plugin, ReleaseInput $input): PluginRelease
    {
        $this->assertOpen();

        $pending = $this->pendingFor($plugin);

        if ($pending->isNotEmpty() && ! $input->supersedePending) {
            throw new ReleaseRefused(
                ReleaseRefusal::PENDING_RELEASES,
                trans_choice('filagora-plugins::releases.supersede.required', $pending->count(), ['versions' => $this->versionList($pending)]),
            );
        }

        $version = $this->validateVersion($plugin, $input->version);
        $this->inspector->assertSafe($input->file->getPathname());
        $extra = $this->extensions->prepare($plugin, $input, $version);
        $status = $this->statusFor($user);

        $release = $this->persist(function () use ($plugin, $user, $input, $version, $extra, $status, $pending): PluginRelease {
            $release = $this->createRelease($plugin, $user, $input, $version, $extra, $status);

            if ($pending->isNotEmpty()) {
                $this->moderation->supersedePending($plugin, $release, $user);
            }

            return $release;
        });

        $this->announce($plugin, $release, $user, $status);

        return $release;
    }

    /**
     * Check a typed version and answer the form it is stored in. Pass the plugin to refuse a version
     * it already has under another spelling ("1.0.1" and "v1.0.1"); pass the id of a release being
     * edited to leave it out of that check.
     *
     * @throws ReleaseRefused
     */
    public function validateVersion(?Plugin $plugin, string $raw, ?int $ignoringReleaseId = null): string
    {
        $trimmed = trim($raw);

        if (! PluginVersion::isValidSubmission($trimmed)) {
            throw new ReleaseRefused(ReleaseRefusal::INVALID_VERSION);
        }

        $canonical = PluginVersion::canonical($trimmed) ?? $trimmed;

        if ($plugin !== null && $this->hasVersion($plugin, $canonical, $ignoringReleaseId)) {
            throw new ReleaseRefused(ReleaseRefusal::DUPLICATE_VERSION);
        }

        return $canonical;
    }

    /**
     * Releases of the plugin still awaiting review, oldest first.
     *
     * @return Collection<int, PluginRelease>
     */
    public function pendingFor(Plugin $plugin): Collection
    {
        return Models::class('release')::query()
            ->where('plugin_id', $plugin->getKey())
            ->where('status', PluginStatus::MODERATED->value)
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();
    }

    public function statusFor(?Authenticatable $user): PluginStatus
    {
        return $this->policy->bypassesModeration($user) ? PluginStatus::ACTIVE : PluginStatus::MODERATED;
    }

    /**
     * "v1.0.1, v1.0.2" for a message that names releases.
     *
     * @param  Collection<int, PluginRelease>  $releases
     */
    public function versionList(Collection $releases): string
    {
        return $releases->map(static fn (PluginRelease $release): string => 'v'.$release->displayVersion())->implode(', ');
    }

    private function assertOpen(): void
    {
        if (! config('filagora-plugins.submissions_enabled', true)) {
            throw new ReleaseRefused(ReleaseRefusal::SUBMISSIONS_PAUSED);
        }
    }

    private function hasVersion(Plugin $plugin, string $canonical, ?int $ignoringReleaseId): bool
    {
        return $plugin->releases()
            ->when($ignoringReleaseId !== null, fn ($query) => $query->whereKeyNot($ignoringReleaseId))
            ->get(['id', 'version'])
            ->contains(fn (PluginRelease $release): bool => PluginVersion::canonical($release->version) === $canonical);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function createRelease(Plugin $plugin, Authenticatable $user, ReleaseInput $input, string $version, array $extra, PluginStatus $status): PluginRelease
    {
        $stored = $this->storage->store((string) $plugin->slug, $version, $input->file);

        try {
            $release = Models::class('release')::query()->create([
                ...$extra,
                'plugin_id' => $plugin->getKey(),
                'uploaded_by_id' => $user->getAuthIdentifier(),
                'version' => $version,
                'internal_version' => null,
                'zip_path' => $stored['path'],
                'zip_filename' => $stored['originalFilename'],
                'changelog' => $input->changelog,
                'status' => $status,
            ]);

            $this->extensions->created($plugin, $release, $input);
        } catch (Throwable $e) {
            Storage::disk((string) config('filagora-plugins.storage.disk'))->delete($stored['path']);

            throw $e;
        }

        return $release->refresh();
    }

    /**
     * @param  \Closure(): PluginRelease  $work
     */
    private function persist(\Closure $work): PluginRelease
    {
        return DB::transaction($work);
    }

    private function announce(Plugin $plugin, PluginRelease $release, Authenticatable $user, PluginStatus $status): void
    {
        ReleaseSubmitted::dispatch($plugin, $release, $user);

        if ($status === PluginStatus::ACTIVE) {
            ReleasePublished::dispatch($plugin, $release, $user);
        }
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'plugin';
        $slug = $base;

        for ($suffix = 1; Models::class('plugin')::query()->where('slug', $slug)->exists(); $suffix++) {
            $slug = sprintf('%s-%d', $base, $suffix);
        }

        return $slug;
    }
}
