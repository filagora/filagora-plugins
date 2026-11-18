<?php

declare(strict_types=1);

namespace Filagora\Plugins\Enums;

/**
 * What a call to the JSON API changed.
 */
enum ApiChange: string
{
    case PLUGIN_UPDATED = 'plugin.updated';
    case RELEASE_UPLOADED = 'release.uploaded';
    case RELEASE_MODERATED = 'release.moderated';
    case PLUGIN_FOLLOWED = 'plugin.followed';
    case PLUGIN_UNFOLLOWED = 'plugin.unfollowed';
}
