<?php

declare(strict_types=1);

namespace Filagora\Plugins\Api;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Plugins\Contracts\ApiUsers;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Who may use the API, in two layers. A request with a token needs the ability the endpoint asks for
 * (`plugins:read`, `plugins:download`, `plugins:write`) on the token, and the matching capability key
 * (`routes.api.keys`) allowed by the access checker for its owner. A browser session needs neither.
 * What the person may then see or download is up to `Visibility` and `DownloadAccess`.
 */
class ApiAccess
{
    public const READ = 'plugins:read';

    public const DOWNLOAD = 'plugins:download';

    public const WRITE = 'plugins:write';

    public function __construct(
        private readonly ApiUsers $users,
        private readonly AccessChecker $access,
    ) {}

    public function user(Request $request): ?Authenticatable
    {
        return $this->users->resolve($request);
    }

    /**
     * The person calling, once they have the ability for the endpoint if they came with a token.
     */
    public function authorize(Request $request, string $ability): ?Authenticatable
    {
        $user = $this->users->resolve($request);

        if (! $this->users->hasToken($request)) {
            return $user;
        }

        if ($user === null) {
            throw new AuthenticationException;
        }

        if (! $this->allowed($user, $ability)) {
            throw new HttpException(403, __('filagora-plugins::api.token_ability_required'));
        }

        return $user;
    }

    /**
     * A signed-in person with a verified email, for the endpoints that change something.
     */
    public function requireVerifiedUser(Request $request, string $ability): Authenticatable
    {
        $user = $this->authorize($request, $ability);

        if ($user === null) {
            throw new AuthenticationException;
        }

        if (method_exists($user, 'hasVerifiedEmail') && ! $user->hasVerifiedEmail()) {
            throw new HttpException(403, __('filagora-plugins::api.email_verification_required'));
        }

        return $user;
    }

    /**
     * Whether the request could use the ability, without refusing it: for the download links a
     * response offers.
     */
    public function can(Request $request, string $ability): bool
    {
        $user = $this->users->resolve($request);

        if (! $this->users->hasToken($request)) {
            return true;
        }

        return $user !== null && $this->allowed($user, $ability);
    }

    public function hasToken(Request $request): bool
    {
        return $this->users->hasToken($request);
    }

    private function allowed(Authenticatable $user, string $ability): bool
    {
        if (! $this->users->tokenCan($user, $ability)) {
            return false;
        }

        $key = config('filagora-plugins.routes.api.keys.'.$this->capability($ability));

        return ! is_string($key) || $key === '' || $this->access->allows($user, $key);
    }

    private function capability(string $ability): string
    {
        return substr($ability, strlen('plugins:'));
    }
}
