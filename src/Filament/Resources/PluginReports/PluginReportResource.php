<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginReports;

use BackedEnum;
use Filagora\Core\Filament\Concerns\GatedByAbility;
use Filagora\Core\Navigation\NavigationGroupRegistry;
use Filagora\Plugins\Filament\Resources\PluginReports\Pages\ListPluginReports;
use Filagora\Plugins\Filament\Resources\PluginReports\Tables\PluginReportsTable;
use Filagora\Plugins\Support\Gates;
use Filagora\Plugins\Support\Models;
use Filament\Resources\Resource;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

/**
 * Reports are made by members and answered by staff; staff do not write or edit them, so the
 * screen is a list with a review action.
 */
class PluginReportResource extends Resource
{
    use GatedByAbility;

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedFlag;

    protected static ?int $navigationSort = 30;

    public static function getModel(): string
    {
        return Models::class('report');
    }

    protected static function abilityFor(string|UnitEnum $action, ?Model $record): ?string
    {
        return Gates::MODERATE;
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canEdit(Model $record): bool
    {
        return false;
    }

    public static function getNavigationGroup(): string|UnitEnum|null
    {
        return app(NavigationGroupRegistry::class)->label('plugins');
    }

    public static function getNavigationLabel(): string
    {
        return __('filagora-plugins::messages.reports.navigation_label');
    }

    public static function getModelLabel(): string
    {
        return __('filagora-plugins::messages.reports.model_label');
    }

    public static function getPluralModelLabel(): string
    {
        return __('filagora-plugins::messages.reports.plural_model_label');
    }

    public static function table(Table $table): Table
    {
        return PluginReportsTable::configure($table);
    }

    public static function getPages(): array
    {
        return ['index' => ListPluginReports::route('/')];
    }
}
