<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

/**
 * The way back in over SSH when the only Admin has lost their phone and
 * recovery codes, so nobody is left to use the Users screen.
 */
#[Signature('mfa:reset {email : The account\'s email address}')]
#[Description('Turn off two-factor sign-in for one account')]
class MfaReset extends Command
{
    public function handle(): int
    {
        $user = User::where('email', $this->argument('email'))->first();

        if (! $user) {
            $this->components->error("No account has the email {$this->argument('email')}.");

            return self::FAILURE;
        }

        if (! $user->hasTwoFactor()) {
            $this->components->info("{$user->email} does not use two-factor sign-in.");

            return self::SUCCESS;
        }

        $user->resetTwoFactor();

        $this->components->info("Two-factor sign-in is off for {$user->email}.");

        return self::SUCCESS;
    }
}
