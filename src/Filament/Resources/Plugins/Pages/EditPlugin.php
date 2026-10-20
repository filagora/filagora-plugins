<?php

declare(strict_types=1);

namespace Filagora\Plugins\Filament\Resources\Plugins\Pages;

use Filagora\Plugins\Filament\Actions\ModerationActions;
use Filagora\Plugins\Filament\Resources\Plugins\PluginResource;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditPlugin extends EditRecord
{
    protected static string $resource = PluginResource::class;

    protected function getHeaderActions(): array
    {
        return [
            ModerationActions::approvePlugin()->after(fn () => $this->refreshFormData(['status'])),
            ModerationActions::rejectPlugin()->after(fn () => $this->refreshFormData(['status'])),
            DeleteAction::make(),
        ];
    }
}
