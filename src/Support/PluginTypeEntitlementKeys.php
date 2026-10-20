<?php

declare(strict_types=1);

namespace Filagora\Plugins\Support;

use Filagora\Entitlements\Contracts\EntitlementKeySource;

/**
 * The entitlement scopes set on plugin types, so the definitions catalogue can create a definition
 * for each (`filagora:entitlements:sync`).
 */
final class PluginTypeEntitlementKeys implements EntitlementKeySource
{
    public function keys(): array
    {
        $keys = [];

        foreach (Models::class('type')::query()->whereNotNull('required_entitlement_scope')->distinct()->pluck('required_entitlement_scope') as $scope) {
            $key = trim((string) $scope);

            if ($key !== '') {
                $keys[] = $key;
            }
        }

        return array_values(array_unique($keys));
    }
}
