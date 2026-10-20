<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\Plugins\Pages;

use Filagora\Plugins\Filament\Resources\Plugins\PluginResource;
use Filament\Resources\Pages\CreateRecord;

class CreatePlugin extends CreateRecord
{
    protected static string $resource = PluginResource::class;
}
