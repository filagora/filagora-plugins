<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginTypes\Tables;

use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Tables\Columns\IconColumn;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Table;

class PluginTypesTable
{
    public static function configure(Table $table): Table
    {
        return $table
            ->defaultSort('position')
            ->columns([
                TextColumn::make('name')->searchable(),
                TextColumn::make('slug')->searchable(),
                TextColumn::make('required_entitlement_scope')->label(__('filagora-plugins::messages.types.scope'))->placeholder('-')->toggleable(),
                TextColumn::make('position')->numeric()->sortable(),
                TextColumn::make('plugins_count')->label(__('filagora-plugins::messages.types.plugins'))->counts('plugins'),
                IconColumn::make('is_active')->boolean(),
                TextColumn::make('updated_at')->dateTime()->sortable(),
            ])
            ->recordActions([EditAction::make()])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->toolbarActions([BulkActionGroup::make([DeleteBulkAction::make()])]);
    }
}
