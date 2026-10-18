<?php

declare(strict_types=1);

namespace Filagora\Plugins\Release;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

/**
 * Puts an uploaded archive on the configured disk under `plugins/`, with a name made of the file's
 * own name, the version and a short random part, after mending backslash path separators.
 */
class ReleaseStorage
{
    public function __construct(private readonly ZipSeparatorRepair $repair) {}

    /**
     * @return array{path: string, originalFilename: string}
     */
    public function store(string $pluginSlug, string $version, UploadedFile $file): array
    {
        $safeSlug = Str::slug($pluginSlug) ?: 'plugin';
        $safeVersion = preg_replace('/[^a-zA-Z0-9._-]+/', '-', $version) ?: 'v';

        $originalFilename = $file->getClientOriginalName() ?: 'plugin.zip';
        $baseName = Str::slug(pathinfo($originalFilename, PATHINFO_FILENAME)) ?: $safeSlug.'-release';
        $targetName = sprintf('%s-%s-%s.zip', $baseName, $safeVersion, Str::lower(Str::random(8)));

        $this->repair->repair($file->getPathname());

        $path = $file->storeAs('plugins', $targetName, (string) config('filagora-plugins.storage.disk'));

        return ['path' => (string) $path, 'originalFilename' => $originalFilename];
    }
}
