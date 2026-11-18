<?php

declare(strict_types=1);

namespace Filagora\Plugins\Events;

use Filagora\Plugins\Enums\ApiChange;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * A call to the JSON API changed something, and it went through. Fired once, after the change, for
 * each of the endpoints that change things (and never for a refused one), so a host can keep an audit
 * trail of who did what with which key. The package stores nothing itself.
 *
 * `$tokenId` names the token the person signed in with, when the `ApiUsers` binding can tell
 * (`IdentifiesApiTokens`); it is null for a browser session. `$context` says a little more: the fields
 * changed on a plugin (`fields`), the moderation action taken (`action`), the version uploaded.
 */
final class ApiChangeMade
{
    use Dispatchable;

    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public readonly ApiChange $change,
        public readonly Authenticatable $user,
        public readonly string $pluginSlug,
        public readonly ?string $version,
        public readonly int|string|null $tokenId,
        public readonly ?string $ip,
        public readonly array $context = [],
    ) {}
}
