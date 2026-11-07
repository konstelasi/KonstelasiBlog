<?php

namespace App\Policies;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\Rbac;

/**
 * Tokens are for Admins (`token.manage`). A token can act as its account, so
 * handing one out is as serious as handing out the account. Nobody edits a
 * token. To change what it may do, revoke it and create another.
 */
class ApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Rbac::TOKEN_MANAGE);
    }

    public function view(User $user, ApiToken $token): bool
    {
        return $user->can(Rbac::TOKEN_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(Rbac::TOKEN_MANAGE);
    }

    public function update(User $user, ApiToken $token): bool
    {
        return false;
    }

    public function delete(User $user, ApiToken $token): bool
    {
        return $user->can(Rbac::TOKEN_MANAGE);
    }

    public function deleteAny(User $user): bool
    {
        return $user->can(Rbac::TOKEN_MANAGE);
    }
}
