<?php

declare(strict_types=1);

namespace Filagora\Plugins\Tests\Entitlements;

use Filagora\Entitlements\EntitlementsServiceProvider;
use Filagora\Plugins\Tests\TestCase;

/**
 * The plugins test application with entitlements installed too, for the definitions the package
 * declares and the type form's entitlement picker.
 */
abstract class EntitlementsTestCase extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), EntitlementsServiceProvider::class];
    }
}
