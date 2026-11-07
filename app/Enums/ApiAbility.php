<?php

namespace App\Enums;

use Filament\Support\Contracts\HasDescription;
use Filament\Support\Contracts\HasLabel;

/**
 * What an API token may do, on top of what its account may do. A token never
 * gets more than its account (`PostPolicy` still decides), and an ability
 * narrows it further. A token with only `posts:write` can keep drafts,
 * whatever role its account has.
 */
enum ApiAbility: string implements HasDescription, HasLabel
{
    case PostsWrite = 'posts:write';
    case PostsPublish = 'posts:publish';

    public function getLabel(): string
    {
        return match ($this) {
            self::PostsWrite => 'Write drafts',
            self::PostsPublish => 'Publish',
        };
    }

    public function getDescription(): string
    {
        return match ($this) {
            self::PostsWrite => 'Create and update drafts, including their planned date and tags.',
            self::PostsPublish => 'Also publish, take a live post back to draft and edit live posts. Needs an account that may publish.',
        };
    }

    /**
     * The values of every ability, for a token that may do everything its
     * account can.
     *
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $ability): string => $ability->value, self::cases());
    }
}
