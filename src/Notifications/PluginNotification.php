<?php

declare(strict_types=1);

namespace Filagora\Plugins\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Route;

/**
 * One email for every plugin event the package sends. The words come from
 * `filagora-plugins::notifications.{kind}` (a `subject`, an `intro`, and an `action` for the link);
 * override the language file to reword them. The link goes to the route named in
 * `filagora-plugins.routes.plugin`, when there is one.
 */
final class PluginNotification extends Notification implements ShouldQueue
{
    use Queueable;

    /**
     * @param  array<string, scalar|null>  $replace  Values for the placeholders in the words.
     * @param  array<string, mixed>  $routeParameters  Parameters for the link's route.
     * @param  list<string>  $details  Extra lines under the intro, such as a note.
     */
    public function __construct(
        public readonly string $kind,
        public readonly array $replace = [],
        public readonly array $routeParameters = [],
        public readonly array $details = [],
    ) {}

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $mail = (new MailMessage)
            ->subject(__("filagora-plugins::notifications.{$this->kind}.subject", $this->replace))
            ->line(__("filagora-plugins::notifications.{$this->kind}.intro", $this->replace));

        foreach ($this->details as $detail) {
            if (trim($detail) !== '') {
                $mail->line($detail);
            }
        }

        $name = config('filagora-plugins.routes.plugin');

        return is_string($name) && $name !== '' && $this->routeParameters !== [] && Route::has($name)
            ? $mail->action(__("filagora-plugins::notifications.{$this->kind}.action"), route($name, $this->routeParameters))
            : $mail;
    }
}
