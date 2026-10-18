<?php

declare(strict_types=1);

namespace Filagora\Plugins\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Who may publish without waiting for review. The default asks the access checker for the
 * `moderate` and `auto_publish` keys set in `filagora-plugins.gates`; bind your own to decide
 * another way (a trusted-author list, a reputation score).
 */
interface SubmissionPolicy
{
    public function bypassesModeration(?Authenticatable $user): bool;
}
