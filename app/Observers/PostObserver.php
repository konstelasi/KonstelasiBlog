<?php

namespace App\Observers;

use App\Enums\PostStatus;
use App\Jobs\RebuildSite;
use App\Models\Post;

/**
 * The public blog is static, so it only changes when the site is rebuilt.
 * This starts a rebuild whenever a change could be visible there.
 */
class PostObserver
{
    public function saved(Post $post): void
    {
        // Visible if the post is live now, or was live until this save.
        $wasPublished = $post->getOriginal('status') === PostStatus::Published;

        if ($post->status === PostStatus::Published || $wasPublished) {
            RebuildSite::dispatchAfterResponse();
        }
    }

    public function deleted(Post $post): void
    {
        if ($post->status === PostStatus::Published) {
            RebuildSite::dispatchAfterResponse();
        }
    }
}
