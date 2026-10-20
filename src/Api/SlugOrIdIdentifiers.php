<?php

declare(strict_types=1);

namespace Filagora\Plugins\Api;

use Filagora\Plugins\Contracts\PluginIdentifiers;
use Filagora\Plugins\Models\Plugin;
use Filagora\Plugins\Services\Visibility;
use Illuminate\Contracts\Auth\Authenticatable;

final class SlugOrIdIdentifiers implements PluginIdentifiers
{
    public function __construct(private readonly Visibility $visibility) {}

    public function find(array $row, ?Authenticatable $viewer): ?Plugin
    {
        $slug = trim((string) ($row['slug'] ?? ''));

        if ($slug !== '') {
            return $this->visibility->findPublicPlugin($slug, $viewer);
        }

        $id = (int) ($row['id'] ?? 0);

        return $id > 0 ? $this->visibility->publicPlugins($viewer)->whereKey($id)->first() : null;
    }

    public function identifies(array $row): bool
    {
        return trim((string) ($row['slug'] ?? '')) !== '' || (int) ($row['id'] ?? 0) > 0;
    }
}
