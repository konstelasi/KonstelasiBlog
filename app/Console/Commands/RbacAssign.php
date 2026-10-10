<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Rbac;
use DomainException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

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

        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->components->error("No account has the email {$this->argument('email')}.");

            return self::FAILURE;
        }

        try {
            Rbac::assign($user, $role);
        } catch (InvalidArgumentException) {
            $this->components->error("Unknown role \"{$role}\". Use ".implode(', ', Rbac::ROLES).'.');

            return self::FAILURE;
        } catch (DomainException $e) {
            $this->components->error($e->getMessage());

            return self::FAILURE;
        }

        $this->components->info("{$user->email} is now {$role}.");

        return self::SUCCESS;
    }
}
