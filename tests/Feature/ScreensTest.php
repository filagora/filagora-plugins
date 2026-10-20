<?php

declare(strict_types=1);

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Plugins\Enums\ModerationAction;
use Filagora\Plugins\Enums\ModerationRefusal;
use Filagora\Plugins\Enums\PluginReportStatus;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Exceptions\ModerationRefused;
use Filagora\Plugins\Filament\Resources\PluginReleases\Pages\EditPluginRelease;
use Filagora\Plugins\Filament\Resources\PluginReleases\Pages\ListPluginReleases;
use Filagora\Plugins\Filament\Resources\PluginReleases\PluginReleaseResource;
use Filagora\Plugins\Filament\Resources\PluginReports\Pages\ListPluginReports;
use Filagora\Plugins\Filament\Resources\PluginReports\PluginReportResource;
use Filagora\Plugins\Filament\Resources\Plugins\Pages\CreatePlugin;
use Filagora\Plugins\Filament\Resources\Plugins\Pages\EditPlugin;
use Filagora\Plugins\Filament\Resources\Plugins\Pages\ListPlugins;
use Filagora\Plugins\Filament\Resources\Plugins\PluginResource;
use Filagora\Plugins\Filament\Resources\Plugins\RelationManagers\ModeratorNotesRelationManager;
use Filagora\Plugins\Filament\Resources\PluginTypes\Pages\CreatePluginType;
use Filagora\Plugins\Filament\Resources\PluginTypes\Pages\EditPluginType;
use Filagora\Plugins\Filament\Resources\PluginTypes\Pages\ListPluginTypes;
use Filagora\Plugins\Filament\Resources\PluginTypes\PluginTypeResource;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginModeratorNote;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginReport;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Services\Moderation;
use Filagora\Plugins\Services\Reports;
use Filagora\Plugins\Tests\Support\TestUser;
use Filament\Facades\Filament;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    Notification::fake();
});

function staff(): TestUser
{
    $user = moderator('Staff');
    actingAs($user);

    return $user;
}

it('puts all four screens on a panel with the plugin', function () {
    expect(Filament::getCurrentPanel()->getResources())->toContain(PluginResource::class, PluginReleaseResource::class, PluginReportResource::class, PluginTypeResource::class);
});

it('keeps every screen for people who may moderate', function (string $resource) {
    actingAs(TestUser::make('Nobody'));
    expect($resource::canViewAny())->toBeFalse();

    staff();
    expect($resource::canViewAny())->toBeTrue();
})->with([PluginResource::class, PluginReleaseResource::class, PluginReportResource::class, PluginTypeResource::class]);

it('cannot create releases or edit reports from the screens', function () {
    staff();

    expect(PluginReleaseResource::canCreate())->toBeFalse()
        ->and(PluginReportResource::canCreate())->toBeFalse()
        ->and(PluginTypeResource::canCreate())->toBeTrue()
        ->and(PluginResource::canCreate())->toBeTrue();
});

it('puts the screens in the plugins navigation group', function () {
    staff();

    expect(PluginResource::getNavigationGroup())->toBe('Plugins')
        ->and(PluginTypeResource::getNavigationLabel())->toBe('Plugin types');
});

// Plugin types

it('lists, creates and edits plugin types', function () {
    staff();
    $type = pluginType();

    Livewire::test(ListPluginTypes::class)->assertCanSeeTableRecords([$type]);

    Livewire::test(CreatePluginType::class)
        ->fillForm(['name' => 'Payment Modules', 'slug' => 'payment', 'position' => 3, 'is_active' => true, 'required_entitlement_scope' => 'plugins.payments'])
        ->call('create')
        ->assertHasNoFormErrors();

    $created = PluginType::where('slug', 'payment')->sole();

    expect($created->name)->toBe('Payment Modules')->and($created->position)->toBe(3)->and($created->required_entitlement_scope)->toBe('plugins.payments');

    Livewire::test(EditPluginType::class, ['record' => $created->getKey()])
        ->fillForm(['name' => 'Payments', 'is_active' => false])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($created->fresh()->name)->toBe('Payments')->and($created->fresh()->is_active)->toBeFalse();
});

it('keeps a slug that was typed by hand when the name changes', function () {
    staff();

    Livewire::test(CreatePluginType::class)
        ->fillForm(['name' => 'First Name'])
        ->assertFormSet(['slug' => 'first-name'])
        ->fillForm(['slug' => 'custom'])
        ->fillForm(['name' => 'Second Name'])
        ->assertFormSet(['slug' => 'custom']);
});

