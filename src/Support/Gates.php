<?php

declare(strict_types=1);

namespace Filagora\Plugins\Support;

/**
 * The access keys behind the package's abilities, as set in `filagora-plugins.gates`
 * (`ability => key`). A host remaps a key there; the ability keeps its name.
 */
final class Gates
{
    public const MODERATE = 'filagora.plugins.moderate';

    public const AUTO_PUBLISH = 'filagora.plugins.auto_publish';

    public static function key(string $ability): string
    {
        $key = ((array) config('filagora-plugins.gates', []))[$ability] ?? null;

        return is_string($key) && $key !== '' ? $key : $ability;
    }
}
