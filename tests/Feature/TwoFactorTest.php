<?php

namespace Tests\Feature;

use App\Filament\Pages\Profile;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Models\User;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Auth\Pages\Login;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\TestCase;

class TwoFactorTest extends TestCase
{
    use RefreshDatabase;

    private function provider(): AppAuthentication
    {
        return Filament::getMultiFactorAuthenticationProviders()['app'];
    }

    /** An account with the app turned on, and the recovery codes it was given. */
    private function withTwoFactor(string $role = 'writer'): array
    {
        $user = User::factory()->{$role}()->create();
        $provider = $this->provider();
        $codes = ['AAAAAAAAAA-BBBBBBBBBB', 'CCCCCCCCCC-DDDDDDDDDD'];

        $provider->saveSecret($user, $provider->generateSecret());
        $provider->saveRecoveryCodes($user, $codes);

        return [$user->fresh(), $codes];
    }

    /** Past the password step, so the login page is asking for the code. */
    private function atTheCodeStep(User $user)
    {
        return Livewire::test(Login::class)
            ->fillForm(['email' => $user->email, 'password' => 'password'])
            ->call('authenticate');
    }

    public function test_an_account_without_two_factor_signs_in_with_the_password_alone(): void
    {
        $user = User::factory()->writer()->create();

        $this->atTheCodeStep($user)->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_password_alone_is_not_enough_once_two_factor_is_on(): void
    {
        [$user] = $this->withTwoFactor();

        $this->atTheCodeStep($user);

        $this->assertGuest();
    }

    public function test_a_valid_code_signs_in(): void
    {
        [$user] = $this->withTwoFactor();

        $this->atTheCodeStep($user)
            ->set('data.multiFactor.app.code', $this->provider()->getCurrentCode($user))
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
    }

    public function test_a_wrong_code_is_refused(): void
    {
        [$user] = $this->withTwoFactor();

        $this->atTheCodeStep($user)
            ->set('data.multiFactor.app.code', '000000')
            ->call('authenticate')
            ->assertHasErrors();

        $this->assertGuest();
    }

    public function test_a_recovery_code_works_once(): void
    {
        [$user, $codes] = $this->withTwoFactor();

        $this->atTheCodeStep($user)
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $codes[0])
            ->call('authenticate')
            ->assertHasNoFormErrors();

        $this->assertAuthenticatedAs($user);
        $this->assertCount(1, $user->fresh()->app_authentication_recovery_codes);

        auth()->logout();

        $this->atTheCodeStep($user)
            ->set('data.multiFactor.app.useRecoveryCode', true)
            ->set('data.multiFactor.app.recoveryCode', $codes[0])
            ->call('authenticate')
            ->assertHasErrors();

        $this->assertGuest();
    }

    public function test_the_secret_and_the_codes_are_stored_encrypted_and_never_shown(): void
    {
        [$user, $codes] = $this->withTwoFactor();
        $secret = $user->app_authentication_secret;

        $row = DB::table('users')->where('id', $user->id)->first();

        $this->assertNotSame($secret, $row->app_authentication_secret);
        $this->assertStringNotContainsString($secret, $row->app_authentication_secret);
        $this->assertStringNotContainsString($codes[0], $row->app_authentication_recovery_codes);
        $this->assertArrayNotHasKey('app_authentication_secret', $user->toArray());
        $this->assertArrayNotHasKey('app_authentication_recovery_codes', $user->toArray());
        $this->assertStringNotContainsString($secret, $user->toJson());
        $this->assertTrue(Hash::check($codes[0], $user->app_authentication_recovery_codes[0]) || $user->app_authentication_recovery_codes[0] === $codes[0]);
    }

    public function test_the_security_tab_offers_to_set_it_up_and_then_to_turn_it_off(): void
    {
        $this->actingAs(User::factory()->writer()->create());

        Livewire::test(Profile::class)
            ->assertSee('Two-factor authentication')
            ->assertSee('Authenticator app')
            ->assertSee('Disabled')
            ->assertSee('Set up')
            ->assertDontSee('Turn off');

        [$user] = $this->withTwoFactor('editor');
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->assertSee('Enabled')
            ->assertSee('Turn off');
    }

    public function test_an_admin_turns_off_two_factor_for_a_lost_phone(): void
    {
        [$writer] = $this->withTwoFactor();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditUser::class, ['record' => $writer->getRouteKey()])
            ->assertActionVisible('resetTwoFactor')
            ->callAction('resetTwoFactor');

        $writer->refresh();
        $this->assertFalse($writer->hasTwoFactor());
        $this->assertNull($writer->app_authentication_recovery_codes);
    }

    public function test_the_reset_action_is_hidden_for_an_account_without_two_factor(): void
    {
        $writer = User::factory()->writer()->create();
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(EditUser::class, ['record' => $writer->getRouteKey()])
            ->assertActionHidden('resetTwoFactor');
    }

    public function test_mfa_reset_turns_it_off_over_the_command_line(): void
    {
        [$user] = $this->withTwoFactor();

        $this->artisan('mfa:reset', ['email' => $user->email])->assertSuccessful();

        $this->assertFalse($user->fresh()->hasTwoFactor());
    }

    public function test_mfa_reset_rejects_an_unknown_email_and_notes_an_account_without_it(): void
    {
        $this->artisan('mfa:reset', ['email' => 'nobody@example.com'])->assertFailed();

        $plain = User::factory()->writer()->create();
        $this->artisan('mfa:reset', ['email' => $plain->email])->assertSuccessful();
    }
}
