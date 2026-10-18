<?php

declare(strict_types=1);

namespace Filagora\Plugins\Support;

/**
 * The one idea of what a release's version string means. Whatever a person types, a release is
 * stored and compared by its canonical form, so "v1.0.1" and "1.0.1" are the same release.
 */
final class PluginVersion
{
    /**
     * The first run of dot-separated digits with a "v" in front: "Ver5.6.0" is "v5.6.0" and
     * "1.0.1-r8256" is "v1.0.1". Null when there are no digits at all.
     */
    public static function canonical(string $raw): ?string
    {
        if (preg_match('/\d+(?:\.\d+)*/', $raw, $matches) !== 1) {
            return null;
        }

        return 'v'.$matches[0];
    }

    /**
     * Whether a new release may be submitted as this: an optional "v", then two to four numeric
     * parts, nothing else.
     */
    public static function isValidSubmission(string $raw): bool
    {
        return preg_match('/^v?\d+(\.\d+){1,3}$/i', trim($raw)) === 1;
    }

    /**
     * The canonical form without the "v", for `version_compare()`; "0" when there are no digits,
     * so an unreadable version sorts lowest instead of breaking the comparison.
     */
    public static function comparable(string $raw): string
    {
        $canonical = self::canonical($raw);

        return $canonical === null ? '0' : substr($canonical, 1);
    }
}
