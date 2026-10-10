<?php

namespace App\Observers;

use App\Jobs\RebuildSite;
use App\Models\User;

/**
 * An account's name is the byline on its posts, so renaming an account that
 * has a live post changes the public site. This starts the rebuild that
 * shows it, the same way a change to a live post does.
 */
class UserObserver
{
    public function saved(User $user): void
    {
        if ($user->wasChanged('name') && $user->posts()->published()->exists()) {
            RebuildSite::dispatchAfterResponse();
        }
    }
}
