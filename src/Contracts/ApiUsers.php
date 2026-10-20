<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Who is calling the API, and what their token may do. The package asks and never reads a token
 * itself, so it works with Sanctum, Passport, or anything else that signs a request in. The default
 * reads the user from a guard (`routes.api.guard`) and asks the user's `tokenCan()`.
 */
interface ApiUsers
{
    /**
     * The signed-in person for the request, or null for a guest or an unknown token.
     */
    public function resolve(Request $request): ?Authenticatable;

    /**
     * Whether the request carries a token, as opposed to a browser session or nothing.
     */
    public function hasToken(Request $request): bool;

    /**
     * Whether the token the person signed in with may do the ability (for example `plugins:read`).
     */
    public function tokenCan(Authenticatable $user, string $ability): bool;
}
