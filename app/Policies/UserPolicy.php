<?php

namespace App\Policies;

use App\Models\User;
use App\Support\Rbac;

/**
 * Accounts are for Admins only (`user.manage`). Two rules protect the way
 * back in: nobody deletes their own account here, and the last Admin cannot
 * be deleted.
 */
class UserPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Rbac::USER_MANAGE);
    }

    public function view(User $user, User $account): bool
    {
        return $user->can(Rbac::USER_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(Rbac::USER_MANAGE);
    }

    public function update(User $user, User $account): bool
    {
        return $user->can(Rbac::USER_MANAGE);
    }

    public function delete(User $user, User $account): bool
    {
        return $user->can(Rbac::USER_MANAGE)
            && $user->isNot($account)
            && ! Rbac::isLastAdmin($account);
    }

    /** Whether the bulk delete is offered. Each account is still checked with `delete()`. */
    public function deleteAny(User $user): bool
    {
        return $user->can(Rbac::USER_MANAGE);
    }
}
