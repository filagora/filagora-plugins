<?php

declare(strict_types=1);

namespace Filagora\Plugins\Tests\Routes;

use Filagora\Plugins\Api\ApiAccess;
use Filagora\Plugins\Contracts\ApiUsers;
use Filagora\Plugins\Tests\Support\FakeApiUsers;
use Filagora\Plugins\Tests\TestCase;

/**
 * The plugins test application with the optional API and download routes switched on, and bearer
 * tokens kept in memory.
 */
abstract class RoutesTestCase extends TestCase
{
    protected FakeApiUsers $tokens;

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('filagora-plugins.routes.api.enabled', true);
        $app['config']->set('filagora-plugins.routes.api.per_page', 2);
        $app['config']->set('filagora-plugins.routes.downloads.enabled', true);
        $app['config']->set('filagora-plugins.routes.downloads.middleware', ['web']);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->tokens = new FakeApiUsers;
        $this->app->instance(ApiUsers::class, $this->tokens);
        $this->app->forgetInstance(ApiAccess::class);
    }
}
