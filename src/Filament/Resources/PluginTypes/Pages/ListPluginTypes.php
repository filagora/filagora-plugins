<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginTypes\Pages;

use Filagora\Plugins\Filament\Resources\PluginTypes\PluginTypeResource;
use Filament\Actions\CreateAction;
use Filament\Resources\Pages\ListRecords;

class ListPluginTypes extends ListRecords
{
    protected static string $resource = PluginTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [CreateAction::make()];
    }
}
