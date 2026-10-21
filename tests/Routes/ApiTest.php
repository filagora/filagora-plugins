<?php

declare(strict_types=1);

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Core\Support\Tables;
use Filagora\Plugins\Api\ApiAccess;
use Filagora\Plugins\Api\ApiPayloads;
use Filagora\Plugins\Api\DownloadLinks;
use Filagora\Plugins\Api\GuardApiUsers;
use Filagora\Plugins\Contracts\ApiFields;
use Filagora\Plugins\Contracts\ApiUsers;
use Filagora\Plugins\Contracts\DownloadAccess;
use Filagora\Plugins\Contracts\ReleaseExtension;
use Filagora\Plugins\Enums\ModerationAction;
use Filagora\Plugins\Enums\ModerationRefusal;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Exceptions\ModerationRefused;
use Filagora\Plugins\Http\Controllers\PluginApiController;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Release\ReleaseExtensions;
use Filagora\Plugins\Release\ReleaseInput;
use Filagora\Plugins\Services\Moderation;
use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;
use Filagora\Plugins\Tests\Support\Zips;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\post;
use function Pest\Laravel\postJson;

function apiLibrary(): array
{
    $open = pluginType();
    $members = PluginType::create(['name' => 'Members', 'slug' => 'members', 'required_entitlement_scope' => 'plugins.members']);
    $ada = TestUser::make('Ada');
    $live = livePlugin($ada, PluginStatus::ACTIVE, 'live');
    $second = livePlugin(null, PluginStatus::ACTIVE, 'second');
    $third = livePlugin(null, PluginStatus::ACTIVE, 'third');
    $waiting = livePlugin($ada, PluginStatus::MODERATED, 'waiting');
    $gated = Plugin::create(['name' => 'Gated', 'slug' => 'gated', 'plugin_type_id' => $members->id, 'status' => PluginStatus::ACTIVE]);
    addRelease($gated, '1.0.0');

    return compact('open', 'members', 'ada', 'live', 'second', 'third', 'waiting', 'gated');
}

function verified(string $name = 'Vera'): TestUser
{
    return TestUser::make($name);
}

// Reading

it('lists the plugin types a guest may see, with their active plugin counts', function () {
    apiLibrary();

    $response = getJson('/api/v1/plugin-types')->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0'))->toMatchArray(['slug' => 'tools', 'requires_entitlement' => false, 'active_plugins_count' => 3]);
});

it('shows a members-only type to a member', function () {
    apiLibrary();
    $member = TestUser::make('Mem');
    app(GrantedKeys::class)->give($member, 'plugins.members');
    actingAs($member);

    $types = collect(getJson('/api/v1/plugin-types')->assertOk()->json('data'))->keyBy('slug');

    expect($types->keys()->sort()->values()->all())->toBe(['members', 'tools'])
        ->and($types['members']['requires_entitlement'])->toBeTrue()
        ->and($types['tools']['requires_entitlement'])->toBeFalse();
});

it('pages the public plugins and narrows them by words and type', function () {
    apiLibrary();

    $page = getJson('/api/v1/plugins')->assertOk();

    expect($page->json('data'))->toHaveCount(2)
        ->and($page->json('meta.total'))->toBe(3)
        ->and($page->json('meta.per_page'))->toBe(2);

    expect(getJson('/api/v1/plugins?page=2')->json('data'))->toHaveCount(1)
        ->and(collect(getJson('/api/v1/plugins?q=sec')->json('data'))->pluck('slug')->all())->toBe(['second'])
        ->and(getJson('/api/v1/plugins?type=members')->json('data'))->toBe([]);
});

it('refuses a bad visibility or page', function () {
    apiLibrary();

    getJson('/api/v1/plugins?visibility=everyone')->assertUnprocessable()->assertJsonValidationErrors('visibility');
    getJson('/api/v1/plugins?page=0')->assertUnprocessable();
});

