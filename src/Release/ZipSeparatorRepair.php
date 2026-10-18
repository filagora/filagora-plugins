<?php

declare(strict_types=1);

namespace Filagora\Plugins\Release;

use ZipArchive;

/**
 * Repairs release ZIP archives whose internal entries use a backslash instead of the
 * ZIP-spec-mandated `/` as a path separator. Confirmed root cause: these
 * archives were built by old Windows-based zip tools (their entries carry a
 * "created by MS-DOS/FAT" header byte) that wrote entry names using the local
 * OS separator instead of the spec-mandated `/`. Lenient extractors silently
 * correct this; PHP's ZipArchive and most Linux `unzip` builds do not, so
 * every file in the archive extracts flat with a literal backslash in its
 * name instead of into the intended nested directories.
 */
final class ZipSeparatorRepair
{
    /**
     * @return list<string> backslash-separated entry names found (empty if
     *                      none, or the archive is missing/unreadable)
     */
    public function findBackslashEntries(string $absolutePath): array
    {
        if (! class_exists(ZipArchive::class) || ! is_file($absolutePath)) {
            return [];
        }

        $zip = new ZipArchive;
        if ($zip->open($absolutePath, ZipArchive::RDONLY) !== true) {
            return [];
        }

        try {
            $entries = [];

            for ($index = 0; $index < $zip->numFiles; $index++) {
                $name = $zip->getNameIndex($index);
                if (is_string($name) && str_contains($name, '\\')) {
                    $entries[] = $name;
                }
            }

            return $entries;
        } finally {
            $zip->close();
        }
    }

    /**
     * Normalizes every backslash-separated entry name to use `/`. File
     * entries are renamed in place — their content, compression and metadata
     * are untouched. 0-byte directory-marker entries (name ends in the
     * separator) can't be renamed to a trailing-slash name via
     * ZipArchive::renameName() (it returns "Invalid argument" for those), so
     * those are deleted and recreated via addEmptyDir() instead.
     *
     * @return array{opened:bool, renamed:int, recreated_dirs:int, failed:list<string>}
     */
    public function repair(string $absolutePath): array
    {
        $result = ['opened' => false, 'renamed' => 0, 'recreated_dirs' => 0, 'failed' => []];

        // ZipArchive::open() on a 0-byte file succeeds (it's treated as a
        // brand-new empty archive), and close()'ing that unmodified "empty"
        // archive deletes the underlying file outright. A valid zip is never
        // 0 bytes, so bail out before that destructive round-trip can occur.
        if (! class_exists(ZipArchive::class) || ! is_file($absolutePath) || filesize($absolutePath) === 0) {
            return $result;
        }

        $zip = new ZipArchive;
        if ($zip->open($absolutePath) !== true) {
            return $result;
        }

        $result['opened'] = true;

        try {
            $names = [];
            for ($index = 0; $index < $zip->numFiles; $index++) {
                $names[] = $zip->getNameIndex($index);
            }

            foreach ($names as $name) {
                if (! is_string($name) || ! str_contains($name, '\\')) {
                    continue;
                }

                $normalized = str_replace('\\', '/', $name);

                if ($zip->renameName($name, $normalized)) {
                    $result['renamed']++;

                    continue;
                }

                if (str_ends_with($normalized, '/') && $this->isZeroByteEntry($zip, $name)) {
                    // deleteName()+addEmptyDir() creates a brand new entry
                    // rather than editing the old one in place, so unlike
                    // renameName() it does NOT inherit the original entry's
                    // stored Unix permission bits (external_attr) — it gets
                    // libzip's default (0777) instead. Capture the original
                    // before deleting and reapply it to the recreated entry.
                    $hasAttributes = $zip->getExternalAttributesName($name, $opsys, $attr);

                    $zip->deleteName($name);
                    if ($zip->addEmptyDir(rtrim($normalized, '/'))) {
                        if ($hasAttributes) {
                            $zip->setExternalAttributesName($normalized, $opsys, $attr);
                        }

                        $result['recreated_dirs']++;

                        continue;
                    }
                }

                $result['failed'][] = $name;
            }
        } finally {
            $zip->close();
        }

        return $result;
    }

    private function isZeroByteEntry(ZipArchive $zip, string $name): bool
    {
        $stat = $zip->statName($name);

        return is_array($stat) && $stat['size'] === 0;
    }
}
