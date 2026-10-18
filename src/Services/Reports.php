<?php

declare(strict_types=1);

namespace Filagora\Plugins\Services;

use Carbon\CarbonImmutable;
use Filagora\Core\Contracts\AccessChecker;
use Filagora\Plugins\Enums\ModerationRefusal;
use Filagora\Plugins\Enums\PluginReportStatus;
use Filagora\Plugins\Events\PluginReported;
use Filagora\Plugins\Events\PluginReportReviewed;
use Filagora\Plugins\Exceptions\ModerationRefused;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Models\PluginRelease;
use Filagora\Plugins\Models\PluginReport;
use Filagora\Plugins\Support\Gates;
use Filagora\Plugins\Support\Models;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Members report a plugin, or one of its releases; moderators review the reports.
 */
class Reports
{
    public function __construct(private readonly AccessChecker $access) {}

    /**
     * One open report per person per plugin. Whether the plugin is one they may see is the
     * caller's to check.
     *
     * @throws ModerationRefused
     */
    public function report(Authenticatable $reporter, Plugin $plugin, string $reason, ?PluginRelease $release = null): PluginReport
    {
        if ($release !== null && $release->plugin_id !== $plugin->getKey()) {
            throw new ModerationRefused(ModerationRefusal::RELEASE_MISMATCH);
        }

        $alreadyReported = Models::class('report')::query()
            ->where('plugin_id', $plugin->getKey())
            ->where('reporter_id', $reporter->getAuthIdentifier())
            ->where('status', PluginReportStatus::OPEN->value)
            ->exists();

        if ($alreadyReported) {
            throw new ModerationRefused(ModerationRefusal::ALREADY_REPORTED);
        }

        $report = Models::class('report')::query()->create([
            'plugin_id' => $plugin->getKey(),
            'plugin_release_id' => $release?->getKey(),
            'reporter_id' => $reporter->getAuthIdentifier(),
            'reason' => trim($reason),
            'status' => PluginReportStatus::OPEN->value,
        ]);

        PluginReported::dispatch($report);

        return $report;
    }

    /**
     * Mark a report reviewed or dismissed. The reporter hears when the status changes.
     *
     * @throws ModerationRefused
     */
    public function review(Authenticatable $moderator, PluginReport $report, PluginReportStatus $status, ?string $note = null): PluginReport
    {
        if (! $this->access->allows($moderator, Gates::key(Gates::MODERATE))) {
            throw new ModerationRefused(ModerationRefusal::NOT_PERMITTED);
        }

        $note = trim((string) $note);

        $report->fill([
            'status' => $status,
            'resolution_note' => $note !== '' ? $note : null,
            'reviewed_by_id' => $moderator->getAuthIdentifier(),
            'reviewed_at' => CarbonImmutable::now(),
        ]);

        $changed = $report->isDirty('status');
        $report->save();

        if ($changed) {
            PluginReportReviewed::dispatch($report);
        }

        return $report;
    }
}
