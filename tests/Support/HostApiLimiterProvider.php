<?php

declare(strict_types=1);

namespace Filagora\Plugins\Tests\Support;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

/**
 * Stands in for a host application that already defines one of the package's API limiters, so the
 * test can check the package leaves it alone. It must be registered before the plugins provider, so
 * that its `boot()` runs first.
 */
class HostApiLimiterProvider extends ServiceProvider
{
    public const READ_PER_MINUTE = 7;

    public function boot(): void
    {
        RateLimiter::for('filagora-plugins-api-read', fn (Request $request): Limit => Limit::perMinute(self::READ_PER_MINUTE));
    }
}
