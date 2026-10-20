<?php

declare(strict_types=1);

namespace Filagora\Plugins\Privacy;

use Filagora\Core\Contracts\PrivacyContributor;
use Filagora\Plugins\Support\Models;
use Illuminate\Database\Eloquent\Model;

/**
 * What the plugin library holds about a person, for the compliance package's export: the plugins
 * they own, the releases they uploaded, the plugins they follow and the reports they made.
 *
 * Erasure removes what is only theirs (who they follow, what they reported). The plugins and
 * releases are public work and stay, credited to the account the host's anonymiser has by then made
 * anonymous; staff notes about a plugin stay too.
 */
final class PluginsDataContributor implements PrivacyContributor
{
    public function key(): string
    {
        return 'plugins';
    }

    public function export(Model $user): array
    {
        return [
            'plugins' => Models::class('plugin')::query()->where('owner_id', $user->getKey())->orderBy('id')
                ->get(['id', 'name', 'slug', 'description', 'github_repository_url', 'status', 'created_at'])->toArray(),
            'releases' => Models::class('release')::query()->where('uploaded_by_id', $user->getKey())->orderBy('id')
                ->get(['id', 'plugin_id', 'version', 'changelog', 'status', 'created_at'])->toArray(),
            'subscriptions' => Models::class('subscription')::query()->where('user_id', $user->getKey())->orderBy('id')
                ->get(['plugin_id', 'created_at'])->toArray(),
            'reports' => Models::class('report')::query()->where('reporter_id', $user->getKey())->orderBy('id')
                ->get(['id', 'plugin_id', 'plugin_release_id', 'reason', 'status', 'resolution_note', 'created_at'])->toArray(),
        ];
    }

    public function erase(Model $user): void
    {
        Models::class('subscription')::query()->where('user_id', $user->getKey())->delete();
        Models::class('report')::query()->where('reporter_id', $user->getKey())->delete();
    }
}
