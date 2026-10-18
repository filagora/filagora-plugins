<?php

declare(strict_types=1);

namespace Filagora\Plugins\Listeners;

use Filagora\Plugins\Enums\ModerationAction;
use Filagora\Plugins\Events\PluginModerated;
use Filagora\Plugins\Events\PluginReported;
use Filagora\Plugins\Events\PluginReportReviewed;
use Filagora\Plugins\Events\ReleasePublished;
use Filagora\Plugins\Events\ReleaseRejected;
use Filagora\Plugins\Events\ReleaseSubmitted;
use Filagora\Plugins\Events\SubscribersToNotify;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Notifications\PluginNotification;
use Filagora\Plugins\Support\Users;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Notification;

/**
 * The default emails for plugin events. Silent when `filagora-plugins.mail.enabled` is off, for a
 * host that sends its own from the same events.
 */
final class SendPluginMail
{
    public function submitted(ReleaseSubmitted $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $replace = $this->replace($event->plugin, $event->release);
        $live = $event->release->status->value === 'active';
        $kind = $live ? 'submitted_live' : 'submitted';

        if (config('filagora-plugins.mail.acknowledge_submissions', true)) {
            Notification::send($event->submittedBy, new PluginNotification($kind, $replace, $this->link($event->plugin)));
        }

        if ($this->supportEmail() !== null) {
            Notification::route('mail', $this->supportEmail())
                ->notify(new PluginNotification('submitted_support', $replace + ['user' => $this->name($event->submittedBy)], $this->link($event->plugin)));
        }
    }

    public function published(ReleasePublished $event): void
    {
        if ($this->enabled() && $event->moderator !== null && $event->author !== null) {
            Notification::send($event->author, new PluginNotification('release_approved', $this->replace($event->plugin, $event->release), $this->link($event->plugin)));
        }
    }

    public function rejected(ReleaseRejected $event): void
    {
        if (! $this->enabled()) {
            return;
        }

        $author = $event->release->uploadedBy ?? $event->plugin->owner;

        if ($author === null || ($event->except !== null && $author->getKey() === $event->except->getAuthIdentifier())) {
            return;
        }

        Notification::send($author, new PluginNotification('release_rejected', $this->replace($event->plugin, $event->release), $this->link($event->plugin), [$event->note]));
    }

    public function moderated(PluginModerated $event): void
    {
        $owner = $event->plugin->owner;

        if (! $this->enabled() || $owner === null) {
            return;
        }

        $kind = $event->action === ModerationAction::APPROVE ? 'plugin_approved' : 'plugin_rejected';

        Notification::send($owner, new PluginNotification($kind, ['plugin' => $event->plugin->name], $this->link($event->plugin), [$event->note]));
    }

    public function subscribers(SubscribersToNotify $event): void
    {
        if ($this->enabled() && $event->recipients !== []) {
            Notification::send($event->recipients, new PluginNotification('release_updated', $this->replace($event->plugin, $event->release), $this->link($event->plugin)));
        }
    }

    public function reported(PluginReported $event): void
    {
        if (! $this->enabled() || $this->supportEmail() === null) {
            return;
        }

        $report = $event->report;
        $plugin = $report->plugin;

        Notification::route('mail', $this->supportEmail())
            ->notify(new PluginNotification('report_submitted', ['plugin' => $plugin->name, 'user' => $this->name(Users::of($report->reporter))], $this->link($plugin), [$report->reason]));
    }

    public function reviewed(PluginReportReviewed $event): void
    {
        $report = $event->report;

        if (! $this->enabled() || $report->reporter === null) {
            return;
        }

        Notification::send($report->reporter, new PluginNotification(
            'report_reviewed',
            ['plugin' => $report->plugin->name, 'status' => $report->status->label()],
            $this->link($report->plugin),
            [(string) $report->resolution_note],
        ));
    }

    private function enabled(): bool
    {
        return (bool) config('filagora-plugins.mail.enabled', true);
    }

    private function supportEmail(): ?string
    {
        $email = trim((string) config('filagora-plugins.mail.support_email'));

        return filter_var($email, FILTER_VALIDATE_EMAIL) !== false ? $email : null;
    }

    /**
     * @return array<string, scalar|null>
     */
    private function replace(Plugin $plugin, PluginRelease $release): array
    {
        return ['plugin' => $plugin->name, 'version' => $release->displayVersion()];
    }

    /**
     * @return array<string, Plugin>
     */
    private function link(Plugin $plugin): array
    {
        return ['plugin' => $plugin];
    }

    private function name(?Authenticatable $user): string
    {
        return (string) (data_get($user, 'username') ?: data_get($user, 'name') ?: __('filagora-plugins::moderation.defaults.author'));
    }
}
