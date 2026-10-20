<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\PluginReleases\Pages;

use Filagora\Plugins\Filament\Actions\ModerationActions;
use Filagora\Plugins\Filament\Resources\PluginReleases\PluginReleaseResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPluginRelease extends EditRecord
{
    protected static string $resource = PluginReleaseResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ModerationActions::approveRelease()->after(fn () => $this->refreshFormData(['status'])),
            ModerationActions::rejectRelease()->after(fn () => $this->refreshFormData(['status'])),
            DeleteAction::make(),
        ];
    }
}
