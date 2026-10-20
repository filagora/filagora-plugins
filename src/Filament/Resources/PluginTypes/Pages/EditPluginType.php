<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginTypes\Pages;

use Filagora\Plugins\Filament\Resources\PluginTypes\PluginTypeResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPluginType extends EditRecord
{
    protected static string $resource = PluginTypeResource::class;

    protected function getHeaderActions(): array
    {
        return [DeleteAction::make()];
    }
}
