<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Whether someone who follows a plugin wants to hear about its new releases. The choice lives with
 * the host's notification settings, so the package asks; the default says yes to everyone.
 */
interface SubscriberPreferences
{
    public function wantsReleaseUpdates(Authenticatable $user): bool;
}
