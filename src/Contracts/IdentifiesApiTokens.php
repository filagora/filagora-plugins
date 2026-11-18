<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;

/**
 * Optional, beside `ApiUsers`: tells the package which token a request came with, so the record of a
 * change can name it (`ApiChangeMade::$tokenId`). The default (`GuardApiUsers`) does, for Sanctum. An
 * `ApiUsers` that does not implement it leaves the token unnamed; nothing else changes.
 */
interface IdentifiesApiTokens
{
    /**
     * The id of the token the person signed in with, or null for a session or when it cannot be told.
     */
    public function tokenId(Request $request, Authenticatable $user): int|string|null;
}