it('makes the slug from the name until it is changed by hand, and keeps slugs unique', function () {
    staff();
    pluginType();

    Livewire::test(CreatePluginType::class)
        ->fillForm(['name' => 'Shipping Modules'])
        ->assertFormSet(['slug' => 'shipping-modules'])
        ->fillForm(['name' => 'Tools', 'slug' => 'tools', 'position' => 0])
        ->call('create')
        ->assertHasFormErrors(['slug' => 'unique']);
});

// Plugins

it('lists plugins and narrows them by status and type', function () {
    staff();
    $live = livePlugin(null, PluginStatus::ACTIVE, 'live');
    $waiting = livePlugin(null, PluginStatus::MODERATED, 'waiting');

    Livewire::test(ListPlugins::class)
        ->assertCanSeeTableRecords([$live, $waiting])
        ->filterTable('status', PluginStatus::MODERATED->value)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$live]);
});

it('approves a plugin from the list, with the note it was given', function () {
    $mo = staff();
    $plugin = livePlugin(TestUser::make('Ada'), PluginStatus::MODERATED);

    Livewire::test(ListPlugins::class)
        ->assertTableActionVisible('approve', $plugin)
        ->callTableAction('approve', $plugin, ['note' => 'Looks fine']);

    expect($plugin->fresh()->status)->toBe(PluginStatus::ACTIVE)
        ->and(PluginModeratorNote::first()->note)->toBe("Plugin approved.\n\nLooks fine")
        ->and(PluginModeratorNote::first()->moderator_id)->toBe($mo->id);
});

it('will not reject a plugin without a note, and rejects it with one', function () {
    staff();
    $plugin = livePlugin(null, PluginStatus::MODERATED);

    Livewire::test(ListPlugins::class)
        ->callTableAction('reject', $plugin, ['note' => ''])
        ->assertHasTableActionErrors(['note' => 'required']);

    expect($plugin->fresh()->status)->toBe(PluginStatus::MODERATED);

    Livewire::test(ListPlugins::class)->callTableAction('reject', $plugin, ['note' => 'Copied code']);

    expect($plugin->fresh()->status)->toBe(PluginStatus::REJECTED_FINAL);
});

it('only offers approve and reject on a plugin that is waiting', function () {
    staff();
    $live = livePlugin(null, PluginStatus::ACTIVE, 'live');

    Livewire::test(ListPlugins::class)
        ->assertTableActionHidden('approve', $live)
        ->assertTableActionHidden('reject', $live);
});

it('changes the status of several plugins at once', function () {
    staff();
    $first = livePlugin(null, PluginStatus::MODERATED, 'first');
    $second = livePlugin(null, PluginStatus::MODERATED, 'second');

    Livewire::test(ListPlugins::class)->callTableBulkAction('changeStatus', [$first, $second], ['status' => PluginStatus::ACTIVE->value]);

    expect($first->fresh()->status)->toBe(PluginStatus::ACTIVE)->and($second->fresh()->status)->toBe(PluginStatus::ACTIVE);
});

it('creates a plugin from the screen', function () {
    staff();
    $owner = TestUser::make('Ada');

    Livewire::test(CreatePlugin::class)
        ->fillForm(['name' => 'Super Widget', 'slug' => 'super-widget', 'plugin_type_id' => pluginType()->id, 'owner_id' => $owner->id, 'status' => PluginStatus::ACTIVE->value, 'github_repository_url' => 'https://example.test/widget'])
        ->call('create')
        ->assertHasNoFormErrors();

    $plugin = Plugin::where('slug', 'super-widget')->sole();

    expect($plugin->owner_id)->toBe($owner->id)->and($plugin->status)->toBe(PluginStatus::ACTIVE);
});

it('edits a plugin, and approves or rejects it from its own page', function () {
    staff();
    $plugin = livePlugin(null, PluginStatus::MODERATED);

    Livewire::test(EditPlugin::class, ['record' => $plugin->getKey()])
        ->fillForm(['name' => 'Renamed', 'description' => 'Now with words'])
        ->call('save')
        ->assertHasNoFormErrors()
        ->callAction('approve', ['note' => 'ok'])
        ->assertFormSet(['status' => PluginStatus::ACTIVE->value]);

    expect($plugin->fresh()->name)->toBe('Renamed')->and($plugin->fresh()->status)->toBe(PluginStatus::ACTIVE);
});

