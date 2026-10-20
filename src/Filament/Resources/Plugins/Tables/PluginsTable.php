<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\Plugins\Tables;

use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Filament\Actions\ModerationActions;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;

class PluginsTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('updated_at', 'desc')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('type.name')->label(__('filagora-plugins::messages.plugins.type'))->sortable(),
                TextColumn::make('owner.'.UserModel::titleAttribute())->label(__('filagora-plugins::messages.plugins.owner'))->placeholder('-')->searchable(),
                TextColumn::make('releases_count')->label(__('filagora-plugins::messages.plugins.releases'))->counts('releases'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PluginStatus|string $state): string => ($state instanceof PluginStatus ? $state : PluginStatus::from($state))->label())
                    ->color(fn (PluginStatus|string $state): string => ($state instanceof PluginStatus ? $state : PluginStatus::from($state))->color())
                    ->sortable(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PluginStatus::options()),
                SelectFilter::make('plugin_type_id')->label(__('filagora-plugins::messages.plugins.type'))->relationship('type', 'name'),
            ])
            ->recordActions([
                EditAction::make(),
                ModerationActions::approvePlugin(),
                ModerationActions::rejectPlugin(),
            ])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    BulkAction::make('changeStatus')
                        ->label(__('filagora-plugins::messages.change_status'))
                        ->schema([
                            Select::make('status')
                                ->options([
                                    PluginStatus::ACTIVE->value => PluginStatus::ACTIVE->label(),
                                    PluginStatus::MODERATED->value => PluginStatus::MODERATED->label(),
                                    PluginStatus::REJECTED_FINAL->value => PluginStatus::REJECTED_FINAL->label(),
                                ])
                                ->required(),
                        ])
                        ->action(function (Collection $records, array $data): void {
                            foreach ($records as $plugin) {
                                $plugin->update(['status' => $data['status']]);
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }
}
