<?php

declare(strict_types=1);

namespace Filagora\Plugins\Enums;

/**
 * Where a plugin or a release stands. Only `active` is public.
 */
enum PluginStatus: string
{
    case ACTIVE = 'active';
    case MODERATED = 'moderated';
    case REJECTED_FINAL = 'rejected_final';

    /**
     * Pulled from public view by a security scan. The status before it is kept on the release and
     * restored when a moderator clears it.
     */
    case QUARANTINED = 'quarantined';

    /**
     * A historical release taken out of the scan sweep. Still listed and, by default, downloadable.
     * The status before it is kept on the release and restored when it is un-archived.
     */
    case ARCHIVED = 'archived';

    public function label(): string
    {
        return match ($this) {
            self::ACTIVE => 'Active',
            self::MODERATED => 'Moderated',
            self::REJECTED_FINAL => 'Rejected',
            self::QUARANTINED => 'Quarantined',
            self::ARCHIVED => 'Archived',
        };
    }

    public function color(): string
    {
        return match ($this) {
            self::ACTIVE => 'success',
            self::MODERATED => 'warning',
            self::REJECTED_FINAL, self::QUARANTINED => 'danger',
            self::ARCHIVED => 'gray',
        };
    }

    /**
     * @return array<string, string>
     */
    public static function options(): array
    {
        return collect(self::cases())
            ->mapWithKeys(fn (self $status): array => [$status->value => $status->label()])
            ->all();
    }

    /**
     * Statuses that may be set directly on a release. Archiving goes through the archive service so
     * its bookkeeping and the "never leave a plugin without an active release" rule hold.
     *
     * @return array<string, string>
     */
    public static function directlySettableOptions(): array
    {
        return collect(self::options())->except(self::ARCHIVED->value)->all();
    }
}
