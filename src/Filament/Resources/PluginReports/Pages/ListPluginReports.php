<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginReports\Pages;

use Filagora\Plugins\Filament\Resources\PluginReports\PluginReportResource;
use Filament\Resources\Pages\ListRecords;

class ListPluginReports extends ListRecords
{
    protected static string $resource = PluginReportResource::class;
}
