<?php

declare(strict_types=1);

namespace Filagora\Plugins\Enums;

enum PluginReportStatus: string
{
    case OPEN = 'open';
    case REVIEWED = 'reviewed';
    case DISMISSED = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::OPEN => 'Open',
            self::REVIEWED => 'Reviewed',
            self::DISMISSED => 'Dismissed',
        };
    }
}
