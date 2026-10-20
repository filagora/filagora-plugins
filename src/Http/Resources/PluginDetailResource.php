<?php

declare(strict_types=1);

namespace Filagora\Plugins\Http\Resources;

use Illuminate\Http\Request;

class PluginDetailResource extends PluginSummaryResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [...parent::toArray($request), 'releases' => PluginReleaseResource::collection($this->resource->releases)->resolve($request)];
    }
}
