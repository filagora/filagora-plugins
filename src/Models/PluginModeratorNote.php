<?php

declare(strict_types=1);

namespace Filagora\Plugins\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A staff note on a plugin: why it was moderated, who it was handed to.
 *
 * @property int $id
 * @property int $plugin_id
 * @property int|null $moderator_id
 * @property string $note
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PluginModeratorNote extends Model
{
    protected $fillable = [
        'plugin_id',
        'moderator_id',
        'note',
    ];

    public function getTable(): string
    {
        return Tables::name('plugin_moderator_notes');
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
    public function moderator(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'moderator_id');
    }
}
