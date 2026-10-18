<?php

declare(strict_types=1);

namespace Filagora\Plugins\Release;

use Filagora\Plugins\Contracts\ArchiveInspector;
use Filagora\Plugins\Enums\ReleaseRefusal;
use Filagora\Plugins\Exceptions\ReleaseRefused;
use ZipArchive;

/**
 * Refuses an upload that is not a ZIP, holds an entry that would escape the folder it is
 * extracted into (zip-slip), has a space in a path, or would expand to an unreasonable number of
 * files or bytes (a zip bomb). Runs on the upload before it is stored. The limits are
 * `filagora-plugins.archive.max_entries` and `max_uncompressed_bytes`.
 */
final class ZipArchiveInspector implements ArchiveInspector
{
    public function assertSafe(string $path): void
    {
        if (! class_exists(ZipArchive::class)) {
            return;
        }

        $zip = new ZipArchive;

        // Read-only, so closing it can never write back to, or delete, the upload.
        if (! is_file($path) || $zip->open($path, ZipArchive::RDONLY) !== true) {
            throw new ReleaseRefused(ReleaseRefusal::NOT_AN_ARCHIVE);
        }

        try {
            $this->assertEntries($zip);
        } finally {
            $zip->close();
        }
    }

    private function assertEntries(ZipArchive $zip): void
    {
        $maxEntries = (int) config('filagora-plugins.archive.max_entries', 5000);
        $maxBytes = (int) config('filagora-plugins.archive.max_uncompressed_bytes', 200 * 1024 * 1024);

        if ($zip->numFiles > $maxEntries) {
            throw new ReleaseRefused(ReleaseRefusal::TOO_MANY_FILES, __('filagora-plugins::releases.refusals.too_many_files', ['count' => $zip->numFiles]));
        }

        $total = 0;

        for ($index = 0; $index < $zip->numFiles; $index++) {
            $name = $zip->getNameIndex($index);

            if (is_string($name) && $name !== '') {
                if ($this->escapesExtractionFolder($name)) {
                    throw new ReleaseRefused(ReleaseRefusal::UNSAFE_PATH, __('filagora-plugins::releases.refusals.unsafe_path', ['path' => $name]));
                }

                if (preg_match('/\s/', $name) === 1) {
                    throw new ReleaseRefused(ReleaseRefusal::WHITESPACE_IN_PATH, __('filagora-plugins::releases.refusals.whitespace_in_path', ['path' => $name]));
                }
            }

            $stat = $zip->statIndex($index);
            $total += is_array($stat) ? $stat['size'] : 0;

            if ($total > $maxBytes) {
                throw new ReleaseRefused(ReleaseRefusal::TOO_LARGE);
            }
        }
    }

    private function escapesExtractionFolder(string $name): bool
    {
        $normalized = str_replace('\\', '/', $name);

        if (str_starts_with($normalized, '/') || preg_match('/^[a-zA-Z]:/', $normalized) === 1) {
            return true;
        }

        return in_array('..', explode('/', $normalized), true);
    }
}
