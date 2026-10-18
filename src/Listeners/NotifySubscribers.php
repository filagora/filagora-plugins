<?php

declare(strict_types=1);

namespace Filagora\Plugins\Listeners;

use Filagora\Plugins\Events\ReleasePublished;
use Filagora\Plugins\Services\Subscriptions;

/**
 * When a release goes live, tell the people who follow the plugin, except its author.
 */
final class NotifySubscribers
{
    public function __construct(private readonly Subscriptions $subscriptions) {}

    public function handle(ReleasePublished $event): void
    {
        $this->subscriptions->notifyOfNewRelease($event->plugin, $event->release, $event->author);
    }
}
