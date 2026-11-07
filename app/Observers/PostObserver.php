<?php

namespace App\Observers;

use App\Enums\PostStatus;
use App\Jobs\RebuildSite;
use App\Models\Post;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;

/**
 * The public blog is static, so it only changes when the site is rebuilt.
 * This starts a rebuild whenever a change could be visible there.
 */
class PostObserver
{
    /**
     * The last line of defence for the two rules about publishing. The date
     * rule judges every save, whoever makes it. The permission rule is for
     * who may publish: the form and the pages already keep a Writer's status
     * out, but this stops a save that gets past them, from a new code path or
     * a forged request. It only judges a signed-in account, so the import
     * command and the tests that sign in as nobody are untouched.
     *
     * @throws AuthorizationException
     * @throws ValidationException
     */
    public function saving(Post $post): void
    {
        $this->refuseFutureDate($post);

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

    /**
     * A post dated in the future has to stay a draft, since the site would
     * show it at once under a date still to come. Only a save that sets the
     * status or the date is judged, so a live post that somehow already
     * carries a future date can still have its text edited.
     *
     * @throws ValidationException
     */
    private function refuseFutureDate(Post $post): void
    {
        if ($post->status !== PostStatus::Published || $post->published_at === null || ! $post->published_at->isFuture()) {
            return;
        }

        if (! $post->exists || $post->isDirty(['status', 'published_at'])) {
            throw ValidationException::withMessages(['published_at' => Post::FUTURE_DATE_MESSAGE]);
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
