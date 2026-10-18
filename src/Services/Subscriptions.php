<?php

declare(strict_types=1);

namespace Filagora\Plugins\Services;

use Filagora\Plugins\Contracts\SubscriberPreferences;
use Filagora\Plugins\Events\SubscribersToNotify;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginSubscription;
use Filagora\Plugins\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Following a plugin, and telling its followers about a new release.
 */
class Subscriptions
{
    public function __construct(private readonly SubscriberPreferences $preferences) {}

    public function subscribe(Authenticatable $user, Plugin $plugin): PluginSubscription
    {
        return Models::class('subscription')::query()->firstOrCreate([
            'plugin_id' => $plugin->getKey(),
            'user_id' => $user->getAuthIdentifier(),
        ]);
    }

    public function unsubscribe(Authenticatable $user, Plugin $plugin): void
    {
        Models::class('subscription')::query()
            ->where('plugin_id', $plugin->getKey())
            ->where('user_id', $user->getAuthIdentifier())
            ->delete();
    }

    public function isSubscribed(Authenticatable $user, Plugin $plugin): bool
    {
        return Models::class('subscription')::query()
            ->where('plugin_id', $plugin->getKey())
            ->where('user_id', $user->getAuthIdentifier())
            ->exists();
    }

    /**
     * Tell the followers who want to hear, leaving out `$except` (the person who just published).
     */
    public function notifyOfNewRelease(Plugin $plugin, PluginRelease $release, ?Authenticatable $except = null): void
    {
        $query = $plugin->subscribedUsers();

        if ($except !== null) {
            $query->whereKeyNot($except->getAuthIdentifier());
        }

        $recipients = [];

        foreach ($query->get() as $user) {
            if ($user instanceof Authenticatable && $this->preferences->wantsReleaseUpdates($user)) {
                $recipients[] = $user;
            }
        }

        if ($recipients !== []) {
            SubscribersToNotify::dispatch($plugin, $release, $recipients);
        }
    }
}
