<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * Also the way back in over SSH if every Admin is locked out, and the step
 * after `make:filament-user` on a fresh install, since a new account has no
 * role and cannot sign in to the admin until it gets one.
 */
#[Signature('rbac:assign {email : The account\'s email address} {role : admin, editor or writer}')]
#[Description('Give an account one role, replacing any role it had')]
class RbacAssign extends Command
{
    public function handle(): int
    {
        $role = (string) $this->argument('role');

        if (! in_array($role, Rbac::ROLES, true)) {
            $this->components->error("Unknown role \"{$role}\". Use ".implode(', ', Rbac::ROLES).'.');

            return self::FAILURE;
        }

        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->components->error("No account has the email {$this->argument('email')}.");

            return self::FAILURE;
        }

        // The role rows may not exist yet on a fresh install.
        Rbac::sync();
        $user->syncRoles($role);

        $this->components->info("{$user->email} is now {$role}.");

        return self::SUCCESS;
    }
}
