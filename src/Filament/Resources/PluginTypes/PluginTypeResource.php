<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginTypes;

use BackedEnum;
use Filagora\Core\Filament\Concerns\GatedByAbility;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Plugins\Filament\Resources\PluginTypes\Pages\CreatePluginType;
use Filagora\Plugins\Filament\Resources\PluginTypes\Pages\EditPluginType;
use Filagora\Plugins\Filament\Resources\PluginTypes\Pages\ListPluginTypes;
use Filagora\Plugins\Filament\Resources\PluginTypes\Schemas\PluginTypeForm;
use Filagora\Plugins\Filament\Resources\PluginTypes\Tables\PluginTypesTable;
use Filagora\Plugins\Support\Gates;
use Filagora\Plugins\Support\Models;
use Filament\Resources\Resource;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class PluginTypeResource extends Resource
{
    use GatedByAbility;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedTag;

    protected static ?int $navigationSort = 40;

    public static function getModel(): string
    {
        return Models::class('type');
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
        return __('filagora-plugins::messages.types.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-plugins::messages.types.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-plugins::messages.types.plural_model_label');
    }

    public static function form(Schema $schema): Schema
    {
        return PluginTypeForm::configure($schema);
    }

    public static function table(Table $table): Table
    {
        return PluginTypesTable::configure($table);
    }

    public static function getPages(): array
    {
        return [
            'index' => ListPluginTypes::route('/'),
            'create' => CreatePluginType::route('/create'),
            'edit' => EditPluginType::route('/{record}/edit'),
        ];
    }
}