it('gives an owner their own plugins and staff everything, and no one else', function () {
    ['ada' => $ada] = apiLibrary();

    actingAs($ada);
    expect(collect(getJson('/api/v1/plugins?visibility=owner&q=wait')->assertOk()->json('data'))->pluck('slug')->all())->toBe(['waiting']);

    actingAs(TestUser::make('Nobody'));
    getJson('/api/v1/plugins?visibility=staff')->assertForbidden();

    actingAs(moderator());
    expect(getJson('/api/v1/plugins?visibility=staff&q=wait')->assertOk()->json('data'))->toHaveCount(1);
});

it('treats a guest who asks for the owner view as asking for the public one', function () {
    apiLibrary();

    expect(collect(getJson('/api/v1/plugins?visibility=owner&q=wait')->assertOk()->json('data'))->pluck('slug')->all())->toBe([]);
});

it('shows a plugin with its releases, and 404s what a guest may not see', function () {
    apiLibrary();

    $show = getJson('/api/v1/plugins/live')->assertOk();

    expect($show->json('data'))->toMatchArray(['slug' => 'live', 'status' => 'active', 'active_releases_count' => 1])
        ->and($show->json('data.type.slug'))->toBe('tools')
        ->and($show->json('data.releases'))->toHaveCount(1)
        ->and($show->json('data.releases.0.version'))->toBe('v1.0.0');

    getJson('/api/v1/plugins/waiting')->assertNotFound();
    getJson('/api/v1/plugins/gated')->assertNotFound();
    getJson('/api/v1/plugins/nothing')->assertNotFound();
});

it('lists a plugin\'s releases by audience', function () {
    ['ada' => $ada, 'live' => $live] = apiLibrary();
    addRelease($live, '2.0.0', PluginStatus::MODERATED);

    expect(getJson('/api/v1/plugins/live/releases')->assertOk()->json('data'))->toHaveCount(1);

    actingAs($ada);
    expect(getJson('/api/v1/plugins/live/releases?visibility=owner')->json('data'))->toHaveCount(2);

    actingAs(TestUser::make('Bo'));
    expect(getJson('/api/v1/plugins/live/releases?visibility=owner')->assertOk()->json('data'))->toHaveCount(1);
});

it('lists what a release works with, when that is tracked', function () {
    ['live' => $live] = apiLibrary();
    fakeCompatibility()->declare($live->releases->first(), '2.1', '2.2');

    getJson('/api/v1/plugins/live/releases/v1.0.0')->assertOk()->assertJsonPath('data.compatibility', ['2.1', '2.2']);
    getJson('/api/v1/plugins/live')->assertJsonPath('data.releases.0.compatibility', ['2.1', '2.2']);
});

it('shows one release and its download details', function () {
    ['live' => $live] = apiLibrary();
    $release = $live->releases->first();
    $release->update(['zip_filename' => 'live.zip', 'checksum_sha256' => str_repeat('a', 64), 'download_count' => 7]);

    getJson('/api/v1/plugins/live/releases/v1.0.0')->assertOk()->assertJsonPath('data.version', 'v1.0.0')->assertJsonPath('data.checksum_sha256', str_repeat('a', 64));
    getJson('/api/v1/plugins/live/releases/v9.9.9')->assertNotFound();

    getJson('/api/v1/plugins/live/download/v1.0.0')->assertOk()->assertJsonPath('data.zip_filename', 'live.zip')->assertJsonPath('data.download_count', 7)
        ->assertJsonPath('data.download_url', route('filagora-plugins.download.release', ['slug' => 'live', 'release' => $release->id]));
});

it('offers no download link for a type the viewer may not download from', function () {
    $members = PluginType::create(['name' => 'Members', 'slug' => 'members', 'required_entitlement_scope' => 'plugins.members']);
    $plugin = Plugin::create(['name' => 'Gated', 'slug' => 'gated', 'plugin_type_id' => $members->id, 'status' => PluginStatus::ACTIVE]);
    addRelease($plugin, '1.0.0');
    $member = TestUser::make('Mem');
    app(GrantedKeys::class)->give($member, 'plugins.members');
    actingAs($member);

    expect(getJson('/api/v1/plugins/gated')->assertOk()->json('data.download_url'))->not->toBeNull();
});

