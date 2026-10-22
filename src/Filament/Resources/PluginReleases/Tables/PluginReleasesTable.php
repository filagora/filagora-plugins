<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginReleases\Tables;

use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Archiving\Archiving;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Filament\Actions\ModerationActions;
use Filagora\Plugins\Filament\ReleaseScreens;
use Filagora\Plugins\Models\PluginRelease;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Actions\BulkActionGroup;
use Filament\Actions\DeleteBulkAction;
use Filament\Actions\EditAction;
use Filament\Forms\Components\Select;
use Filament\Notifications\Notification;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Enums\RecordActionsPosition;
use Filament\Tables\Filters\SelectFilter;
use Filament\Tables\Table;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PluginReleasesTable
{
    public static function configure(Table $table): Table
    {
        $screens = app(ReleaseScreens::class);

        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('plugin.name')->label(__('filagora-plugins::messages.releases.plugin'))->searchable(),
                TextColumn::make('version')->searchable(),
                TextColumn::make('uploadedBy.'.UserModel::titleAttribute())->label(__('filagora-plugins::messages.releases.uploader'))->placeholder('-'),
                TextColumn::make('status')
                    ->badge()
                    ->formatStateUsing(fn (PluginStatus|string $state): string => ($state instanceof PluginStatus ? $state : PluginStatus::from($state))->label())
                    ->color(fn (PluginStatus|string $state): string => ($state instanceof PluginStatus ? $state : PluginStatus::from($state))->color())
                    ->sortable(),
                TextColumn::make('download_count')->numeric()->sortable()->toggleable(),
                TextColumn::make('created_at')->dateTime()->sortable(),
                TextColumn::make('archived_at')->dateTime()->placeholder('-')->sortable()->toggleable(isToggledHiddenByDefault: true),
                ...$screens->columns(),
            ])
            ->filters([
                SelectFilter::make('status')->options(PluginStatus::options()),
                ...$screens->filters(),
            ])
            ->recordActions([
                EditAction::make(),
                ModerationActions::approveRelease(),
                ModerationActions::rejectRelease(),
                self::download(),
                ...$screens->actions(),
            ])
            ->recordActionsPosition(RecordActionsPosition::BeforeCells)
            ->toolbarActions([
                BulkActionGroup::make([
                    ...$screens->bulkActions(),
                    BulkAction::make('changeStatus')
                        ->label(__('filagora-plugins::messages.change_status'))
                        ->schema([Select::make('status')->options(PluginStatus::directlySettableOptions())->required()])
                        ->action(function (Collection $records, array $data): void {
                            foreach ($records as $release) {
                                $release->update(['status' => $data['status']]);
                            }
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('archive')
                        ->label(__('filagora-plugins::messages.releases.archive'))
                        ->icon('heroicon-o-archive-box')
                        ->requiresConfirmation()
                        ->modalDescription(__('filagora-plugins::messages.releases.archive_help'))
                        ->action(function (Collection $records): void {
                            $result = app(Archiving::class)->archive($records->pluck('id')->map(static fn ($id): int => (int) $id)->all(), auth()->user());

                            Notification::make()
                                ->success()
                                ->title(__('filagora-plugins::messages.releases.archived', ['count' => $result['archived']]))
                                ->body($result['kept_back'] > 0 ? __('filagora-plugins::messages.releases.kept_back', ['count' => $result['kept_back']]) : null)
                                ->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    BulkAction::make('restore')
                        ->label(__('filagora-plugins::messages.releases.restore'))
                        ->icon('heroicon-o-arrow-uturn-left')
                        ->requiresConfirmation()
                        ->action(function (Collection $records): void {
                            $count = app(Archiving::class)->restore($records->pluck('id')->map(static fn ($id): int => (int) $id)->all());

                            Notification::make()->success()->title(__('filagora-plugins::messages.releases.restored', ['count' => $count]))->send();
                        })
                        ->deselectRecordsAfterCompletion(),
                    DeleteBulkAction::make(),
                ]),
            ]);
    }

    private static function download(): Action
    {
        $disk = static fn (): string => (string) config('filagora-plugins.storage.disk');

        return Action::make('download')
            ->label(__('filagora-plugins::messages.releases.download'))
            ->icon('heroicon-o-arrow-down-tray')
            ->visible(fn (PluginRelease $record): bool => filled($record->zip_path) && Storage::disk($disk())->exists($record->zip_path))
            ->action(fn (PluginRelease $record): StreamedResponse => Storage::disk($disk())->download($record->zip_path, $record->zip_filename ?: basename($record->zip_path)));
    }
}
