<?php

namespace App\Filament\Resources\Users\Pages;

use App\Filament\Resources\Users\UserResource;
use App\Support\Rbac;
use Filament\Resources\Pages\CreateRecord;

class CreateUser extends CreateRecord
{
    protected static string $resource = UserResource::class;

    /** The role is not a column, so it is given once the account exists. */
    protected function afterCreate(): void
    {
        Rbac::assign($this->getRecord(), $this->data['role']);
    }
}
