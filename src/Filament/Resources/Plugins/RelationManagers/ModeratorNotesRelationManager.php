<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\Plugins\RelationManagers;

use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Services\Moderation;
use Filament\Actions\CreateAction;
use Filament\Forms\Components\Textarea;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Filament\Tables\Columns\TextColumn;
use Filament\Tables\Table;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * The staff notes on a plugin, newest first, and a way to add one.
 */
class ModeratorNotesRelationManager extends RelationManager
{
    protected static string $relationship = 'moderatorNotes';

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return __('filagora-plugins::messages.notes.title');
    }

    public function form(Schema $schema): Schema
    {
        return $schema->components([Textarea::make('note')->required()->rows(4)->maxLength(4000)->columnSpanFull()]);
    }

    public function table(Table $table): Table
    {
        return $table
            ->defaultSort('created_at', 'desc')
            ->columns([
                TextColumn::make('moderator.'.UserModel::titleAttribute())->label(__('filagora-plugins::messages.notes.moderator'))->placeholder('-'),
                TextColumn::make('note')->wrap(),
                TextColumn::make('created_at')->dateTime()->sortable(),
            ])
            ->headerActions([
                CreateAction::make()
                    ->label(__('filagora-plugins::messages.notes.add'))
                    ->using(function (array $data): Model {
                        $plugin = $this->getOwnerRecord();
                        $moderator = auth()->user();
                        assert($plugin instanceof Plugin && $moderator instanceof Authenticatable);

                        return app(Moderation::class)->addNote($plugin, $moderator, trim((string) $data['note']));
                    }),
            ]);
    }
}
