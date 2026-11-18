<?php

declare(strict_types=1);

namespace Filagora\Plugins\Api;

use Filagora\Plugins\Contracts\ApiUsers;
use Filagora\Plugins\Contracts\IdentifiesApiTokens;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The person is whoever the configured guard (`routes.api.guard`, the default guard when empty)
 * says, and a token may do an ability when the user's `tokenCan()` says so, and is named by its
 * `currentAccessToken()` (Sanctum's). A request
 * with a token and a user that cannot answer is refused every ability.
 */
final class GuardApiUsers implements ApiUsers, IdentifiesApiTokens
{
    public function resolve(Request $request): ?Authenticatable
    {
        $guard = config('filagora-plugins.routes.api.guard');

        return Auth::guard(is_string($guard) && $guard !== '' ? $guard : null)->user();
    }

    public function hasToken(Request $request): bool
    {
        return trim((string) $request->bearerToken()) !== '';
    }

    public function tokenCan(Authenticatable $user, string $ability): bool
    {
        return method_exists($user, 'tokenCan') && (bool) $user->tokenCan($ability);
    }

    public function tokenId(Request $request, Authenticatable $user): int|string|null
    {
        if (! $this->hasToken($request) || ! method_exists($user, 'currentAccessToken')) {
            return null;
        }

        $token = $user->currentAccessToken();

        return $token instanceof Model ? $token->getKey() : null;
    }
}
