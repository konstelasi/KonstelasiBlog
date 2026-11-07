<?php

namespace App\Models;

use App\Enums\ApiAbility;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Sanctum's token row, with a few helpers for the admin screen. Only the hash
 * of a token is stored, so the plain text exists once, when the token is
 * created, and can never be shown again.
 */
class ApiToken extends PersonalAccessToken
{
    /** Sanctum's table. A subclass would otherwise look for `api_tokens`. */
    protected $table = 'personal_access_tokens';

    /**
     * The abilities as the labels the token form uses, in the order of
     * `ApiAbility`. A name this version doesn't know is shown as it is.
     *
     * @return list<string>
     */
    public function abilityLabels(): array
    {
        $labels = [];
        foreach ((array) $this->abilities as $name) {
            $labels[] = ApiAbility::tryFrom($name)?->getLabel() ?? $name;
        }

        return $labels;
    }

    /** Whether the token has run out. Sanctum refuses an expired token, this is for the list. */
    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }
}
