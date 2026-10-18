<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Filagora\Plugins\Exceptions\ReleaseRefused;

/**
 * Looks at an uploaded archive before it is stored. A hostile archive must never reach the disk,
 * so the checks that have no legitimate reason to trip refuse the upload outright.
 */
interface ArchiveInspector
{
    /**
     * @param  string  $path  The uploaded file on the local disk.
     *
     * @throws ReleaseRefused
     */
    public function assertSafe(string $path): void;
}
