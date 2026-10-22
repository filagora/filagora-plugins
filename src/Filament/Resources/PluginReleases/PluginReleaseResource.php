<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginReleases;

use BackedEnum;
use Filagora\Core\Filament\Concerns\GatedByAbility;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Plugins\Filament\ReleaseScreens;
use Filagora\Plugins\Filament\Resources\PluginReleases\Pages\EditPluginRelease;
use Filagora\Plugins\Filament\Resources\PluginReleases\Pages\ListPluginReleases;
use Filagora\Plugins\Filament\Resources\PluginReleases\Schemas\PluginReleaseForm;
use Filagora\Plugins\Filament\Resources\PluginReleases\Tables\PluginReleasesTable;
use Filagora\Plugins\Support\Gates;
use Filagora\Plugins\Support\Models;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Releases are submitted through `Releases` (an archive has to be uploaded and checked), so there is
 * no create page here: staff review, edit, archive and delete what exists.
 */
class PluginReleaseResource extends Resource
{
    use GatedByAbility;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedArchiveBox;

    protected static ?int $navigationSort = 20;

    public static function getModel(): string
    {
        return Models::class('release');
    }

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return Gates::MODERATE;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('plugins');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-plugins::messages.releases.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-plugins::messages.releases.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-plugins::messages.releases.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return PluginReleaseForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PluginReleasesTable::configure($table);
    }

    public static function getRelations(): array
    {
        return app(ReleaseScreens::class)->relationManagers();
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPluginReleases::route('/'),
            'edit' => EditPluginRelease::route('/{record}/edit'),
        ];
    }
}
