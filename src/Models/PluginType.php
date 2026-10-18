<?php

declare(strict_types=1);

namespace Filagora\Plugins\Models;

use Filagora\Core\Support\Tables;
use Filagora\Plugins\Support\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A kind of plugin (a category). A type can name the entitlement scope needed to download its plugins.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property string|null $description
 * @property string|null $required_entitlement_scope
 * @property bool $is_active
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class PluginType extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'description',
        'required_entitlement_scope',
        'is_active',
        'position',
    ];

    public function getTable(): string
    {
        return Tables::name('plugin_types');
    }

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'position' => 'integer',
        ];
    }

    /**
     * @return HasMany<Plugin, $this>
     */
    public function plugins(): HasMany
    {
        return $this->hasMany(Models::class('plugin'), 'plugin_type_id');
    }
}
