<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\Plugins\Schemas;

use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Enums\PluginStatus;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class PluginForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            TextInput::make('name')
                ->required()
                ->maxLength(180)
                ->live(onBlur: true)
                ->afterStateUpdated(function (Get $get, Set $set, ?string $old, ?string $state): void {
                    if (($get('slug') ?? '') === Str::slug((string) $old)) {
                        $set('slug', Str::slug((string) $state));
                    }
                }),
            TextInput::make('slug')->required()->maxLength(180)->unique(ignoreRecord: true),
            Select::make('plugin_type_id')->label(__('filagora-plugins::messages.plugins.type'))->relationship('type', 'name')->required()->searchable()->preload(),
            Select::make('owner_id')
                ->label(__('filagora-plugins::messages.plugins.owner'))
                ->relationship('owner', UserModel::titleAttribute())
                ->searchable()
                ->preload(),
            TextInput::make('forum_thread_id')->label(__('filagora-plugins::messages.plugins.support_thread'))->numeric()->minValue(1),
            TextInput::make('github_repository_url')->label(__('filagora-plugins::messages.plugins.github'))->maxLength(500)->url(),
            Textarea::make('description')->columnSpanFull(),
            Select::make('status')
                ->options([
                    PluginStatus::ACTIVE->value => PluginStatus::ACTIVE->label(),
                    PluginStatus::MODERATED->value => PluginStatus::MODERATED->label(),
                    PluginStatus::REJECTED_FINAL->value => PluginStatus::REJECTED_FINAL->label(),
                ])
                ->required()
                ->default(PluginStatus::MODERATED->value),
        ]);
    }
}
