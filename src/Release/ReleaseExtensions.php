<?php

declare(strict_types=1);

namespace Filagora\Plugins\Release;

use Filagora\Plugins\Contracts\ReleaseExtension;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;

/**
 * The extensions a profile package has registered, run in the order they were added.
 */
final class ReleaseExtensions
{
    /** @var list<ReleaseExtension> */
    private array $extensions = [];

    public function register(ReleaseExtension $extension): void
    {
        $this->extensions[] = $extension;
    }

    /**
     * @return array<string, mixed>
     */
    public function prepare(?Plugin $plugin, ReleaseInput $input, string $canonicalVersion): array
    {
        $attributes = [];

        foreach ($this->extensions as $extension) {
            $attributes = [...$attributes, ...$extension->prepare($plugin, $input, $canonicalVersion)];
        }

        return $attributes;
    }

    public function created(Plugin $plugin, PluginRelease $release, ReleaseInput $input): void
    {
        foreach ($this->extensions as $extension) {
            $extension->created($plugin, $release, $input);
        }
    }
}
