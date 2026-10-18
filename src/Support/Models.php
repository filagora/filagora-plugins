<?php

declare(strict_types=1);

namespace Filagora\Plugins\Support;

use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginModeratorNote;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginReport;
use Filagora\Plugins\Models\PluginSubscription;
use Filagora\Plugins\Models\PluginType;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Resolves the package's model classes. The package never names a model class directly:
 * a host swaps one under `filagora.models.<key>` (or `filagora-plugins.models.<key>`) and
 * every relation and query follows.
 */
final class Models
{
    /**
     * @return ($key is 'type' ? class-string<PluginType> : ($key is 'plugin' ? class-string<Plugin> : ($key is 'release' ? class-string<PluginRelease> : ($key is 'report' ? class-string<PluginReport> : ($key is 'subscription' ? class-string<PluginSubscription> : ($key is 'moderator_note' ? class-string<PluginModeratorNote> : class-string<Model>))))))
     */
    public static function class(string $key): string
    {
        /** @var class-string<Model>|null $class */
        $class = config("filagora.models.{$key}") ?? config("filagora-plugins.models.{$key}");

        return $class ?? throw new InvalidArgumentException("No Filagora plugins model is registered for [{$key}].");
    }
}
