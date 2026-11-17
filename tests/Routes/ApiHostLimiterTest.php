<?php

declare(strict_types=1);

namespace Filagora\Plugins\Tests\Routes;

use Filagora\Plugins\PluginsServiceProvider;
use Filagora\Plugins\Tests\Support\HostApiLimiterProvider;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * A host that defines one of the API limiters before the package boots keeps its own; the limiters
 * it did not define still come from the package's config.
 */
class ApiHostLimiterTest extends RoutesTestCase
{
    protected function getPackageProviders($app): array
    {
        $providers = parent::getPackageProviders($app);
        $at = array_search(PluginsServiceProvider::class, $providers, true);

        array_splice($providers, $at, 0, [HostApiLimiterProvider::class]);

        return $providers;
    }

    public function test_a_limiter_the_host_defined_is_kept_and_the_others_come_from_the_package(): void
    {
        $this->assertSame(HostApiLimiterProvider::READ_PER_MINUTE, $this->limitFor('filagora-plugins-api-read')->maxAttempts);
        $this->assertSame(30, $this->limitFor('filagora-plugins-api-write')->maxAttempts);
        $this->assertSame(60, $this->limitFor('filagora-plugins-api-updates')->maxAttempts);
    }

    private function limitFor(string $limiter): Limit
    {
        $resolver = RateLimiter::limiter($limiter);

        $this->assertNotNull($resolver, "The {$limiter} limiter is not defined.");

        return $resolver(Request::create('/api/v1/plugins'));
    }
}
