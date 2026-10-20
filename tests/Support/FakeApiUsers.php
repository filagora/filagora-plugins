<?php

declare(strict_types=1);

namespace Filagora\Plugins\Tests\Support;

use Filagora\Plugins\Contracts\ApiUsers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Bearer tokens kept in memory: a token belongs to a person and carries abilities. A request with no
 * bearer token is signed in by the session, as the default does.
 */
final class FakeApiUsers implements ApiUsers
{
    /** @var array<string, array{user: Authenticatable, abilities: list<string>}> */
    private array $tokens = [];

    public function issue(string $token, Authenticatable $user, string ...$abilities): void
    {
        // One instance per token, as Sanctum hands back a user carrying the token it signed in with.
        $this->tokens[$token] = ['user' => clone $user, 'abilities' => array_values($abilities)];
    }

    public function resolve(Request $request): ?Authenticatable
    {
        $token = (string) $request->bearerToken();

        return $token === '' ? $request->user() : ($this->tokens[$token]['user'] ?? null);
    }

    public function hasToken(Request $request): bool
    {
        return trim((string) $request->bearerToken()) !== '';
    }

    public function tokenCan(Authenticatable $user, string $ability): bool
    {
        foreach ($this->tokens as $issued) {
            if ($issued['user'] === $user) {
                return in_array($ability, $issued['abilities'], true);
            }
        }

        return false;
    }
}
