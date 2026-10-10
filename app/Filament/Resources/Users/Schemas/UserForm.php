<?php

namespace App\Filament\Resources\Users\Schemas;

use App\Models\User;
use App\Support\Rbac;
use Closure;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Schema;
use Illuminate\Support\Str;

class UserForm
{
    public static function configure(Schema $schema): Schema
    {
        return $schema
            ->columns(1)
            ->components([
                TextInput::make('name')
                    ->required()
                    ->maxLength(255)
                    // The one place an account name becomes public. Posts print
                    // their writer's current name as the author.
                    ->helperText('Printed to readers as the author of this person\'s posts.'),
                TextInput::make('email')
                    ->label('Email address')
                    ->email()
                    ->required()
                    ->maxLength(255)
                    ->unique(ignoreRecord: true),
                TextInput::make('password')
                    ->password()
                    ->revealable()
                    ->minLength(8)
                    ->required(fn (string $operation): bool => $operation === 'create')
                    // Left empty on edit means keep the current password.
                    ->dehydrated(fn (?string $state): bool => filled($state))
                    ->helperText(fn (string $operation): ?string => $operation === 'edit'
                        ? 'Leave empty to keep the current password.'
                        : null),
                // Not a column. The pages read it and call `Rbac::assign`.
                Select::make('role')
                    ->options(self::roleOptions())
                    ->required()
                    ->dehydrated(false)
                    ->formatStateUsing(fn (?User $record): ?string => $record?->roles->first()?->name)
                    ->rule(fn (?User $record): Closure => function (string $attribute, mixed $value, Closure $fail) use ($record): void {
                        if ($record && $value !== Rbac::ADMIN && Rbac::isLastAdmin($record)) {
                            $fail('This is the last Admin. Make someone else an Admin first.');
                        }
                    })
                    ->helperText('Writers save drafts and edit their own. Editors also publish and edit any post. Admins also manage accounts.'),
            ]);
    }

    /** @return array<string, string> */
    private static function roleOptions(): array
    {
        return collect(Rbac::ROLES)
            ->mapWithKeys(fn (string $role): array => [$role => Str::ucfirst($role)])
            ->all();
    }
}
