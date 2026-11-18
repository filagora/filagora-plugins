<?php

declare(strict_types=1);

namespace Filagora\Plugins\Api;

/**
 * Where the OpenAPI document for the JSON API (`routes.api.enabled`) is, shipped with the package so a
 * host can serve it, or add its own fields to it, instead of writing the contract again. A host's
 * `ApiFields` add properties to the plugin and release schemas; the document names the package's own.
 */
final class OpenApiSpec
{
    public static function path(): string
    {
        return dirname(__DIR__, 2).'/resources/openapi/filagora-plugins.yaml';
    }
}
