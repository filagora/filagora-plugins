<?php

declare(strict_types=1);

namespace Filagora\Plugins\Release;

use Filagora\Core\Contracts\AccessChecker;
use Filagora\Plugins\Contracts\SubmissionPolicy;
use Filagora\Plugins\Support\Gates;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Staff who may moderate, and people granted auto-publish, publish without review.
 */
final class AccessCheckerSubmissionPolicy implements SubmissionPolicy
{
    public function __construct(private readonly AccessChecker $access) {}

    public function bypassesModeration(?Authenticatable $user): bool
    {
        return $user !== null && $this->access->allowsAny($user, [
            Gates::key(Gates::MODERATE),
            Gates::key(Gates::AUTO_PUBLISH),
        ]);
    }
}