it('shows a plugin\'s notes and lets staff add one', function () {
    $mo = staff();
    $plugin = livePlugin();
    $earlier = PluginModeratorNote::create(['plugin_id' => $plugin->id, 'moderator_id' => $mo->id, 'note' => 'First look']);

    Livewire::test(ModeratorNotesRelationManager::class, ['ownerRecord' => $plugin, 'pageClass' => EditPlugin::class])
        ->assertCanSeeTableRecords([$earlier])
        ->callTableAction('create', null, ['note' => '  Handed to Bo  '])
        ->assertHasNoTableActionErrors();

    $note = PluginModeratorNote::where('plugin_id', $plugin->id)->latest('id')->first();

    expect($note->note)->toBe('Handed to Bo')->and($note->moderator_id)->toBe($mo->id);
});

// Releases

it('lists releases and narrows them by status', function () {
    staff();
    $plugin = livePlugin();
    $waiting = addRelease($plugin, '2.0.0', PluginStatus::MODERATED);
    $live = $plugin->releases()->where('version', 'v1.0.0')->first();

    Livewire::test(ListPluginReleases::class)
        ->assertCanSeeTableRecords([$live, $waiting])
        ->filterTable('status', PluginStatus::MODERATED->value)
        ->assertCanSeeTableRecords([$waiting])
        ->assertCanNotSeeTableRecords([$live]);
});

it('approves and rejects a release from the list', function () {
    staff();
    $plugin = livePlugin(TestUser::make('Ada'), PluginStatus::ACTIVE);
    $good = addRelease($plugin, '2.0.0', PluginStatus::MODERATED);
    $bad = addRelease($plugin, '3.0.0', PluginStatus::MODERATED);

    Livewire::test(ListPluginReleases::class)
        ->callTableAction('approve', $good)
        ->callTableAction('reject', $bad, ['note' => 'Broken'])
        ->assertTableActionHidden('approve', $good->fresh());

    expect($good->fresh()->status)->toBe(PluginStatus::ACTIVE)->and($bad->fresh()->status)->toBe(PluginStatus::REJECTED_FINAL);
});

it('will not reject a release without a note', function () {
    staff();
    $release = addRelease(livePlugin(null, PluginStatus::ACTIVE), '2.0.0', PluginStatus::MODERATED);

    Livewire::test(ListPluginReleases::class)
        ->callTableAction('reject', $release, ['note' => ''])
        ->assertHasTableActionErrors(['note' => 'required']);

    expect($release->fresh()->status)->toBe(PluginStatus::MODERATED);
});

it('tells the person when the service refuses, and changes nothing', function () {
    staff();
    $plugin = livePlugin(null, PluginStatus::MODERATED);
    app()->instance(Moderation::class, new class(app(AccessChecker::class)) extends Moderation
    {
        public function moderatePlugin(Authenticatable $moderator, Plugin $plugin, ModerationAction $action, ?string $note = null): void
        {
            throw new ModerationRefused(ModerationRefusal::NOT_PERMITTED);
        }
    });

    Livewire::test(ListPlugins::class)
        ->callTableAction('approve', $plugin, ['note' => 'x'])
        ->assertNotified('You are not allowed to moderate plugins.');

    expect($plugin->fresh()->status)->toBe(PluginStatus::MODERATED);
});

it('edits a release', function () {
    staff();
    $release = livePlugin()->releases->first();

    Livewire::test(EditPluginRelease::class, ['record' => $release->getKey()])
        ->fillForm(['version' => '1.0.1', 'changelog' => 'Fixed', 'status' => PluginStatus::MODERATED->value])
        ->call('save')
        ->assertHasNoFormErrors();

    expect($release->fresh()->version)->toBe('1.0.1')->and($release->fresh()->changelog)->toBe('Fixed')->and($release->fresh()->status)->toBe(PluginStatus::MODERATED);
});

it('will not set a release to archived by hand', function () {
    staff();
    $release = livePlugin()->releases->first();

    Livewire::test(EditPluginRelease::class, ['record' => $release->getKey()])
        ->fillForm(['status' => PluginStatus::ARCHIVED->value])
        ->call('save')
        ->assertHasFormErrors(['status']);
});

