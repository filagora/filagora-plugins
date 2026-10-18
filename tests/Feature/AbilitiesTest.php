<?php

declare(strict_types=1);

use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;
use Illuminate\Support\Facades\Gate;

it('defines an ability for each key, answered by the access checker', function () {
    $staff = TestUser::make('Staff');
    app(GrantedKeys::class)->give($staff, 'filagora.plugins.moderate');

    expect(Gate::forUser($staff)->allows('filagora.plugins.moderate'))->toBeTrue()
        ->and(Gate::forUser($staff)->allows('filagora.plugins.auto_publish'))->toBeFalse()
        ->and(Gate::forUser(TestUser::make('Other'))->allows('filagora.plugins.moderate'))->toBeFalse();
});

it('lists both keys in the configuration so a host can remap them', function () {
    expect(config('filagora-plugins.gates'))->toBe([
        'filagora.plugins.moderate' => 'filagora.plugins.moderate',
        'filagora.plugins.auto_publish' => 'filagora.plugins.auto_publish',
    ]);
});
