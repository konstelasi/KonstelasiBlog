<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Models\User;
use App\Support\Rbac;
use Filament\Actions\Action;
use Filament\Actions\DeleteAction;
use Filament\Notifications\Notification;
use Filament\Resources\Pages\EditRecord;
use Filament\Support\Icons\Heroicon;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            // For a lost phone. The person signs in with the password alone
            // again and can set up the app anew.
            Action::make('resetTwoFactor')
                ->label('Turn off two-factor')
                ->icon(Heroicon::OutlinedShieldExclamation)
                ->color('gray')
                ->visible(fn (User $record): bool => $record->hasTwoFactor())
                ->requiresConfirmation()
                ->modalHeading('Turn off two-factor sign-in?')
                ->modalDescription(fn (User $record): string => "{$record->name} will sign in with the password alone until they set up the authenticator app again.")
                ->action(function (User $record): void {
                    $record->resetTwoFactor();

                    Notification::make()->success()->title('Two-factor sign-in is off')->send();
                }),
            DeleteAction::make()
                ->modalDescription('Their posts stay. Each one keeps showing the name it had as the author.'),
        ];
    }

    protected function afterSave(): void
    {
        Rbac::assign($this->getRecord(), $this->data['role']);
    }
}
