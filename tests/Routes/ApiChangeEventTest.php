<?php

declare(strict_types=1);

use Filagora\Plugins\Api\ApiAccess;
use Filagora\Plugins\Api\GuardApiUsers;
use Filagora\Plugins\Contracts\ApiUsers;
use Filagora\Plugins\Enums\ApiChange;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Events\ApiChangeMade;
use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;
use Filagora\Plugins\Tests\Support\Zips;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

function changeLibrary(): TestUser
{
    pluginType();
    $owner = TestUser::make('Owner');
    livePlugin($owner, PluginStatus::ACTIVE, 'live');

    return $owner;
}

/**
 * A person with a token that may write, with the capability key the token's owner needs.
 */
function writer(string $token, ?TestUser $user = null, string ...$moreKeys): TestUser
{
    $user ??= TestUser::make('Writer');
    app(ApiUsers::class)->issue($token, $user, 'plugins:read', 'plugins:write');
    // Granting replaces what the person had, so any other key they need is named here.
    app(GrantedKeys::class)->give($user, 'api.plugins.read', 'api.plugins.write', ...$moreKeys);

    return $user;
}

it('says so, naming the token, when a plugin is changed', function () {
    Event::fake([ApiChangeMade::class]);
    $owner = writer('owner-key', changeLibrary());

    patchJson('/api/v1/plugins/live', ['name' => 'Renamed', 'description' => 'Words'], ['Authorization' => 'Bearer owner-key'])->assertOk();

    Event::assertDispatchedTimes(ApiChangeMade::class, 1);
    Event::assertDispatched(fn (ApiChangeMade $event): bool => $event->change === ApiChange::PLUGIN_UPDATED
        && $event->pluginSlug === 'live'
        && $event->tokenId === 'owner-key'
        && $event->user->getAuthIdentifier() === $owner->getAuthIdentifier()
        && $event->context === ['fields' => ['name', 'description']]
        && $event->ip !== null);
});

it('says so when a release is uploaded, and when one is moderated', function () {
    Storage::fake('local');
    Event::fake([ApiChangeMade::class]);
    changeLibrary();
    $uploader = writer('up-key');

    post('/api/v1/plugins/live/releases', ['version' => '2.0.0', 'zip_file' => Zips::make()], ['Accept' => 'application/json', 'Authorization' => 'Bearer up-key'])->assertCreated();

    Event::assertDispatched(fn (ApiChangeMade $event): bool => $event->change === ApiChange::RELEASE_UPLOADED
        && $event->version === 'v2.0.0'
        && $event->tokenId === 'up-key'
        && $event->user->getAuthIdentifier() === $uploader->getAuthIdentifier());

    $staff = writer('mod-key', null, 'filagora.plugins.moderate');

    // An uploaded release is stored with its canonical version, `v2.0.0`.
    postJson('/api/v1/plugins/live/releases/v2.0.0/moderate', ['action' => 'approve'], ['Authorization' => 'Bearer mod-key'])->assertOk();

    Event::assertDispatched(fn (ApiChangeMade $event): bool => $event->change === ApiChange::RELEASE_MODERATED
        && $event->version === 'v2.0.0'
        && $event->context === ['action' => 'approve']
        && $event->tokenId === 'mod-key'
        && $event->user->getAuthIdentifier() === $staff->getAuthIdentifier());
});

it('says so when someone follows a plugin and when they stop', function () {
    Event::fake([ApiChangeMade::class]);
    changeLibrary();
    writer('fan-key');
    $auth = ['Authorization' => 'Bearer fan-key'];

    postJson('/api/v1/plugins/live/subscription', [], $auth)->assertOk();
    deleteJson('/api/v1/plugins/live/subscription', [], $auth)->assertOk();

    Event::assertDispatchedTimes(ApiChangeMade::class, 2);
    Event::assertDispatched(fn (ApiChangeMade $event): bool => $event->change === ApiChange::PLUGIN_FOLLOWED && $event->tokenId === 'fan-key');
    Event::assertDispatched(fn (ApiChangeMade $event): bool => $event->change === ApiChange::PLUGIN_UNFOLLOWED && $event->tokenId === 'fan-key');
});

it('leaves the token unnamed for a browser session', function () {
    Event::fake([ApiChangeMade::class]);
    $owner = changeLibrary();
    actingAs($owner);

    patchJson('/api/v1/plugins/live', ['name' => 'Renamed'])->assertOk();

    Event::assertDispatched(fn (ApiChangeMade $event): bool => $event->change === ApiChange::PLUGIN_UPDATED && $event->tokenId === null);
});

it('says nothing about a change that was refused', function () {
    Storage::fake('local');
    Event::fake([ApiChangeMade::class]);
    changeLibrary();
    writer('stranger-key');
    $auth = ['Authorization' => 'Bearer stranger-key'];

    patchJson('/api/v1/plugins/live', ['name' => 'Mine now'], $auth)->assertForbidden();
    patchJson('/api/v1/plugins/live', ['github_repository_url' => 'not a url'], $auth)->assertForbidden();
    postJson('/api/v1/plugins/live/releases/2.0.0/moderate', ['action' => 'approve'], $auth)->assertForbidden();
    postJson('/api/v1/plugins/nothing/subscription', [], $auth)->assertNotFound();
    postJson('/api/v1/plugins/live/subscription')->assertUnauthorized();

    Event::assertNotDispatched(ApiChangeMade::class);
});

it('says nothing about an owner\'s change that failed validation', function () {
    Event::fake([ApiChangeMade::class]);
    writer('owner-key', changeLibrary());

    patchJson('/api/v1/plugins/live', ['github_repository_url' => 'not a url'], ['Authorization' => 'Bearer owner-key'])->assertUnprocessable();

    Event::assertNotDispatched(ApiChangeMade::class);
});

// Naming the token

it('names a Sanctum style token by its id, and leaves a session or a user that has none unnamed', function () {
    $users = new GuardApiUsers;
    $withToken = Request::create('/', 'GET', server: ['HTTP_AUTHORIZATION' => 'Bearer secret']);
    $session = Request::create('/');

    $token = new class extends Model
    {
        protected $guarded = [];
    };
    $token->setAttribute('id', 42);

    $holder = new class extends TestUser
    {
        public ?Model $heldToken = null;

        public function currentAccessToken(): ?Model
        {
            return $this->heldToken;
        }
    };
    $holder->heldToken = $token;

    expect($users->tokenId($withToken, $holder))->toBe(42)
        ->and($users->tokenId($session, $holder))->toBeNull()
        ->and($users->tokenId($withToken, TestUser::make('Plain')))->toBeNull();
});

it('names no token when the binding cannot tell', function () {
    $silent = new class implements ApiUsers
    {
        public function resolve(Request $request): ?Authenticatable
        {
            return $request->user();
        }

        public function hasToken(Request $request): bool
        {
            return false;
        }

        public function tokenCan(Authenticatable $user, string $ability): bool
        {
            return false;
        }
    };
    $this->app->instance(ApiUsers::class, $silent);
    $this->app->forgetInstance(ApiAccess::class);
    Event::fake([ApiChangeMade::class]);
    actingAs(changeLibrary());

    patchJson('/api/v1/plugins/live', ['name' => 'Renamed'])->assertOk();

    Event::assertDispatched(fn (ApiChangeMade $event): bool => $event->tokenId === null);
});
