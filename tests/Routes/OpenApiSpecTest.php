<?php

declare(strict_types=1);

use Filagora\Plugins\Api\ApiAccess;
use Filagora\Plugins\Api\OpenApiSpec;
use Illuminate\Routing\Route as LaravelRoute;
use Illuminate\Support\Facades\Route;
use Symfony\Component\Yaml\Yaml;

/**
 * @return array<string, mixed>
 */
function openApiDocument(): array
{
    /** @var array<string, mixed> */
    return Yaml::parseFile(OpenApiSpec::path());
}

/**
 * The operations the document describes, as "method /path".
 *
 * @return list<string>
 */
function documentedOperations(): array
{
    $operations = [];

    foreach (openApiDocument()['paths'] as $path => $item) {
        foreach (array_keys($item) as $key) {
            if (in_array($key, ['get', 'post', 'put', 'patch', 'delete'], true)) {
                $operations[] = $key.' '.$path;
            }
        }
    }

    sort($operations);

    return $operations;
}

it('describes every route the API registers, and only those', function () {
    $served = collect(Route::getRoutes()->getRoutes())
        ->filter(fn (LaravelRoute $route): bool => str_starts_with((string) $route->getName(), 'filagora-plugins.api.'))
        ->flatMap(fn (LaravelRoute $route): array => collect($route->methods())
            ->reject(fn (string $method): bool => in_array($method, ['HEAD', 'OPTIONS'], true))
            ->map(fn (string $method): string => strtolower($method).' /'.$route->uri())
            ->all())
        ->sort()
        ->values()
        ->all();

    expect($served)->not->toBeEmpty();
    expect(documentedOperations())->toBe($served);
});

it('gives each operation its own operation id', function () {
    $ids = [];

    foreach (openApiDocument()['paths'] as $item) {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            if (isset($item[$method])) {
                $ids[] = $item[$method]['operationId'] ?? null;
            }
        }
    }

    expect($ids)->not->toContain(null)->and($ids)->toBe(array_values(array_unique($ids)));
});

it('only points at parts of the document that exist', function () {
    $document = openApiDocument();
    $broken = [];

    array_walk_recursive($document, function ($value, $key) use ($document, &$broken): void {
        if ($key !== '$ref' || ! is_string($value)) {
            return;
        }

        $node = $document;

        foreach (explode('/', ltrim($value, '#/')) as $part) {
            if (! is_array($node) || ! array_key_exists($part, $node)) {
                $broken[] = $value;

                return;
            }

            $node = $node[$part];
        }
    });

    expect($broken)->toBe([]);
});

it('asks only for abilities a token can carry', function () {
    $known = [ApiAccess::READ, ApiAccess::DOWNLOAD, ApiAccess::WRITE];
    $asked = [];

    foreach (openApiDocument()['paths'] as $item) {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            foreach ($item[$method]['security'] ?? [] as $requirement) {
                $asked = [...$asked, ...($requirement['bearer'] ?? [])];
            }
        }
    }

    expect($asked)->not->toBeEmpty()->and(array_diff($asked, $known))->toBe([]);
});

it('names the 429 on every operation, since each is limited', function () {
    $without = [];

    foreach (openApiDocument()['paths'] as $path => $item) {
        foreach (['get', 'post', 'put', 'patch', 'delete'] as $method) {
            if (isset($item[$method]) && ! isset($item[$method]['responses']['429'])) {
                $without[] = $method.' '.$path;
            }
        }
    }

    expect($without)->toBe([]);
});

it('versions the document as the API, the last part of the route prefix, and not as the package', function () {
    expect(openApiDocument()['info']['version'])->toBe(basename((string) config('filagora-plugins.routes.api.prefix')));
});
