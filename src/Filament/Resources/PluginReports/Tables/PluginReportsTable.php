<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginReports\Tables;

use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Enums\PluginReportStatus;
use Filagora\Plugins\Filament\Actions\ModerationActions;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;

class PluginReportsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('plugin.name')->label(__('filagora-plugins::messages.reports.plugin'))->searchable()->sortable(),
                TextColumn::make('release.version')->label(__('filagora-plugins::messages.reports.release'))->placeholder('-'),
                TextColumn::make('reporter.'.UserModel::titleAttribute())->label(__('filagora-plugins::messages.reports.reporter'))->searchable(),
                TextColumn::make('reason')->limit(80)->tooltip(fn (TextColumn $column): ?string => strlen((string) $column->getState()) > 80 ? (string) $column->getState() : null)->wrap(),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PluginReportStatus|string $state): string => ($state instanceof PluginReportStatus ? $state : PluginReportStatus::from($state))->label())
                    ->color(fn (PluginReportStatus|string $state): string => match ($state instanceof PluginReportStatus ? $state : PluginReportStatus::from($state)) {
                        PluginReportStatus::OPEN => 'warning',
                        PluginReportStatus::REVIEWED => 'success',
                        PluginReportStatus::DISMISSED => 'gray',
                    })
                    ->sortable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('reviewed_at')->dateTime()->placeholder('-')->sortable()->toggleable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(array_column(array_map(fn (PluginReportStatus $status): array => [$status->value, $status->label()], PluginReportStatus::cases()), 1, 0))->default(PluginReportStatus::OPEN->value),
            ])
            ->recordActions([
                ModerationActions::reviewReport(PluginReportStatus::REVIEWED),
                ModerationActions::reviewReport(PluginReportStatus::DISMISSED),
            ]);
    }
}
