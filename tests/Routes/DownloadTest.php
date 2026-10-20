<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;
use Illuminate\Support\Facades\Storage;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\get;
use function Pest\Laravel\getJson;

beforeEach(function () {
    Storage::fake('local');
});

/**
 * A plugin with a release whose file is on the disk.
 */
function servedPlugin(string $slug = 'widget', PluginStatus $plugin = PluginStatus::ACTIVE, PluginStatus $release = PluginStatus::ACTIVE, ?PluginType $type = null, string $contents = 'zip-bytes'): array
{
    $model = Plugin::create(['name' => ucfirst($slug), 'slug' => $slug, 'plugin_type_id' => ($type ?? pluginType())->id, 'status' => $plugin]);
    $file = addRelease($model, '1.0.0', $release, ['zip_filename' => "{$slug}-1.0.0.zip"]);
    Storage::disk('local')->put($file->zip_path, $contents);
    $file->refresh();

    return [$model, $file];
}

function members(): PluginType
{
    return PluginType::firstOrCreate(['slug' => 'members'], ['name' => 'Members', 'required_entitlement_scope' => 'plugins.members']);
}

it('serves the newest active release to anyone, under the name it was uploaded with, and counts it', function () {
    [$plugin, $release] = servedPlugin();
    $newer = addRelease($plugin, '2.0.0');
    Storage::disk('local')->put($newer->zip_path, 'newer-bytes');
    addRelease($plugin, '3.0.0', PluginStatus::MODERATED);

    // The newer release was given no name, so the file's own name is used.
    $response = get('/plugins/widget/download')->assertOk()->assertDownload('widget-2.0.0.zip');

    expect($newer->fresh()->download_count)->toBe(1)
        ->and($release->fresh()->download_count)->toBe(0)
        ->and($response->streamedContent())->toBe('newer-bytes');
});

it('serves one release by number, and counts it', function () {
    [, $release] = servedPlugin();

    get("/plugins/widget/releases/{$release->id}/download")->assertOk()->assertDownload('widget-1.0.0.zip');

    expect($release->fresh()->download_count)->toBe(1);
});

it('falls back to the file name when no name was kept', function () {
    [, $release] = servedPlugin();
    $release->update(['zip_filename' => null]);
    PluginRelease::query()->whereKey($release->id)->update(['zip_filename' => null]);

    get("/plugins/widget/releases/{$release->id}/download")->assertOk()->assertDownload(basename($release->zip_path));
});

it('serves an archived release while that is allowed, and refuses it when it is not', function () {
    [, $release] = servedPlugin('old', release: PluginStatus::ARCHIVED);

    get("/plugins/old/releases/{$release->id}/download")->assertOk();
    expect($release->fresh()->download_count)->toBe(0);

    config(['filagora-plugins.downloads.archived_releases' => false]);

    get("/plugins/old/releases/{$release->id}/download")->assertNotFound();
});

it('does not serve what a guest may not see', function (PluginStatus $plugin, PluginStatus $release) {
    [, $file] = servedPlugin('hidden', $plugin, $release);

    get('/plugins/hidden/download')->assertNotFound();
    get("/plugins/hidden/releases/{$file->id}/download")->assertNotFound();
})->with([
    'plugin waiting' => [PluginStatus::MODERATED, PluginStatus::ACTIVE],
    'plugin rejected' => [PluginStatus::REJECTED_FINAL, PluginStatus::ACTIVE],
    'release waiting' => [PluginStatus::ACTIVE, PluginStatus::MODERATED],
    'release rejected' => [PluginStatus::ACTIVE, PluginStatus::REJECTED_FINAL],
    'release quarantined' => [PluginStatus::ACTIVE, PluginStatus::QUARANTINED],
]);

it('is not found when the plugin, the release or the type is not there', function () {
    [, $file] = servedPlugin();
    [, $other] = servedPlugin('other');
    $off = PluginType::create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);
    servedPlugin('switched-off', type: $off);

    get('/plugins/nothing/download')->assertNotFound();
    get('/plugins/widget/releases/999999/download')->assertNotFound();
    get("/plugins/widget/releases/{$other->id}/download")->assertNotFound();
    get('/plugins/switched-off/download')->assertNotFound();
});