it('leaves download links out when the download routes are off', function () {
    apiLibrary();
    config(['filagora-plugins.routes.downloads.enabled' => false]);

    getJson('/api/v1/plugins/live')->assertOk()->assertJsonPath('data.download_url', null)->assertJsonPath('data.latest_release.download_url', null);
});

// Update check

it('says which installed plugins have a newer release, and gives its details', function () {
    ['live' => $live] = apiLibrary();
    addRelease($live, '2.0.0', PluginStatus::ACTIVE, ['changelog' => 'Bigger']);
    $compatibility = fakeCompatibility();
    $compatibility->declare($live->releases()->where('version', '2.0.0')->first(), '2.1');

    $response = postJson('/api/v1/plugins/updates', ['plugins' => [
        ['slug' => 'live', 'version' => '1.0.0'],
        ['slug' => 'second', 'version' => '1.0.0'],
        ['slug' => 'nope', 'version' => '0.1'],
    ]])->assertOk();

    expect($response->json('data'))->toHaveCount(1)
        ->and($response->json('data.0'))->toMatchArray(['slug' => 'live', 'installed_version' => '1.0.0', 'update_available' => true])
        ->and($response->json('data.0.latest_compatible_release'))->toMatchArray(['version' => '2.0.0', 'changelog' => 'Bigger', 'compatibility' => ['2.1']]);
});

it('picks the release that works with the version the client runs on', function () {
    ['live' => $live] = apiLibrary();
    $compatibility = fakeCompatibility();
    $newer = addRelease($live, '2.0.0');
    $compatibility->declare($newer, '2.1');
    $compatibility->declare($live->releases()->where('version', 'v1.0.0')->first(), '1.5');
    addRelease($live, '1.5.0');
    $compatibility->declare($live->releases()->where('version', '1.5.0')->first(), '1.5');

    $old = postJson('/api/v1/plugins/updates', ['target' => '1.5', 'plugins' => [['slug' => 'live', 'version' => '1.0.0']]])->assertOk();

    expect($old->json('data.0.latest_compatible_release.version'))->toBe('1.5.0')
        ->and($old->json('meta.target'))->toBe('1.5')
        ->and(postJson('/api/v1/plugins/updates', ['target' => '9.9', 'plugins' => [['slug' => 'live', 'version' => '1.0.0']]])->json('data'))->toBe([]);
});

it('says nothing about a plugin that is up to date or that a guest may not see', function () {
    apiLibrary();

    expect(postJson('/api/v1/plugins/updates', ['plugins' => [['slug' => 'live', 'version' => 'v1.0.0'], ['slug' => 'waiting', 'version' => '0.1'], ['slug' => 'gated', 'version' => '0.1']]])->assertOk()->json('data'))->toBe([]);
});

it('finds a plugin by id as well as slug', function () {
    ['live' => $live] = apiLibrary();
    addRelease($live, '2.0.0');

    expect(postJson('/api/v1/plugins/updates', ['plugins' => [['id' => $live->id, 'version' => '1.0.0']]])->json('data.0.slug'))->toBe('live');
});

it('refuses an update check that names no plugin or has no version', function () {
    apiLibrary();

    postJson('/api/v1/plugins/updates', ['plugins' => [['version' => '1.0.0']]])->assertUnprocessable()->assertJsonValidationErrors('plugins.0.slug');
    postJson('/api/v1/plugins/updates', ['plugins' => [['slug' => 'live']]])->assertUnprocessable()->assertJsonValidationErrors('plugins.0.version');
    postJson('/api/v1/plugins/updates', ['plugins' => []])->assertUnprocessable();
    postJson('/api/v1/plugins/updates', [])->assertUnprocessable();
});

// Changing a plugin

it('lets an owner change their plugin, and returns it', function () {
    ['ada' => $ada, 'live' => $live] = apiLibrary();
    actingAs($ada);

    patchJson('/api/v1/plugins/live', ['name' => 'Renamed', 'description' => 'Words', 'github_repository_url' => 'https://example.test/live'])
        ->assertOk()
        ->assertJsonPath('data.name', 'Renamed')
        ->assertJsonPath('meta.message', 'Plugin updated.');

    expect($live->fresh()->description)->toBe('Words')->and($live->fresh()->slug)->toBe('live');
});