it('archives and restores releases in bulk, never archiving a plugin\'s last active release', function () {
    $mo = staff();
    $plugin = livePlugin();
    $second = addRelease($plugin, '2.0.0');
    PluginRelease::query()->whereKey($plugin->releases()->where('version', 'v1.0.0')->value('id'))->update(['created_at' => now()->subYears(3)]);
    $old = $plugin->releases()->where('version', 'v1.0.0')->first();

    Livewire::test(ListPluginReleases::class)
        ->callTableBulkAction('archive', [$old, $second])
        ->assertNotified(FilamentNotification::make()->success()->title('Archived 1 release(s)')->body("Kept back 1: a plugin's last active release is never archived."));

    expect($old->fresh()->status)->toBe(PluginStatus::ARCHIVED)
        ->and($old->fresh()->archived_by_id)->toBe($mo->id)
        ->and($second->fresh()->status)->toBe(PluginStatus::ACTIVE);

    Livewire::test(ListPluginReleases::class)->callTableBulkAction('restore', [$old]);

    expect($old->fresh()->status)->toBe(PluginStatus::ACTIVE);
});

it('changes the status of several releases at once', function () {
    staff();
    $plugin = livePlugin(null, PluginStatus::ACTIVE);
    $a = addRelease($plugin, '2.0.0', PluginStatus::MODERATED);
    $b = addRelease($plugin, '3.0.0', PluginStatus::MODERATED);

    Livewire::test(ListPluginReleases::class)->callTableBulkAction('changeStatus', [$a, $b], ['status' => PluginStatus::REJECTED_FINAL->value]);

    expect($a->fresh()->status)->toBe(PluginStatus::REJECTED_FINAL)->and($b->fresh()->status)->toBe(PluginStatus::REJECTED_FINAL);
});

it('offers a download only when the file is there', function () {
    Storage::fake('local');
    staff();
    $plugin = livePlugin();
    $release = $plugin->releases->first();
    Storage::disk('local')->put($release->zip_path, 'zip-bytes');

    Livewire::test(ListPluginReleases::class)
        ->assertTableActionVisible('download', $release)
        ->callTableAction('download', $release)
        ->assertFileDownloaded($release->zip_path === 'plugins/widget.zip' ? 'widget.zip' : basename($release->zip_path));

    Storage::disk('local')->delete($release->zip_path);

    Livewire::test(ListPluginReleases::class)->assertTableActionHidden('download', $release);
});

// Reports

function openReport(?Plugin $plugin = null): PluginReport
{
    return app(Reports::class)->report(TestUser::make('Rita'), $plugin ?? livePlugin(), 'This plugin phones home');
}

it('lists the open reports first, and the rest on request', function () {
    staff();
    $open = openReport();
    $done = openReport(livePlugin(null, PluginStatus::ACTIVE, 'other'));
    app(Reports::class)->review(moderator('Mo2'), $done, PluginReportStatus::DISMISSED);

    Livewire::test(ListPluginReports::class)
        ->assertCanSeeTableRecords([$open])
        ->assertCanNotSeeTableRecords([$done])
        ->removeTableFilter('status')
        ->assertCanSeeTableRecords([$open, $done]);
});

it('reviews or dismisses a report with a note, and tells the reporter', function () {
    $mo = staff();
    $reviewed = openReport();
    $dismissed = openReport(livePlugin(null, PluginStatus::ACTIVE, 'other'));

    Livewire::test(ListPluginReports::class)
        ->callTableAction(PluginReportStatus::REVIEWED->value, $reviewed, ['note' => 'Removed the call'])
        ->callTableAction(PluginReportStatus::DISMISSED->value, $dismissed);

    expect($reviewed->fresh()->status)->toBe(PluginReportStatus::REVIEWED)
        ->and($reviewed->fresh()->resolution_note)->toBe('Removed the call')
        ->and($reviewed->fresh()->reviewed_by_id)->toBe($mo->id)
        ->and($dismissed->fresh()->status)->toBe(PluginReportStatus::DISMISSED);
});

it('only offers a review on a report that is still open', function () {
    staff();
    $report = openReport();
    app(Reports::class)->review(moderator('Mo2'), $report, PluginReportStatus::REVIEWED);

    Livewire::test(ListPluginReports::class)
        ->removeTableFilter('status')
        ->assertTableActionHidden(PluginReportStatus::REVIEWED->value, $report->fresh())
        ->assertTableActionHidden(PluginReportStatus::DISMISSED->value, $report->fresh());
});

it('takes a typed-in entitlement scope for a type when entitlements is not installed', function () {
    staff();

    Livewire::test(CreatePluginType::class)
        ->assertFormFieldExists('required_entitlement_scope', fn ($field): bool => $field instanceof TextInput);
});
