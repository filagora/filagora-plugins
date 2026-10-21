<?php

declare(strict_types=1);

namespace Filagora\Plugins\Http\Resources;

use Filagora\Plugins\Api\ApiPayloads;
use Filagora\Plugins\Models\PluginRelease;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PluginRelease $resource
 */
class PluginReleaseResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return app(ApiPayloads::class)->release($this->resource, $request);
    }
}