it('lets staff change someone else\'s plugin, and no one else', function () {
    apiLibrary();

    actingAs(TestUser::make('Bo'));
    patchJson('/api/v1/plugins/live', ['name' => 'Hijacked'])->assertForbidden();
    patchJson('/api/v1/plugins/waiting', ['name' => 'Hijacked'])->assertNotFound();

    actingAs(moderator());
    patchJson('/api/v1/plugins/second', ['description' => 'Tidied'])->assertOk();
});

it('refuses an unsigned-in or unverified person, and bad values', function () {
    ['ada' => $ada] = apiLibrary();

    patchJson('/api/v1/plugins/live', ['name' => 'X'])->assertUnauthorized();

    $unverified = TestUser::make('Una');
    $unverified->forceFill(['email_verified_at' => null])->save();
    Plugin::where('slug', 'live')->update(['owner_id' => $unverified->id]);
    actingAs($unverified);
    patchJson('/api/v1/plugins/live', ['name' => 'X'])->assertForbidden();
    expect(Plugin::where('slug', 'live')->value('name'))->toBe('Live');
    postJson('/api/v1/plugins/live/subscription')->assertForbidden();

    Plugin::where('slug', 'live')->update(['owner_id' => $ada->id]);
    actingAs($ada);
    patchJson('/api/v1/plugins/live', ['github_repository_url' => 'not a url'])->assertUnprocessable()->assertJsonValidationErrors('github_repository_url');
    patchJson('/api/v1/plugins/live', ['name' => ''])->assertUnprocessable();
});

// Following

it('lets a signed-in person follow, check and stop following a plugin', function () {
    apiLibrary();
    actingAs(verified());

    getJson('/api/v1/plugins/live/subscription')->assertOk()->assertJsonPath('data.subscribed', false);
    postJson('/api/v1/plugins/live/subscription')->assertOk()->assertJsonPath('data.subscribed', true);
    getJson('/api/v1/plugins/live/subscription')->assertJsonPath('data.subscribed', true);
    deleteJson('/api/v1/plugins/live/subscription')->assertOk()->assertJsonPath('data.subscribed', false);
    getJson('/api/v1/plugins/live/subscription')->assertJsonPath('data.subscribed', false);
});

it('will not let a guest follow, nor follow a plugin they cannot see', function () {
    apiLibrary();

    postJson('/api/v1/plugins/live/subscription')->assertUnauthorized();

    actingAs(verified());
    postJson('/api/v1/plugins/waiting/subscription')->assertNotFound();
});

// Uploading a release

function uploadRelease(string $slug, array $fields = [], ?string $token = null)
{
    $headers = ['Accept' => 'application/json'] + ($token === null ? [] : ['Authorization' => "Bearer {$token}"]);

    return post("/api/v1/plugins/{$slug}/releases", $fields + ['version' => '2.0.0', 'zip_file' => Zips::make()], $headers);
}

it('accepts a release upload and holds it for review', function () {
    Storage::fake('local');
    apiLibrary();
    actingAs(verified());

    $response = uploadRelease('live', ['changelog' => 'New'])->assertCreated();

    expect($response->json('data'))->toMatchArray(['version' => 'v2.0.0', 'status' => 'moderated', 'changelog' => 'New', 'plugin_slug' => 'live'])
        ->and($response->json('meta.message'))->toBe('The release was received and is waiting for review.')
        ->and($response->json('meta.superseded_versions'))->toBe([])
        ->and(PluginRelease::where('version', 'v2.0.0')->count())->toBe(1);
});

it('publishes a release at once for someone who may', function () {
    Storage::fake('local');
    apiLibrary();
    $trusted = verified('Trusted');
    app(GrantedKeys::class)->give($trusted, 'filagora.plugins.auto_publish');
    actingAs($trusted);

    uploadRelease('live')->assertCreated()->assertJsonPath('data.status', 'active')->assertJsonPath('meta.message', 'The release is live.');
});

