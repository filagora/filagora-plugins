<?php

declare(strict_types=1);

namespace Filagora\Plugins\Http\Middleware;

use Closure;
use Filagora\Plugins\Api\ApiAccess;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Lets shared caches (a CDN, a proxy) hold the API's reads, but only what a guest was shown.
 *
 * What the API answers depends on who asks (the types and plugins a person may see), so every
 * response varies on `Authorization`, and an answer given to a signed-in person, with a token or a
 * session, is left private. A guest's successful read is public for `routes.api.cache_seconds`
 * (0 switches this off) and carries an ETag, so a client that asks again with `If-None-Match` gets a
 * 304 and a cache that expired can revalidate.
 *
 * @internal
 */
final class CachePublicReads
{
    public function __construct(private readonly ApiAccess $api) {}

    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);
        $seconds = (int) config('filagora-plugins.routes.api.cache_seconds', 60);

        if ($seconds <= 0) {
            return $response;
        }

        $response->setVary(['Authorization'], false);

        if (! $request->isMethodCacheable() || $response->getStatusCode() !== 200) {
            return $response;
        }

        if ($request->headers->has('Authorization') || $this->api->user($request) !== null) {
            $response->setPrivate();

            return $response;
        }

        $response->setPublic();
        $response->setMaxAge($seconds);
        $response->setSharedMaxAge($seconds);
        $response->setEtag(md5((string) $response->getContent()));
        $response->isNotModified($request);

        return $response;
    }
}
