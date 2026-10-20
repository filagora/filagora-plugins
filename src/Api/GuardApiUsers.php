<?php

declare(strict_types=1);

namespace Filagora\Plugins\Api;

use Filagora\Plugins\Contracts\ApiUsers;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The person is whoever the configured guard (`routes.api.guard`, the default guard when empty)
 * says, and a token may do an ability when the user's `tokenCan()` says so (Sanctum's). A request
 * with a token and a user that cannot answer is refused every ability.
 */
final class GuardApiUsers implements ApiUsers
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
}
