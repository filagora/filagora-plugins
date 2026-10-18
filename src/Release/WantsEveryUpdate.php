<?php

declare(strict_types=1);

namespace Filagora\Plugins\Release;

use Filagora\Plugins\Contracts\SubscriberPreferences;
use Illuminate\Contracts\Auth\Authenticatable;

final class WantsEveryUpdate implements SubscriberPreferences
{
    public function wantsReleaseUpdates(Authenticatable $user): bool
    {
        return true;
    }
}
