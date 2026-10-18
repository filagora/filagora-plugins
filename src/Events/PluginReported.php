<?php

declare(strict_types=1);

namespace Filagora\Plugins\Events;

use Filagora\Plugins\Models\PluginReport;
use Illuminate\Foundation\Events\Dispatchable;

final class PluginReported
{
    use Dispatchable;

    public function __construct(public readonly PluginReport $report) {}
}
