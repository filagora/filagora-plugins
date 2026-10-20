<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginTypes\Pages;

use Filagora\Plugins\Filament\Resources\PluginTypes\PluginTypeResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePluginType extends CreateRecord
{
    protected static string $resource = PluginTypeResource::class;
}
