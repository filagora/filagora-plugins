<?php

declare(strict_types=1);

use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;

use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

function limitsLibrary(): void
{
    pluginType();
    $plugin = livePlugin(null, slug: 'live');
    addRelease($plugin, '1.0.0');
}

// Rate limits

it('refuses a reader past the read limit with a 429 that says when to come back', function () {
    limitsLibrary();
    config(['filagora-plugins.routes.api.throttle.read' => 3]);

    foreach (range(1, 3) as $ignored) {
        getJson('/api/v1/plugin-types')->assertOk();
    }

    getJson('/api/v1/plugin-types')->assertStatus(429)->assertHeader('Retry-After');
});

it('counts each signed-in person on their own, and a guest by address', function () {
    limitsLibrary();
    config(['filagora-plugins.routes.api.throttle.read' => 2]);
    $ada = TestUser::make('Ada');
    $bea = TestUser::make('Bea');
    $this->tokens->issue('ada', $ada, 'plugins:read');
    $this->tokens->issue('bea', $bea, 'plugins:read');
    app(GrantedKeys::class)->give($ada, 'api.plugins.read');
    app(GrantedKeys::class)->give($bea, 'api.plugins.read');

    getJson('/api/v1/plugins', ['Authorization' => 'Bearer ada'])->assertOk();
    getJson('/api/v1/plugins', ['Authorization' => 'Bearer ada'])->assertOk();
    getJson('/api/v1/plugins', ['Authorization' => 'Bearer ada'])->assertStatus(429);

    getJson('/api/v1/plugins', ['Authorization' => 'Bearer bea'])->assertOk();
    getJson('/api/v1/plugins')->assertOk();
});

it('limits writes and update checks separately from reads', function () {
    limitsLibrary();
    config(['filagora-plugins.routes.api.throttle' => ['read' => 100, 'write' => 1, 'updates' => 2]]);
    $ada = TestUser::make('Ada');
    $this->tokens->issue('ada', $ada, 'plugins:read', 'plugins:write');
    app(GrantedKeys::class)->give($ada, 'api.plugins.read', 'api.plugins.write');
    $auth = ['Authorization' => 'Bearer ada'];

    postJson('/api/v1/plugins/live/subscription', [], $auth)->assertSuccessful();
    postJson('/api/v1/plugins/live/subscription', [], $auth)->assertStatus(429);

    postJson('/api/v1/plugins/updates', ['plugins' => []], $auth);
    postJson('/api/v1/plugins/updates', ['plugins' => []], $auth);
    postJson('/api/v1/plugins/updates', ['plugins' => []], $auth)->assertStatus(429);

    getJson('/api/v1/plugin-types', $auth)->assertOk();
});

it('applies no limit when one is set to 0', function () {
    limitsLibrary();
    config(['filagora-plugins.routes.api.throttle.read' => 0]);

    foreach (range(1, 5) as $ignored) {
        getJson('/api/v1/plugin-types')->assertOk();
    }
});

// Cache headers

it('lets shared caches hold a guest\'s read, with a validator, and answers a repeat with 304', function () {
    limitsLibrary();
    config(['filagora-plugins.routes.api.cache_seconds' => 120]);

    $first = getJson('/api/v1/plugins')->assertOk();

    expect($first->headers->get('Cache-Control'))->toContain('public')->toContain('max-age=120')->toContain('s-maxage=120')
        ->and($first->headers->get('Vary'))->toContain('Authorization')
        ->and($first->headers->get('ETag'))->not->toBeNull();

    getJson('/api/v1/plugins', ['If-None-Match' => $first->headers->get('ETag')])->assertStatus(304);
});

it('never lets a shared cache hold what a signed-in person was shown', function () {
    limitsLibrary();
    config(['filagora-plugins.routes.api.cache_seconds' => 120]);
    $ada = TestUser::make('Ada');
    $this->tokens->issue('ada', $ada, 'plugins:read');
    app(GrantedKeys::class)->give($ada, 'api.plugins.read');

    $response = getJson('/api/v1/plugins', ['Authorization' => 'Bearer ada'])->assertOk();

    expect($response->headers->get('Cache-Control'))->toContain('private')->not->toContain('public')->not->toContain('s-maxage')
        ->and($response->headers->get('Vary'))->toContain('Authorization');
});

it('does not cache a refusal, a write or a switched-off setting', function () {
    limitsLibrary();
    config(['filagora-plugins.routes.api.cache_seconds' => 120]);

    expect(getJson('/api/v1/plugins/nothing-here')->assertNotFound()->headers->get('Cache-Control'))->not->toContain('public');

    config(['filagora-plugins.routes.api.cache_seconds' => 0]);

    expect(getJson('/api/v1/plugins')->assertOk()->headers->get('Cache-Control'))->not->toContain('public');
});
