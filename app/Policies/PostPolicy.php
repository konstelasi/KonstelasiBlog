<?php

namespace App\Policies;

use App\Enums\PostStatus;
use App\Models\Post;
use App\Models\User;
use App\Support\Rbac;

/**
 * Who may do what to a post. Laravel finds this class by name, and Filament
 * asks it for every button and page of the Posts resource.
 *
 * A Writer's `.own` permissions stop at the draft stage. Editing or deleting a
 * live post changes the public site, so that is for Editors and Admins.
 * A post with no writer (imported, or its writer's account was deleted) is
 * nobody's own.
 */
class PostPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Rbac::POST_VIEW);
    }

    public function view(User $user, Post $post): bool
    {
        return $user->can(Rbac::POST_VIEW);
    }

    public function create(User $user): bool
    {
        return $user->can(Rbac::POST_CREATE);
    }

    public function update(User $user, Post $post): bool
    {
        return $user->can(Rbac::POST_UPDATE_ANY)
            || ($user->can(Rbac::POST_UPDATE_OWN) && $this->isOwnDraft($user, $post));
    }

    public function delete(User $user, Post $post): bool
    {
        return $user->can(Rbac::POST_DELETE_ANY)
            || ($user->can(Rbac::POST_DELETE_OWN) && $this->isOwnDraft($user, $post));
    }

    /**
     * Whether the bulk delete action may be offered at all. Each record is
     * still checked with `delete()` (see `PostsTable`), which is what keeps
     * a Writer from deleting someone else's post.
     */
    public function deleteAny(User $user): bool
    {
        return $user->can(Rbac::POST_DELETE_ANY) || $user->can(Rbac::POST_DELETE_OWN);
    }

    /** Setting a post to Published, taking a live one back to Draft, or setting its date. */
    public function publish(User $user): bool
    {
        return $user->can(Rbac::POST_PUBLISH);
    }

    private function isOwnDraft(User $user, Post $post): bool
    {
        // The stored status, not one a form has put on the model.
        $status = $post->getOriginal('status', $post->status);

        return $post->user_id !== null
            && (int) $post->user_id === (int) $user->getKey()
            && $status === PostStatus::Draft;
    }
}
