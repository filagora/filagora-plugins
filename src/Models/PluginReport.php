<?php

declare(strict_types=1);

namespace Filagora\Plugins\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Enums\PluginReportStatus;
use Filagora\Plugins\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A member's report about a plugin, or about one of its releases.
 *
 * @property int $id
 * @property int $plugin_id
 * @property int|null $plugin_release_id
 * @property int $reporter_id
 * @property string $reason
 * @property PluginReportStatus $status
 * @property int|null $reviewed_by_id
 * @property Carbon|null $reviewed_at
 * @property string|null $resolution_note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PluginReport extends Model
{
    protected $fillable = [
        'plugin_id',
        'plugin_release_id',
        'reporter_id',
        'reason',
        'status',
        'reviewed_by_id',
        'reviewed_at',
        'resolution_note',
    ];

    public function getTable(): string
    {
        return Tables::name('plugin_reports');
    }

    protected function casts(): array
    {
        return [
            'status' => PluginReportStatus::class,
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<Plugin, $this>
     */
    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Models::class('plugin'), 'plugin_id');
    }

    /**
     * @return BelongsTo<PluginRelease, $this>
     */
    public function release(): BelongsTo
    {
        return $this->belongsTo(Models::class('release'), 'plugin_release_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function reporter(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'reporter_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'reviewed_by_id');
    }
}
