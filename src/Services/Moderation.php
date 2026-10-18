<?php

declare(strict_types=1);

namespace Filagora\Plugins\Services;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Plugins\Enums\ModerationAction;
use Filagora\Plugins\Enums\ModerationRefusal;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Events\PluginModerated;
use Filagora\Plugins\Events\ReleasePublished;
use Filagora\Plugins\Events\ReleaseRejected;
use Filagora\Plugins\Exceptions\ModerationRefused;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginModeratorNote;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Support\Gates;
use Filagora\Plugins\Support\Models;
use Filagora\Plugins\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * A moderator's decisions on plugins and releases. Every decision leaves a note on the plugin.
 */
class Moderation
{
    public function __construct(private readonly AccessChecker $access) {}

    /**
     * Approve a plugin, or reject it for good (which needs a note).
     *
     * @throws ModerationRefused
     */
    public function moderatePlugin(Authenticatable $moderator, Plugin $plugin, ModerationAction $action, ?string $note = null): void
    {
        $this->authorize($moderator);
        $note = $this->clean($note);
        $this->requireNoteToReject($action, $note);

        DB::transaction(function () use ($moderator, $plugin, $action, $note): void {
            $plugin->status = $action->status();
            $plugin->save();

            $this->addNote($plugin, $moderator, __("filagora-plugins::moderation.notes.plugin_{$this->word($action)}"), $note);
        });

        PluginModerated::dispatch($plugin, $action, $moderator, $note);
    }

    /**
     * Approve a release, or reject it for good (which needs a note). Approving the first release of
     * a plugin still awaiting review approves the plugin too; a plugin already rejected is left alone.
     *
     * @throws ModerationRefused
     */
    public function moderateRelease(Authenticatable $moderator, Plugin $plugin, PluginRelease $release, ModerationAction $action, ?string $note = null): void
    {
        $this->authorize($moderator);

        if ($release->plugin_id !== $plugin->getKey()) {
            throw new ModerationRefused(ModerationRefusal::RELEASE_MISMATCH);
        }

        $note = $this->clean($note);
        $this->requireNoteToReject($action, $note);

        DB::transaction(function () use ($moderator, $plugin, $release, $action, $note): void {
            $release->status = $action->status();
            $release->save();

            if ($action === ModerationAction::APPROVE && $plugin->status === PluginStatus::MODERATED) {
                $plugin->status = PluginStatus::ACTIVE;
                $plugin->save();
                $this->addNote($plugin, $moderator, __('filagora-plugins::moderation.notes.plugin_approved'), '');
            }

            $this->addNote(
                $plugin,
                $moderator,
                __("filagora-plugins::moderation.notes.release_{$this->word($action)}", ['version' => $release->displayVersion()]),
                $note,
            );
        });

        if ($action === ModerationAction::APPROVE) {
            ReleasePublished::dispatch($plugin, $release, Users::of($release->uploadedBy ?? $plugin->owner), $moderator);

            return;
        }

        ReleaseRejected::dispatch($plugin, $release, $note);
    }

    /**
     * Reject for good every release of the plugin still awaiting review, because `$uploader` has
     * just submitted `$newRelease` to replace them. Each gets a note attributed to the uploader.
     *
     * @return Collection<int, PluginRelease> The releases rejected.
     */
    public function supersedePending(Plugin $plugin, PluginRelease $newRelease, Authenticatable $uploader): Collection
    {
        $superseded = Models::class('release')::query()
            ->where('plugin_id', $plugin->getKey())
            ->where('status', PluginStatus::MODERATED->value)
            ->whereKeyNot($newRelease->getKey())
            ->get();

        $name = (string) (data_get($uploader, 'username') ?: data_get($uploader, 'name') ?: __('filagora-plugins::moderation.defaults.author'));
        $reason = __('filagora-plugins::releases.supersede.note', ['version' => $newRelease->displayVersion(), 'user' => $name]);

        foreach ($superseded as $release) {
            $release->status = PluginStatus::REJECTED_FINAL;
            $release->save();

            $this->addNote($plugin, $uploader, __('filagora-plugins::moderation.notes.release_superseded', ['version' => $release->displayVersion()]), $reason);

            ReleaseRejected::dispatch($plugin, $release, $reason, superseded: true, except: $uploader);
        }

        return $superseded;
    }

    /**
     * Leave a note on a plugin. The note is the summary line, then the moderator's own words.
     */
    public function addNote(Plugin $plugin, Authenticatable $moderator, string $summary, string $note = ''): PluginModeratorNote
    {
        return Models::class('moderator_note')::query()->create([
            'plugin_id' => $plugin->getKey(),
            'moderator_id' => $moderator->getAuthIdentifier(),
            'note' => trim($summary."\n\n".$note),
        ]);
    }

    private function authorize(Authenticatable $moderator): void
    {
        if (! $this->access->allows($moderator, Gates::key(Gates::MODERATE))) {
            throw new ModerationRefused(ModerationRefusal::NOT_PERMITTED);
        }
    }

    private function requireNoteToReject(ModerationAction $action, string $note): void
    {
        if ($action === ModerationAction::REJECT_PERMANENTLY && $note === '') {
            throw new ModerationRefused(ModerationRefusal::NOTE_REQUIRED);
        }
    }

    private function clean(?string $note): string
    {
        return trim((string) $note);
    }

    private function word(ModerationAction $action): string
    {
        return $action === ModerationAction::APPROVE ? 'approved' : 'rejected';
    }
}
