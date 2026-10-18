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
 * A member following a plugin, to hear about its new releases.
 *
 * @property int $id
 * @property int $plugin_id
 * @property int $user_id
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PluginSubscription extends Model
{
    protected $fillable = [
        'plugin_id',
        'user_id',
    ];

    public function getTable(): string
    {
        return Tables::name('plugin_subscriptions');
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
    public function user(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'user_id');
    }
}
