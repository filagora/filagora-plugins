<?php

declare(strict_types=1);

namespace Filagora\Plugins\Release;

use Illuminate\Http\UploadedFile;

/**
 * What a person submits as a release. `extra` carries whatever a profile package's
 * `ReleaseExtension` reads (for example a compatibility list), untouched.
 */
final readonly class ReleaseInput
{
    /**
     * @param  array<string, mixed>  $extra
     */
    public function __construct(
        public string $version,
        public UploadedFile $file,
        public ?string $changelog = null,
        public bool $supersedePending = false,
        public array $extra = [],
    ) {}
}
