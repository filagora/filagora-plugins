<?php

declare(strict_types=1);

use Filagora\Entitlements\Models\EntitlementDefinition;
use Filagora\Entitlements\Registry\EntitlementRegistry;
use Filagora\Plugins\Filament\Resources\PluginTypes\Pages\CreatePluginType;
use Filagora\Plugins\Models\PluginType;
use Filagora\Plugins\Support\PluginTypeEntitlementKeys;
use Filament\Facades\Filament;
use Filament\Forms\Components\Select;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

/*
 * The database may already have been built, without entitlements, by an earlier test, so these tests
 * bring the entitlement tables up to date themselves.
 */
beforeEach(function () {
    $this->artisan('migrate')->assertSuccessful();
});

it('declares the keys behind its abilities, without letting them cover what is below them', function () {
    $definitions = app(EntitlementRegistry::class)->definitions();

    expect($definitions)->toHaveKeys(['filagora.plugins.moderate', 'filagora.plugins.auto_publish'])
        ->and($definitions['filagora.plugins.moderate']->wildcardChildren)->toBeFalse();
});

it('lists the scopes set on plugin types, once each, and nothing for a type with none', function () {
    PluginType::create(['name' => 'A', 'slug' => 'a', 'required_entitlement_scope' => 'plugins.members']);
    PluginType::create(['name' => 'B', 'slug' => 'b', 'required_entitlement_scope' => 'plugins.members']);
    PluginType::create(['name' => 'C', 'slug' => 'c', 'required_entitlement_scope' => '  plugins.vip  ']);
    PluginType::create(['name' => 'D', 'slug' => 'd', 'required_entitlement_scope' => '   ']);
    PluginType::create(['name' => 'F', 'slug' => 'f', 'required_entitlement_scope' => 'plugins.vip']);
    PluginType::create(['name' => 'E', 'slug' => 'e']);

    $keys = (new PluginTypeEntitlementKeys)->keys();
    sort($keys);

    expect($keys)->toBe(['plugins.members', 'plugins.vip'])
        ->and(app(EntitlementRegistry::class)->keySources())->toHaveCount(1);
});

it('has the sync command create a definition for each key and scope', function () {
    PluginType::create(['name' => 'A', 'slug' => 'a', 'required_entitlement_scope' => 'plugins.members']);

    $this->artisan('filagora:entitlements:sync')->assertSuccessful();

    expect(EntitlementDefinition::whereIn('key', ['filagora.plugins.moderate', 'filagora.plugins.auto_publish', 'plugins.members'])->count())->toBe(3);
});

it('lets staff pick a type\'s scope from the definitions', function () {
    Filament::setCurrentPanel('admin');
    actingAs(moderator('Staff'));
    EntitlementDefinition::create(['key' => 'plugins.members', 'label' => 'Members', 'is_active' => true]);

    Livewire::test(CreatePluginType::class)
        ->assertFormFieldExists('required_entitlement_scope', fn ($field): bool => $field instanceof Select)
        ->fillForm(['name' => 'Members Only', 'slug' => 'members', 'position' => 0, 'required_entitlement_scope' => 'plugins.members'])
        ->call('create')
        ->assertHasNoFormErrors();

    expect(PluginType::where('slug', 'members')->value('required_entitlement_scope'))->toBe('plugins.members');
});
