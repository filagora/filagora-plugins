<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginReleases\Pages;

use Filagora\Plugins\Filament\Resources\PluginReleases\PluginReleaseResource;
use Filament\Resources\Pages\ListRecords;

class ListPluginReleases extends ListRecords
{
    protected static string $resource = PluginReleaseResource::class;
}
