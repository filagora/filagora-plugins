<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\Plugins;

use BackedEnum;
use Filagora\Core\Filament\Concerns\GatedByAbility;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Plugins\Filament\Resources\Plugins\Pages\CreatePlugin;
use Filagora\Plugins\Filament\Resources\Plugins\Pages\EditPlugin;
use Filagora\Plugins\Filament\Resources\Plugins\Pages\ListPlugins;
use Filagora\Plugins\Filament\Resources\Plugins\RelationManagers\ModeratorNotesRelationManager;
use Filagora\Plugins\Filament\Resources\Plugins\Schemas\PluginForm;
use Filagora\Plugins\Filament\Resources\Plugins\Tables\PluginsTable;
use Filagora\Plugins\Support\Gates;
use Filagora\Plugins\Support\Models;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PluginResource extends Resource
{
    use GatedByAbility;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static ?int $navigationSort = 10;

    public static function getModel(): string
    {
        return Models::class('plugin');
    }

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return Gates::MODERATE;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('plugins');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-plugins::messages.plugins.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-plugins::messages.plugins.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-plugins::messages.plugins.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return PluginForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PluginsTable::configure($table);
    }

    public static function getRelations(): array
    {
        return [ModeratorNotesRelationManager::class];
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPlugins::route('/'),
            'create' => CreatePlugin::route('/create'),
            'edit' => EditPlugin::route('/{record}/edit'),
        ];
    }
}
