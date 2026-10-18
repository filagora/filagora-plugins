<?php

declare(strict_types=1);

namespace Filagora\Plugins\Exceptions;

use Filagora\Plugins\Enums\ReleaseRefusal;
use RuntimeException;

/**
 * A submission that cannot be accepted as it stands. The message is safe to show the person, and
 * `field()` names the form field it belongs to.
 */
final class ReleaseRefused extends RuntimeException
{
    public function __construct(public readonly ReleaseRefusal $reason, ?string $detail = null)
    {
        parent::__construct($detail ?? $reason->message());
    }

    public function field(): string
    {
        return $this->reason->field();
    }
}