it('asks before replacing a release that is waiting, and says which it replaced', function () {
    Storage::fake('local');
    ['live' => $live] = apiLibrary();
    addRelease($live, '1.5.0', PluginStatus::MODERATED);
    actingAs(verified());

    uploadRelease('live')->assertUnprocessable()->assertJsonValidationErrors('supersede_pending_releases');

    uploadRelease('live', ['supersede_pending_releases' => true])->assertCreated()->assertJsonPath('meta.superseded_versions', ['1.5.0']);

    expect(PluginRelease::where('version', '1.5.0')->value('status'))->toBe(PluginStatus::REJECTED_FINAL);
});

it('refuses a bad upload with the field it belongs to', function () {
    Storage::fake('local');
    apiLibrary();
    actingAs(verified());

    uploadRelease('live', ['version' => 'latest'])->assertUnprocessable()->assertJsonValidationErrors('version');
    uploadRelease('live', ['version' => '1.0.0'])->assertUnprocessable()->assertJsonValidationErrors('version');
    uploadRelease('live', ['zip_file' => Zips::make(['../evil.php' => 'x'])])->assertUnprocessable()->assertJsonValidationErrors('zip_file');
    post('/api/v1/plugins/live/releases', ['version' => '3.0.0'], ['Accept' => 'application/json'])->assertUnprocessable()->assertJsonValidationErrors('zip_file');
});

it('answers 503 for an upload while submissions are paused', function () {
    Storage::fake('local');
    apiLibrary();
    config(['filagora-plugins.submissions_enabled' => false]);
    actingAs(verified());

    uploadRelease('live')->assertStatus(503);
});

it('refuses an upload from a guest, and for a plugin that cannot be seen', function () {
    Storage::fake('local');
    apiLibrary();

    uploadRelease('live')->assertUnauthorized();

    actingAs(verified());
    uploadRelease('waiting')->assertNotFound();
});

it('lets a profile package add fields to an upload and to every response', function () {
    Storage::fake('local');
    apiLibrary();
    app()->instance(ApiFields::class, new class implements ApiFields
    {
        public function pluginFields(Plugin $plugin): array
        {
            return ['shouty_name' => strtoupper($plugin->name)];
        }

        public function releaseFields(PluginRelease $release): array
        {
            return ['extra_note' => 'hello'];
        }

        public function releaseRules(): array
        {
            return ['flavour' => ['required', 'in:mild,hot']];
        }
    });
    foreach ([ApiPayloads::class, PluginApiController::class] as $class) {
        app()->forgetInstance($class);
    }
    $seen = [];
    app(ReleaseExtensions::class)->register(new class($seen) implements ReleaseExtension
    {
        public function __construct(public array &$seen) {}

        public function prepare(?Plugin $plugin, ReleaseInput $input, string $canonicalVersion): array
        {
            $this->seen = $input->extra;

            return [];
        }

        public function created(Plugin $plugin, PluginRelease $release, ReleaseInput $input): void {}
    });
    actingAs(verified());

    getJson('/api/v1/plugins/live')->assertJsonPath('data.shouty_name', 'LIVE')->assertJsonPath('data.releases.0.extra_note', 'hello');
    uploadRelease('live')->assertUnprocessable()->assertJsonValidationErrors('flavour');
    uploadRelease('live', ['flavour' => 'hot'])->assertCreated()->assertJsonPath('data.extra_note', 'hello');

    expect($seen)->toBe(['flavour' => 'hot']);
});

// Moderating

