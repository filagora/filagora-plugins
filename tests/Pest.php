<?php

declare(strict_types=1);

use Filagora\Plugins\Tests\Entitlements\EntitlementsTestCase;
use Filagora\Plugins\Tests\Forums\ForumsTestCase;
use Filagora\Plugins\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

require_once __DIR__.'/Support/Fixtures.php';

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(ForumsTestCase::class)->use(RefreshDatabase::class)->in('Forums');
pest()->extend(EntitlementsTestCase::class)->use(RefreshDatabase::class)->in('Entitlements');
