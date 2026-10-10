<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\Rbac;
use Filament\Actions\DeleteAction;
use Filament\Resources\Pages\EditRecord;

class EditUser extends EditRecord
{
    protected static string $resource = UserResource::class;

    protected function getHeaderActions(): array
    {
        return [
            DeleteAction::make()
                ->modalDescription('Their posts stay. Each one keeps showing the name it had as the author.'),
        ];
    }

    protected function afterSave(): void
    {
        Rbac::assign($this->getRecord(), $this->data['role']);
    }
}
