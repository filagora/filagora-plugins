<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Filagora\Plugins\Exceptions\ReleaseRefused;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Release\ReleaseInput;

/**
 * Where a profile package adds what only its kind of plugin needs to a submission: extra checks
 * on the archive, extra columns on the release, extra rows once it exists. Register one with
 * `ReleaseExtensions::register()`.
 */
interface ReleaseExtension
{
    /**
     * Check the submission and answer the extra attributes to store on the release row (columns
     * the profile added). The package's own attributes, such as the status, are set after these and
     * win, so an extension cannot publish a release.
     *
     * @param  Plugin|null  $plugin  Null for the first release of a new plugin.
     * @return array<string, mixed>
     *
     * @throws ReleaseRefused
     */
    public function prepare(?Plugin $plugin, ReleaseInput $input, string $canonicalVersion): array;

    /**
     * Called once the release row exists, inside the same transaction.
     */
    public function created(Plugin $plugin, PluginRelease $release, ReleaseInput $input): void;
}
