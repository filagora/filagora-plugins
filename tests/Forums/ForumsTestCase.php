<?php

declare(strict_types=1);

namespace Filagora\Plugins\Tests\Forums;

use Filagora\Forums\ForumsServiceProvider;
use Filagora\Plugins\Tests\TestCase;

/**
 * The plugins test application with forums installed too, for the support-thread link.
 */
abstract class ForumsTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), ForumsServiceProvider::class];
    }
}
