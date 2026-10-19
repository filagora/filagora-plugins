<?php

declare(strict_types=1);

namespace Filagora\Plugins\Archiving;

/**
 * What an archive run would do. `plugins` holds, for each plugin that has something to archive, the
 * releases it keeps (id to the reason) and the ids it would archive.
 */
final readonly class ArchivePlan
{
    /**
     * @param  array<int, array{slug: string, name: string, keep: array<int, string>, archive: list<int>}>  $plugins
     * @param  list<int>  $archiveIds
     * @param  array{plugins: int, active: int, keep: int, eligible: int, selected: int, whole_plugin: int}  $totals
     */
    public function __construct(
        public array $plugins,
        public array $archiveIds,
        public array $totals,
    ) {}
}
