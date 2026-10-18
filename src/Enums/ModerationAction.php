<?php

declare(strict_types=1);

namespace Filagora\Plugins\Enums;

enum ModerationAction: string
{
    case APPROVE = 'approve';
    case REJECT_PERMANENTLY = 'reject_permanently';

    public function status(): PluginStatus
    {
        return match ($this) {
            self::APPROVE => PluginStatus::ACTIVE,
            self::REJECT_PERMANENTLY => PluginStatus::REJECTED_FINAL,
        };
    }
}
