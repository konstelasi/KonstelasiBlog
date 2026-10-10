<?php

namespace App\Console\Commands;

use App\Support\Rbac;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('rbac:sync')]
#[Description('Make the roles and permissions in the database match App\Support\Rbac')]
class RbacSync extends Command
{
    public function handle(): int
    {
        Rbac::sync();

        $this->components->info('Roles and permissions are in sync.');

        return self::SUCCESS;
    }
}
