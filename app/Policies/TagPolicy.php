<?php

namespace App\Policies;

use App\Models\Tag;
use App\Models\User;
use App\Support\Rbac;

/**
 * The tag list is for Editors and Admins (`tag.manage`). Everyone with a role
 * can still pick tags on a post, because that goes through the post form and
 * not through this resource. A tag is printed on live posts, so renaming or
 * deleting one is a decision for people who may publish.
 */
class TagPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Rbac::TAG_MANAGE);
    }

    public function view(User $user, Tag $tag): bool
    {
        return $user->can(Rbac::TAG_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(Rbac::TAG_MANAGE);
    }

    public function update(User $user, Tag $tag): bool
    {
        return $user->can(Rbac::TAG_MANAGE);
    }

    public function delete(User $user, Tag $tag): bool
    {
        return $user->can(Rbac::TAG_MANAGE);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Rbac::TAG_MANAGE);
    }
}
