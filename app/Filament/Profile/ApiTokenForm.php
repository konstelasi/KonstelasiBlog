<?php

namespace App\Filament\Profile;

use App\Enums\ApiAbility;
use Closure;
use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Component;

/**
 * The fields of a new API token, shown in the modal of the profile page's
 * "Create token" action.
 */
class ApiTokenForm
{
    /** How long a new token lasts, in days. There is no token that never expires. */
    public const LIFETIMES = [
        30 => '30 days',
        90 => '90 days',
        365 => '1 year',
    ];

    /** @return array<Component> */
    public static function fields(): array
    {
        return [
            TextInput::make('name')
                ->required()
                ->maxLength(100)
                ->helperText('What the token is for, such as "Push from the office PC". The list shows it, and the token itself is never shown again.'),
            CheckboxList::make('abilities')
                ->options(ApiAbility::class)
                ->default([ApiAbility::PostsWrite->value])
                ->required()
                ->rules([
                    fn (): Closure => function (string $attribute, mixed $value, Closure $fail): void {
                        if (in_array(ApiAbility::PostsPublish->value, (array) $value, true)
                            && ! in_array(ApiAbility::PostsWrite->value, (array) $value, true)) {
                            $fail('Publish needs Write as well.');
                        }
                    },
                ])
                ->helperText('A token can do no more than your account, and an ability narrows it further. A token that only writes drafts can be lost with little harm.'),
            Select::make('expires_in')
                ->label('Lasts')
                ->options(self::LIFETIMES)
                ->default(90)
                ->required()
                ->native(false),
        ];
    }
}
