<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginReleases\Schemas;

use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Models\PluginRelease;
use Filament\Forms\Components\Placeholder;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;

class PluginReleaseForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema->components([
            Placeholder::make('plugin')
                ->label(__('filagora-plugins::messages.releases.plugin'))
                ->content(fn (?PluginRelease $record): string => $record === null ? '-' : $record->plugin->name),
            TextInput::make('version')->required()->maxLength(40),
            Select::make('status')->options(PluginStatus::directlySettableOptions())->required(),
            Placeholder::make('file')
                ->label(__('filagora-plugins::messages.releases.file'))
                ->content(fn (?PluginRelease $record): string => $record === null ? '-' : trim(($record->zip_filename ?: basename((string) $record->zip_path)).' '.($record->zip_size !== null ? '('.number_format($record->zip_size / 1024, 1).' KB)' : ''))),
            Textarea::make('changelog')->rows(6)->columnSpanFull(),
            Textarea::make('quarantine_note')->rows(3)->columnSpanFull()->visible(fn (?PluginRelease $record): bool => $record?->isQuarantined() ?? false),
        ]);
    }
}
