<?php

declare(strict_types=1);

namespace Filagora\Plugins\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;

/**
 * Relations answer a plain model; the package's events and mail want someone who can sign in.
 */
final class Users
{
    public static function of(?Model $model): ?Authenticatable
    {
        return $model instanceof Authenticatable ? $model : null;
    }
}
