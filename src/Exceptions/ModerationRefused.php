<?php

declare(strict_types=1);

namespace Filagora\Plugins\Exceptions;

use Filagora\Plugins\Enums\ModerationRefusal;
use RuntimeException;

/**
 * A moderation step, report or review that cannot be carried out. The message is safe to show.
 */
final class ModerationRefused extends RuntimeException
{
    public function __construct(public readonly ModerationRefusal $reason, ?string $detail = null)
    {
        parent::__construct($detail ?? $reason->message());
    }
}
