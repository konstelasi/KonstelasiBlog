<?php

namespace App\Policies;

use App\Models\ApiToken;
use App\Models\User;
use App\Support\Rbac;

/**
 * Tokens are for Admins (`token.manage`). A token can act as its account, so
 * handing one out is as serious as handing out the account. Nobody edits a
 * token. To change what it may do, revoke it and create another. Everyone
 * manages only their own tokens, on the profile page, and deleting an account
 * deletes its tokens.
 */
class ApiTokenPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->can(Rbac::TOKEN_MANAGE);
    }

    public function create(User $user): bool
    {
        return $user->can(Rbac::TOKEN_MANAGE);
    }

    public function delete(User $user, ApiToken $token): bool
    {
        return $user->can(Rbac::TOKEN_MANAGE)
            && $token->tokenable_type === $user->getMorphClass()
            && (int) $token->tokenable_id === $user->getKey();
    }
}
