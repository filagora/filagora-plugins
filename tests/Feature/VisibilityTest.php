<?php

declare(strict_types=1);

use Filagora\Plugins\Enums\ModerationRefusal;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Exceptions\ModerationRefused;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Services\Visibility;
use Filagora\Plugins\Tests\Support\GrantedKeys;
use Filagora\Plugins\Tests\Support\TestUser;

/**
 * A library with an open type, a members-only type and a switched-off type.
 *
 * @return array<string, Plugin>
 */
function library(TestUser $ada): array
{
    $open = PluginType::create(['name' => 'Open', 'slug' => 'open', 'position' => 2]);
    $members = PluginType::create(['name' => 'Members', 'slug' => 'members', 'required_entitlement_scope' => 'plugins.members', 'position' => 1]);
    $off = PluginType::create(['name' => 'Off', 'slug' => 'off', 'is_active' => false]);

    $make = fn (string $slug, PluginType $type, PluginStatus $status, ?TestUser $owner = null): Plugin => tap(Plugin::create([
        'name' => ucfirst($slug), 'slug' => $slug, 'plugin_type_id' => $type->id, 'status' => $status, 'owner_id' => $owner?->id,
    ]), function (Plugin $plugin) use ($status): void {
        addRelease($plugin, '1.0.0', $status);
        addRelease($plugin, '1.1.0', PluginStatus::MODERATED);
    });

    return [
        'open' => $make('open-live', $open, PluginStatus::ACTIVE, TestUser::make('Other')),
        'members' => $make('members-live', $members, PluginStatus::ACTIVE),
        'waiting' => $make('waiting', $open, PluginStatus::MODERATED, $ada),
        'rejected' => $make('rejected', $open, PluginStatus::REJECTED_FINAL),
        'off' => $make('off-live', $off, PluginStatus::ACTIVE),
        'mine' => $make('mine-live', $open, PluginStatus::ACTIVE, $ada),
    ];
}

function slugs($plugins): array
{
    return $plugins->pluck('slug')->sort()->values()->all();
}

it('shows a guest only active plugins in open types', function () {
    library(TestUser::make('Ada'));

    expect(slugs(app(Visibility::class)->publicPlugins()->get()))->toBe(['mine-live', 'open-live']);
});

it('shows a members-only type to someone allowed its scope, and not to anyone else', function () {
    library(TestUser::make('Ada'));
    $member = TestUser::make('Mem');
    app(GrantedKeys::class)->give($member, 'plugins.members');

    expect(slugs(app(Visibility::class)->publicPlugins($member)->get()))->toBe(['members-live', 'mine-live', 'open-live'])
        ->and(slugs(app(Visibility::class)->publicPlugins(TestUser::make('Nobody'))->get()))->toBe(['mine-live', 'open-live']);
});

it('lists the types a viewer may see, with active plugin counts, in order', function () {
    library(TestUser::make('Ada'));
    $member = TestUser::make('Mem');
    app(GrantedKeys::class)->give($member, 'plugins.members');

    $guest = app(Visibility::class)->types(null)->get();
    $seen = app(Visibility::class)->types($member)->get();

    expect($guest->pluck('slug')->all())->toBe(['open'])
        ->and($guest->first()->active_plugins_count)->toBe(2)
        ->and($seen->pluck('slug')->all())->toBe(['members', 'open'])
        ->and(app(Visibility::class)->visibleTypeIds(null))->toHaveCount(1);
});

it('narrows by type and by search words', function () {
    library(TestUser::make('Ada'));
    $visibility = app(Visibility::class);

    expect(slugs($visibility->publicPlugins(null, 'open')->get()))->toBe(['mine-live', 'open-live'])
        ->and(slugs($visibility->publicPlugins(null, 'members')->get()))->toBe([])
        ->and(slugs($visibility->publicPlugins(null, null, 'mine')->get()))->toBe(['mine-live'])
        ->and(slugs($visibility->publicPlugins(null, null, '100%')->get()))->toBe([]);
});

it('shows an owner their own plugins in any status, as well as the public ones', function () {
    $ada = TestUser::make('Ada');
    library($ada);

    expect(slugs(app(Visibility::class)->ownerPlugins($ada)->get()))->toBe(['mine-live', 'open-live', 'waiting'])
        ->and(slugs(app(Visibility::class)->ownerPlugins(TestUser::make('Bo'))->get()))->toBe(['mine-live', 'open-live']);
});

