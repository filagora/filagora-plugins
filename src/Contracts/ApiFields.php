<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;

/**
 * What a profile package adds to the API: fields on every plugin and release it returns, and rules
 * for the extra fields it accepts when a release is uploaded (they reach its `ReleaseExtension` in
 * `ReleaseInput::$extra`). The default adds nothing.
 */
interface ApiFields
{
    /**
     * @return array<string, mixed>
     */
    public function pluginFields(Plugin $plugin): array;

    /**
     * @return array<string, mixed>
     */
    public function releaseFields(PluginRelease $release): array;

    /**
     * Validation rules for extra fields of an uploaded release, by field name.
     *
     * @return array<string, mixed>
     */
    public function releaseRules(): array;
}