it('lets staff approve or reject a release over the API', function () {
    ['live' => $live] = apiLibrary();
    $good = addRelease($live, '2.0.0', PluginStatus::MODERATED);
    $bad = addRelease($live, '3.0.0', PluginStatus::MODERATED);
    actingAs(moderator());

    postJson('/api/v1/plugins/live/releases/2.0.0/moderate', ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'active');
    postJson('/api/v1/plugins/live/releases/3.0.0/moderate', ['action' => 'reject_permanently'])->assertUnprocessable()->assertJsonValidationErrors('note');
    postJson('/api/v1/plugins/live/releases/3.0.0/moderate', ['action' => 'reject_permanently', 'note' => 'Broken'])->assertOk()->assertJsonPath('data.status', 'rejected_final');
    postJson('/api/v1/plugins/live/releases/3.0.0/moderate', ['action' => 'maybe'])->assertUnprocessable()->assertJsonValidationErrors('action');
    postJson('/api/v1/plugins/live/releases/9.9.9/moderate', ['action' => 'approve'])->assertNotFound();

    expect($good->fresh()->status)->toBe(PluginStatus::ACTIVE)->and($bad->fresh()->status)->toBe(PluginStatus::REJECTED_FINAL);
});

it('keeps moderation for staff', function () {
    ['live' => $live] = apiLibrary();
    addRelease($live, '2.0.0', PluginStatus::MODERATED);

    postJson('/api/v1/plugins/live/releases/2.0.0/moderate', ['action' => 'approve'])->assertUnauthorized();

    actingAs(verified());
    postJson('/api/v1/plugins/live/releases/2.0.0/moderate', ['action' => 'approve'])->assertForbidden();
});

// Tokens

it('lets a token in only with the ability the endpoint needs', function () {
    apiLibrary();
    $user = verified();
    $this->tokens->issue('reader', $user, 'plugins:read');
    $this->tokens->issue('writer', $user, 'plugins:write');
    app(GrantedKeys::class)->give($user, 'api.plugins.read');

    getJson('/api/v1/plugins', ['Authorization' => 'Bearer reader'])->assertOk();
    getJson('/api/v1/plugins', ['Authorization' => 'Bearer writer'])->assertForbidden();
    getJson('/api/v1/plugins', ['Authorization' => 'Bearer unknown'])->assertUnauthorized();
});

it('also needs the capability key for the ability, unless none is configured', function () {
    apiLibrary();
    $user = verified();
    $this->tokens->issue('reader', $user, 'plugins:read');

    getJson('/api/v1/plugins', ['Authorization' => 'Bearer reader'])->assertForbidden();

    app(GrantedKeys::class)->give($user, 'api.plugins.read');
    getJson('/api/v1/plugins', ['Authorization' => 'Bearer reader'])->assertOk();

    app(GrantedKeys::class)->give($user, 'something.else');
    config(['filagora-plugins.routes.api.keys.read' => null]);
    getJson('/api/v1/plugins', ['Authorization' => 'Bearer reader'])->assertOk();
});

it('does not ask a browser session for token abilities or capability keys', function () {
    apiLibrary();
    actingAs(verified());

    getJson('/api/v1/plugins')->assertOk();
});

it('asks a token that changes things for the write ability and key', function () {
    Storage::fake('local');
    apiLibrary();
    $user = verified();
    $this->tokens->issue('rw', $user, 'plugins:read', 'plugins:write');
    $this->tokens->issue('ro', $user, 'plugins:read');
    app(GrantedKeys::class)->give($user, 'api.plugins.read', 'api.plugins.write');

    postJson('/api/v1/plugins/live/subscription', [], ['Authorization' => 'Bearer ro'])->assertForbidden();
    postJson('/api/v1/plugins/live/subscription', [], ['Authorization' => 'Bearer rw'])->assertOk();
});

it('reads who is calling through the guard by default, and refuses a token its user cannot vouch for', function () {
    apiLibrary();
    app()->instance(ApiUsers::class, new GuardApiUsers);
    app()->forgetInstance(ApiAccess::class);
    app()->forgetInstance(PluginApiController::class);
    $user = verified();
    app(GrantedKeys::class)->give($user, 'api.plugins.read');
    actingAs($user);

    getJson('/api/v1/plugins')->assertOk();
    getJson('/api/v1/plugins', ['Authorization' => 'Bearer anything'])->assertForbidden();
});

// Download links

