<?php

namespace App\Observers;

use App\Enums\PostStatus;
use App\Jobs\RebuildSite;
use App\Models\Post;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Auth\Access\AuthorizationException;

/**
 * The public blog is static, so it only changes when the site is rebuilt.
 * This starts a rebuild whenever a change could be visible there.
 */
class PostObserver
{
    /**
     * The last line of defence for who may publish. The form and the pages
     * already keep a Writer's status out, but this stops a save that gets
     * past them, from a new code path or a forged request. It only judges a
     * signed-in account, so the import command and the tests that sign in as
     * nobody are untouched.
     *
     * @throws AuthorizationException
     */
    public function saving(Post $post): void
    {
        $user = auth()->user();

        if (! $user instanceof User || $user->can(Rbac::POST_PUBLISH)) {
            return;
        }

        $goesLive = $post->status === PostStatus::Published && ($post->isDirty('status') || ! $post->exists);
        $comesDown = $post->exists && $post->isDirty('status') && $post->getOriginal('status') === PostStatus::Published;

        if ($goesLive || $comesDown) {
            throw new AuthorizationException('Only an Editor or an Admin can publish or unpublish a post.');
        }
    }

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
