<?php

declare(strict_types=1);

use Filagora\Plugins\Filament\ReleaseScreens;
use Filagora\Plugins\Filament\Resources\PluginReleases\Pages\ListPluginReleases;
use Filagora\Plugins\Filament\Resources\PluginReleases\PluginReleaseResource;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Actions\BulkAction;
use Filament\Facades\Filament;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\TernaryFilter;
use Livewire\Livewire;

use function Pest\Laravel\actingAs;

beforeEach(function () {
    Filament::setCurrentPanel('admin');
    actingAs(moderator('Staff'));
});

class ExtraReleasesRelationManager extends RelationManager
{
    protected static string $relationship = 'plugin';
}

it('has nothing added by default', function () {
    $screens = app(ReleaseScreens::class);

    expect($screens->columns())->toBe([])
        ->and($screens->filters())->toBe([])
        ->and($screens->actions())->toBe([])
        ->and($screens->bulkActions())->toBe([])
        ->and($screens->relationManagers())->toBe([])
        ->and(PluginReleaseResource::getRelations())->toBe([]);
});

it('puts another package\'s column, filter and actions on the release list', function () {
    $screens = app(ReleaseScreens::class);
    $screens->column('x.extra', fn () => TextColumn::make('id')->label('Extra'));
    $screens->filter('x.flag', fn () => TernaryFilter::make('flag')->queries(true: fn ($query) => $query, false: fn ($query) => $query));
    $screens->action('x.look', fn () => Action::make('look')->action(fn () => null));
    $screens->action('x.group', fn () => ActionGroup::make([Action::make('inGroup')->action(fn () => null)]));
    $screens->bulkAction('x.bulk', fn () => BulkAction::make('bulky')->action(fn () => null));
    $release = livePlugin()->releases->first();

    Livewire::test(ListPluginReleases::class)
        ->assertTableColumnExists('id')
        ->assertTableFilterExists('flag')
        ->assertTableActionExists('look')
        ->assertTableActionExists('inGroup')
        ->assertTableBulkActionExists('bulky')
        ->assertTableActionExists('edit')
        ->assertTableBulkActionExists('changeStatus')
        ->assertCanSeeTableRecords([$release]);
});

it('keeps the package\'s own actions and puts additions after them', function () {
    app(ReleaseScreens::class)->action('x.look', fn () => Action::make('look')->action(fn () => null));
    $release = livePlugin()->releases->first();

    $names = collect(Livewire::test(ListPluginReleases::class)->instance()->getTable()->getRecordActions())->map(fn ($action) => $action->getName())->all();

    expect(array_slice($names, -1))->toBe(['look'])
        ->and($names)->toContain('edit', 'approve', 'reject', 'download');
});

it('adds a relation manager to the edit page', function () {
    app(ReleaseScreens::class)->relationManager('x.extra', ExtraReleasesRelationManager::class);

    expect(PluginReleaseResource::getRelations())->toBe([ExtraReleasesRelationManager::class]);
});

it('replaces what a key already holds, so a plugin on several panels adds itself once', function () {
    $screens = app(ReleaseScreens::class);
    $screens->column('x.extra', fn () => TextColumn::make('one'));
    $screens->column('x.extra', fn () => TextColumn::make('two'));
    $screens->relationManager('x.extra', ExtraReleasesRelationManager::class);
    $screens->relationManager('x.extra', ExtraReleasesRelationManager::class);

    expect($screens->columns())->toHaveCount(1)
        ->and($screens->columns()[0]->getName())->toBe('two')
        ->and($screens->relationManagers())->toHaveCount(1);
});

it('builds the components only when a screen is set up', function () {
    $built = 0;
    app(ReleaseScreens::class)->column('x.lazy', function () use (&$built) {
        $built++;

        return TextColumn::make('id');
    });

    expect($built)->toBe(0);

    Livewire::test(ListPluginReleases::class);

    expect($built)->toBeGreaterThan(0);
});
