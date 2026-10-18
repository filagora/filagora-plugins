<?php

declare(strict_types=1);

namespace Filagora\Plugins\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Support\Models;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * One uploaded version of a plugin, and where it stands in review, quarantine and archive.
 *
 * @property int $id
 * @property int $plugin_id
 * @property int|null $uploaded_by_id
 * @property string $version
 * @property int|null $internal_version Numbered by the model when a release is created.
 * @property string $zip_path
 * @property string|null $zip_filename
 * @property int|null $zip_size
 * @property string|null $checksum_sha256
 * @property int $download_count
 * @property string|null $changelog
 * @property int|null $latest_security_scan_id
 * @property string|null $status_before_quarantine
 * @property Carbon|null $quarantined_at
 * @property int|null $quarantine_scan_id
 * @property Carbon|null $quarantine_cleared_at
 * @property int|null $quarantine_cleared_by_id
 * @property string|null $quarantine_note
 * @property string|null $status_before_archive
 * @property Carbon|null $archived_at
 * @property int|null $archived_by_id
 * @property PluginStatus $status
 * @property Carbon|null $approved_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PluginRelease extends Model
{
    protected $fillable = [
        'plugin_id',
        'uploaded_by_id',
        'version',
        'internal_version',
        'zip_path',
        'zip_filename',
        'zip_size',
        'checksum_sha256',
        'download_count',
        'changelog',
        'status',
        'approved_at',
        'latest_security_scan_id',
        'status_before_quarantine',
        'quarantined_at',
        'quarantine_scan_id',
        'quarantine_cleared_at',
        'quarantine_cleared_by_id',
        'quarantine_note',
        'status_before_archive',
        'archived_at',
        'archived_by_id',
    ];

    public function getTable(): string
    {
        return Tables::name('plugin_releases');
    }

    protected function casts(): array
    {
        return [
            'internal_version' => 'integer',
            'zip_size' => 'integer',
            'download_count' => 'integer',
            'status' => PluginStatus::class,
            'approved_at' => 'datetime',
            'quarantined_at' => 'datetime',
            'quarantine_cleared_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (PluginRelease $release): void {
            if ($release->uploaded_by_id === null && Auth::check()) {
                $release->uploaded_by_id = (int) Auth::id();
            }

            if ($release->internal_version === null) {
                $max = static::query()->where('plugin_id', $release->plugin_id)->max('internal_version');
                $release->internal_version = ((int) $max) + 1;
            }
        });

        static::saving(function (PluginRelease $release): void {
            // A partial select that never loaded the status must not wipe a correct approved_at.
            if (! array_key_exists('status', $release->getAttributes())) {
                return;
            }

            if ($release->status === PluginStatus::ACTIVE) {
                $release->approved_at ??= now();
            } elseif (! in_array($release->status, [PluginStatus::QUARANTINED, PluginStatus::ARCHIVED], true)) {
                // A quarantine or an archive is a hold, not a reversal: keep approved_at so lifting
                // it restores the release exactly.
                $release->approved_at = null;
            }

            // Leaving ARCHIVED by any route clears the archive bookkeeping so it cannot go stale.
            if ($release->isDirty('status')
                && $release->getRawOriginal('status') === PluginStatus::ARCHIVED->value
                && $release->status !== PluginStatus::ARCHIVED) {
                $release->status_before_archive = null;
                $release->archived_at = null;
                $release->archived_by_id = null;
            }
        });

        static::saving(function (PluginRelease $release): void {
            if ($release->exists && ! $release->isDirty('zip_path') && ! $release->isDirty('zip_filename')) {
                return;
            }

            if ($release->zip_path === '') {
                return;
            }

            $disk = Storage::disk((string) config('filagora-plugins.storage.disk'));

            if (! $disk->exists($release->zip_path)) {
                return;
            }

            if (! is_string($release->zip_filename) || $release->zip_filename === '') {
                $release->zip_filename = basename($release->zip_path);
            }

            $path = $disk->path($release->zip_path);

            if (is_file($path)) {
                $release->zip_size = filesize($path) ?: null;
                $release->checksum_sha256 = hash_file('sha256', $path) ?: null;
            }
        });
    }

    /**
     * @return BelongsTo<Plugin, $this>
     */
    public function plugin(): BelongsTo
    {
        return $this->belongsTo(Models::class('plugin'), 'plugin_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'uploaded_by_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function quarantineClearedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'quarantine_cleared_by_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function archivedBy(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'archived_by_id');
    }

    public function isQuarantined(): bool
    {
        return $this->quarantined_at !== null;
    }

    public function isArchived(): bool
    {
        return $this->status === PluginStatus::ARCHIVED;
    }

    /**
     * The version without a leading "v", for places that print their own "v".
     */
    public function displayVersion(): string
    {
        return ltrim(trim($this->version), 'vV');
    }

    /**
     * @param  Builder<PluginRelease>  $query
     * @return Builder<PluginRelease>
     */
    public function scopeQuarantined(Builder $query): Builder
    {
        return $query->whereNotNull('quarantined_at');
    }
}
