<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\Plugins\Pages;

use Filagora\Plugins\Filament\Resources\Plugins\PluginResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPlugins extends ListRecords
{
    protected static string $resource = PluginResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
