<?php

declare(strict_types=1);

namespace Filagora\Plugins\Api;

use Filagora\Plugins\Contracts\ApiFields;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;

final class NoApiFields implements ApiFields
{
    public function pluginFields(Plugin $plugin): array
    {
        return [];
    }

    public function releaseFields(PluginRelease $release): array
    {
        return [];
    }

    public function releaseRules(): array
    {
        return [];
    }
}