it('is not found when the plugin has no active release or the file is gone', function () {
    $empty = Plugin::create(['name' => 'Empty', 'slug' => 'empty', 'plugin_type_id' => pluginType()->id, 'status' => PluginStatus::ACTIVE]);
    [, $file] = servedPlugin('gone');
    Storage::disk('local')->delete($file->zip_path);

    get('/plugins/empty/download')->assertNotFound();
    get('/plugins/gone/download')->assertNotFound();
    get("/plugins/gone/releases/{$file->id}/download")->assertNotFound();
    expect($file->fresh()->download_count)->toBe(0);
});

it('sends a guest to sign in for a type that needs it, and turns away someone without the entitlement', function () {
    [, $file] = servedPlugin('gated', type: members());

    getJson('/plugins/gated/download')->assertUnauthorized();
    getJson("/plugins/gated/releases/{$file->id}/download")->assertUnauthorized();

    actingAs(TestUser::make('Nobody'));
    getJson('/plugins/gated/download')->assertForbidden()->assertJsonPath('message', 'Downloading this plugin needs plugins.members.');

    $member = TestUser::make('Mem');
    app(GrantedKeys::class)->give($member, 'plugins.members');
    actingAs($member);
    get('/plugins/gated/download')->assertOk();
    get("/plugins/gated/releases/{$file->id}/download")->assertOk();
    expect($file->fresh()->download_count)->toBe(2);
});

it('lets staff download for review whatever the status, without counting it', function () {
    [, $waiting] = servedPlugin('waiting', PluginStatus::MODERATED, PluginStatus::MODERATED);
    [, $gated] = servedPlugin('gated', type: members());
    actingAs(moderator());

    get('/plugins/waiting/download')->assertOk();
    get("/plugins/waiting/releases/{$waiting->id}/download")->assertOk();
    get('/plugins/gated/download')->assertOk();

    expect($waiting->fresh()->download_count)->toBe(0)->and($gated->fresh()->download_count)->toBe(1);
});

it('does not give staff the review bypass to a request that comes with a token', function () {
    [, $waiting] = servedPlugin('waiting', PluginStatus::MODERATED, PluginStatus::MODERATED);
    $staff = moderator();
    $this->tokens->issue('staff', $staff, 'plugins:download');
    app(GrantedKeys::class)->give($staff, 'filagora.plugins.moderate', 'api.plugins.download');

    get('/plugins/waiting/download', ['Authorization' => 'Bearer staff'])->assertNotFound();
});

it('holds a token to the download ability and key', function () {
    [, $file] = servedPlugin('gated', type: members());
    $user = TestUser::make('Mem');
    $this->tokens->issue('can', $user, 'plugins:download');
    $this->tokens->issue('cannot', $user, 'plugins:read');
    app(GrantedKeys::class)->give($user, 'plugins.members');

    getJson('/plugins/gated/download', ['Authorization' => 'Bearer can'])->assertForbidden();

    app(GrantedKeys::class)->give($user, 'plugins.members', 'api.plugins.download');

    get('/plugins/gated/download', ['Authorization' => 'Bearer can'])->assertOk();
    getJson('/plugins/gated/download', ['Authorization' => 'Bearer cannot'])->assertForbidden();
    getJson('/plugins/gated/download', ['Authorization' => 'Bearer nobody'])->assertUnauthorized();
});

it('lets a token download an open plugin, but still needs the ability', function () {
    servedPlugin();
    $user = TestUser::make('Dev');
    $this->tokens->issue('dl', $user, 'plugins:download');
    $this->tokens->issue('rd', $user, 'plugins:read');
    app(GrantedKeys::class)->give($user, 'api.plugins.download');

    get('/plugins/widget/download', ['Authorization' => 'Bearer dl'])->assertOk();
    getJson('/plugins/widget/download', ['Authorization' => 'Bearer rd'])->assertForbidden();
});
