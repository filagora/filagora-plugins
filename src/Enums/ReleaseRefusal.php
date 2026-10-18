<?php

declare(strict_types=1);

namespace Filagora\Plugins\Enums;

/**
 * Why a plugin or release submission was refused.
 */
enum ReleaseRefusal: string
{
    case SUBMISSIONS_PAUSED = 'submissions_paused';
    case INVALID_VERSION = 'invalid_version';
    case DUPLICATE_VERSION = 'duplicate_version';
    case PENDING_RELEASES = 'pending_releases';
    case UNKNOWN_TYPE = 'unknown_type';
    case NOT_AN_ARCHIVE = 'not_an_archive';
    case TOO_MANY_FILES = 'too_many_files';
    case TOO_LARGE = 'too_large';
    case UNSAFE_PATH = 'unsafe_path';
    case WHITESPACE_IN_PATH = 'whitespace_in_path';
    case REJECTED_BY_EXTENSION = 'rejected_by_extension';

    /**
     * The form field the refusal belongs to.
     */
    public function field(): string
    {
        return match ($this) {
            self::INVALID_VERSION, self::DUPLICATE_VERSION => 'version',
            self::PENDING_RELEASES => 'supersede_pending_releases',
            self::UNKNOWN_TYPE => 'plugin_type_id',
            self::NOT_AN_ARCHIVE, self::TOO_MANY_FILES, self::TOO_LARGE, self::UNSAFE_PATH, self::WHITESPACE_IN_PATH => 'zip_file',
            self::SUBMISSIONS_PAUSED, self::REJECTED_BY_EXTENSION => 'release',
        };
    }

    public function message(): string
    {
        return __("filagora-plugins::releases.refusals.{$this->value}");
    }
}