it('shows an owner all their releases, and everyone else only the active ones', function () {
    $ada = TestUser::make('Ada');
    $plugins = library($ada);

    $own = app(Visibility::class)->ownerPlugins($ada)->get()->firstWhere('slug', 'mine-live');
    $other = app(Visibility::class)->publicPlugins($ada)->get()->firstWhere('slug', 'open-live');
    $guestView = app(Visibility::class)->publicPlugins()->get()->firstWhere('slug', 'mine-live');

    expect($own->releases)->toHaveCount(2)
        ->and($own->active_releases_count)->toBe(1)
        ->and($guestView->releases)->toHaveCount(1)
        ->and($other->releases)->toHaveCount(1);
});

it('shows staff everything in an active type, but only to someone who may moderate', function () {
    library(TestUser::make('Ada'));

    expect(slugs(app(Visibility::class)->staffPlugins(moderator())->get()))->toBe(['members-live', 'mine-live', 'open-live', 'rejected', 'waiting']);

    try {
        app(Visibility::class)->staffPlugins(TestUser::make('Nobody'));
        $this->fail('Expected a refusal');
    } catch (ModerationRefused $e) {
        expect($e->reason)->toBe(ModerationRefusal::NOT_PERMITTED);
    }
});

it('finds a plugin by slug only where the viewer is allowed to see it', function () {
    $ada = TestUser::make('Ada');
    library($ada);
    $visibility = app(Visibility::class);

    expect($visibility->findPublicPlugin('open-live'))->not->toBeNull()
        ->and($visibility->findPublicPlugin('waiting'))->toBeNull()
        ->and($visibility->findPublicPlugin('members-live'))->toBeNull()
        ->and($visibility->findOwnerPlugin('waiting', $ada))->not->toBeNull()
        ->and($visibility->findOwnerPlugin('waiting', TestUser::make('Bo')))->toBeNull()
        ->and($visibility->findStaffPlugin('rejected', moderator()))->not->toBeNull();
});

it('lists releases by audience', function () {
    $ada = TestUser::make('Ada');
    $plugins = library($ada);
    $visibility = app(Visibility::class);
    $mine = $plugins['mine'];

    expect($visibility->publicReleases($mine)->pluck('version')->all())->toBe(['1.0.0'])
        ->and($visibility->ownerReleases($mine, $ada)->pluck('version')->sort()->values()->all())->toBe(['1.0.0', '1.1.0'])
        ->and($visibility->ownerReleases($mine, TestUser::make('Bo'))->pluck('version')->all())->toBe(['1.0.0'])
        ->and($visibility->staffReleases($mine, moderator())->count())->toBe(2);
});

it('finds a release by version for each audience', function () {
    $ada = TestUser::make('Ada');
    $mine = library($ada)['mine'];
    $visibility = app(Visibility::class);

    expect($visibility->findPublicRelease($mine, '1.1.0'))->toBeNull()
        ->and($visibility->findOwnerRelease($mine, $ada, '1.1.0'))->not->toBeNull()
        ->and($visibility->findOwnerRelease($mine, TestUser::make('Bo'), '1.1.0'))->toBeNull()
        ->and($visibility->findStaffRelease($mine, moderator(), '1.1.0'))->not->toBeNull()
        ->and($visibility->findPublicRelease($mine, '1.0.0')->plugin->type)->not->toBeNull();
});

it('orders plugins newest first, breaking a tie by id', function () {
    $type = pluginType();
    $now = now();
    $first = Plugin::create(['name' => 'A', 'slug' => 'a', 'plugin_type_id' => $type->id, 'status' => PluginStatus::ACTIVE]);
    $second = Plugin::create(['name' => 'B', 'slug' => 'b', 'plugin_type_id' => $type->id, 'status' => PluginStatus::ACTIVE]);
    Plugin::query()->update(['updated_at' => $now, 'created_at' => $now]);

    expect(app(Visibility::class)->publicPlugins()->pluck('slug')->all())->toBe(['b', 'a']);
});

it('tells the owner of nothing when a plugin has no owner', function () {
    $plugin = library(TestUser::make('Ada'))['open'];

    expect(app(Visibility::class)->ownerReleases($plugin, TestUser::make('Bo'))->count())->toBe(1);
});
