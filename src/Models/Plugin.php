<?php

declare(strict_types=1);

namespace Filagora\Plugins\Models;

use Filagora\Core\Support\Tables;
use Filagora\Core\Support\UserModel;
use Filagora\Plugins\Enums\PluginStatus;
use Filagora\Plugins\Support\Models;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * A plugin in the library: who owns it, what type it is, and its releases.
 *
 * @property int $id
 * @property string $name
 * @property string $slug
 * @property int $plugin_type_id
 * @property string|null $description
 * @property int|null $forum_thread_id
 * @property string|null $github_repository_url
 * @property int|null $owner_id
 * @property PluginStatus $status
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Plugin extends Model
{
    protected $fillable = [
        'name',
        'slug',
        'plugin_type_id',
        'description',
        'forum_thread_id',
        'github_repository_url',
        'owner_id',
        'status',
    ];

    public function getTable(): string
    {
        return Tables::name('plugins');
    }

    protected function casts(): array
    {
        return [
            'status' => PluginStatus::class,
        ];
    }

    /**
     * The public, stable number for this plugin. Here it is the row key; a profile package for a
     * site that already had plugin numbers returns those instead.
     */
    public function canonicalId(): int
    {
        return (int) $this->getKey();
    }

    /**
     * @return BelongsTo<PluginType, $this>
     */
    public function type(): BelongsTo
    {
        return $this->belongsTo(Models::class('type'), 'plugin_type_id');
    }

    /**
     * @return BelongsTo<Model, $this>
     */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(UserModel::class(), 'owner_id');
    }

    /**
     * @return HasMany<PluginRelease, $this>
     */
    public function releases(): HasMany
    {
        return $this->hasMany(Models::class('release'), 'plugin_id')->latest('created_at');
    }

    /**
     * The most recently published active release: the one a plugin page presents as current.
     *
     * @return HasOne<PluginRelease, $this>
     */
    public function latestActiveRelease(): HasOne
    {
        return $this->hasOne(Models::class('release'), 'plugin_id')->ofMany(
            ['created_at' => 'max', 'id' => 'max'],
            fn ($query) => $query->where('status', PluginStatus::ACTIVE->value),
        );
    }

    /**
     * @return HasMany<PluginModeratorNote, $this>
     */
    public function moderatorNotes(): HasMany
    {
        return $this->hasMany(Models::class('moderator_note'), 'plugin_id')->latest('created_at');
    }

    /**
     * @return HasMany<PluginReport, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(Models::class('report'), 'plugin_id');
    }

    /**
     * @return HasMany<PluginSubscription, $this>
     */
    public function subscriptions(): HasMany
    {
        return $this->hasMany(Models::class('subscription'), 'plugin_id');
    }

    /**
     * @return BelongsToMany<Model, $this>
     */
    public function subscribedUsers(): BelongsToMany
    {
        return $this->belongsToMany(UserModel::class(), Tables::name('plugin_subscriptions'), 'plugin_id', 'user_id')
            ->withTimestamps();
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function githubRepositoryUrl(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value): ?string => self::normalizeGithubRepositoryUrl($value),
        );
    }

    /**
     * Accepts `owner/repo`, an SSH remote and github.com URLs and answers a full https URL.
     */
    private static function normalizeGithubRepositoryUrl(?string $value): ?string
    {
        $value = trim((string) $value);

        if ($value === '') {
            return null;
        }

        if (Str::startsWith($value, ['http://', 'https://'])) {
            return $value;
        }

        $normalized = preg_replace('#^(?:git@github\.com:|(?:https?:)?//(?:www\.)?github\.com/)#i', '', $value);
        $normalized = ltrim((string) $normalized, '/');

        if (preg_match('#^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+(?:/.*)?$#', $normalized) === 1) {
            return 'https://github.com/'.$normalized;
        }

        return $value;
    }
}