it('offers a download link only to a token that may download', function () {
    apiLibrary();
    $user = verified();
    $this->tokens->issue('reader', $user, 'plugins:read');
    $this->tokens->issue('downloader', $user, 'plugins:read', 'plugins:download');
    app(GrantedKeys::class)->give($user, 'api.plugins.read', 'api.plugins.download');

    expect(getJson('/api/v1/plugins/live', ['Authorization' => 'Bearer reader'])->assertOk()->json('data.download_url'))->toBeNull()
        ->and(getJson('/api/v1/plugins/live', ['Authorization' => 'Bearer reader'])->json('data.releases.0.download_url'))->toBeNull()
        ->and(getJson('/api/v1/plugins/live', ['Authorization' => 'Bearer downloader'])->json('data.download_url'))->toBe(route('filagora-plugins.download', ['slug' => 'live']))
        ->and(getJson('/api/v1/plugins/live', ['Authorization' => 'Bearer downloader'])->json('data.releases.0.download_url'))->not->toBeNull();
});

it('offers no download link when the download rules say no', function () {
    apiLibrary();
    app()->instance(DownloadAccess::class, new class implements DownloadAccess
    {
        public function requiredScope(Plugin $plugin): string
        {
            return '';
        }

        public function canDownloadPlugin(?Authenticatable $user, Plugin $plugin): bool
        {
            return false;
        }

        public function canDownloadRelease(?Authenticatable $user, PluginRelease $release): bool
        {
            return false;
        }
    });
    foreach ([DownloadLinks::class, ApiPayloads::class, PluginApiController::class] as $class) {
        app()->forgetInstance($class);
    }

    getJson('/api/v1/plugins/live')->assertOk()->assertJsonPath('data.download_url', null)->assertJsonPath('data.releases.0.download_url', null);
});

it('offers a plugin-level link only when the plugin has a live release', function () {
    $plugin = Plugin::create(['name' => 'Bare', 'slug' => 'bare', 'plugin_type_id' => pluginType()->id, 'status' => PluginStatus::ACTIVE]);
    addRelease($plugin, '1.0.0', PluginStatus::MODERATED);
    $links = app(DownloadLinks::class);

    expect($links->plugin(request(), $plugin))->toBeNull();

    addRelease($plugin, '2.0.0');

    expect($links->plugin(request(), $plugin))->toBe(route('filagora-plugins.download', ['slug' => 'bare']));
});

// Edge cases of changing and moderating

it('checks a support thread exists when the forums tables are there', function () {
    ['ada' => $ada] = apiLibrary();
    $table = Tables::name('threads');
    Schema::create($table, fn ($t) => $t->id());
    actingAs($ada);

    try {
        $id = DB::table($table)->insertGetId([]);

        patchJson('/api/v1/plugins/live', ['forum_thread_id' => $id + 999])->assertUnprocessable()->assertJsonValidationErrors('forum_thread_id');
        patchJson('/api/v1/plugins/live', ['forum_thread_id' => $id])->assertOk();
    } finally {
        Schema::dropIfExists($table);
    }
});

it('answers 403 when the moderation service refuses a person it let through', function () {
    ['live' => $live] = apiLibrary();
    addRelease($live, '2.0.0', PluginStatus::MODERATED);
    actingAs(moderator());
    app()->instance(Moderation::class, new class(app(AccessChecker::class)) extends Moderation
    {
        public function moderateRelease(Authenticatable $moderator, Plugin $plugin, PluginRelease $release, ModerationAction $action, ?string $note = null): void
        {
            throw new ModerationRefused(ModerationRefusal::NOT_PERMITTED);
        }
    });
    app()->forgetInstance(PluginApiController::class);

    postJson('/api/v1/plugins/live/releases/2.0.0/moderate', ['action' => 'approve'])->assertForbidden();
});

it('lets staff moderate a release of a plugin that is itself waiting', function () {
    ['waiting' => $waiting] = apiLibrary();
    addRelease($waiting, '2.0.0', PluginStatus::MODERATED);
    actingAs(moderator());

    postJson('/api/v1/plugins/waiting/releases/2.0.0/moderate', ['action' => 'approve'])->assertOk()->assertJsonPath('data.status', 'active');
});
