<?php

declare(strict_types=1);

namespace Filagora\Plugins\Enums;

/**
 * Why a moderation step, or the review of a report, was refused.
 */
enum ModerationRefusal: string
{
    case NOT_PERMITTED = 'not_permitted';
    case NOTE_REQUIRED = 'note_required';
    case RELEASE_MISMATCH = 'release_mismatch';
    case ALREADY_REPORTED = 'already_reported';
    case ALREADY_REVIEWED = 'already_reviewed';

    public function message(): string
    {
        return __("filagora-plugins::moderation.refusals.{$this->value}");
    }
}
