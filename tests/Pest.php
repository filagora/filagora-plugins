<?php

declare(strict_types=1);

use Filagora\Plugins\Tests\Forums\ForumsTestCase;
use Filagora\Plugins\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');
pest()->extend(ForumsTestCase::class)->use(RefreshDatabase::class)->in('Forums');
