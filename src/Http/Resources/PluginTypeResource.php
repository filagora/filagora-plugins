<?php

declare(strict_types=1);

namespace Filagora\Plugins\Http\Resources;

use Filagora\Plugins\Api\ApiPayloads;
use Filagora\Plugins\Models\PluginType;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @property PluginType $resource
 */
class PluginTypeResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return app(ApiPayloads::class)->type($this->resource);
    }
}
