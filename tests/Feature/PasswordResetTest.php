<?php

namespace Tests\Feature;

use App\Models\User;
use Filament\Auth\Notifications\ResetPassword as ResetPasswordNotification;
use Filament\Auth\Pages\PasswordReset\RequestPasswordReset;
use Filament\Auth\Pages\PasswordReset\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * "Forgot password?" exists once a real mailer is set. The panel registers its
 * routes while it boots, so the mail settings are put in place before the app
 * is built, the same way as in EmailChangeTest (see there for why each test
 * runs in its own process). Nothing is ever sent, because the notifications
 * are faked.
 */
#[RunTestsInSeparateProcesses]
class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const SETTINGS = [
        'MAIL_MAILER' => 'smtp',
        'MAIL_SCHEME' => 'smtps',
        'MAIL_HOST' => 'mx3.mailspace.id',
        'MAIL_PORT' => '465',
        'MAIL_USERNAME' => 'hello@konstelasi.co.id',
        'MAIL_PASSWORD' => 'a-made-up-test-password',
        'MAIL_FROM_ADDRESS' => 'hello@konstelasi.co.id',
        'MAIL_FROM_NAME' => 'Konstelasi Blog',
    ];

    private array $before = [];

    protected function setUp(): void
    {
        foreach (self::SETTINGS as $name => $value) {
            $this->before[$name] = [getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null];
            putenv("{$name}={$value}");
            $_ENV[$name] = $_SERVER[$name] = $value;
        }

        parent::setUp();

        Notification::fake();
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        foreach ($this->before as $name => [$env, $envArray, $server]) {
            putenv($env === false ? $name : "{$name}={$env}");
            unset($_ENV[$name], $_SERVER[$name]);

            if ($envArray !== null) {
                $_ENV[$name] = $envArray;
            }

            if ($server !== null) {
                $_SERVER[$name] = $server;
            }
        }
    }

    public function test_the_login_page_links_to_the_reset_request(): void
    {
        $this->get('/admin/login')->assertOk()->assertSee('/admin/password-reset/request', false);
    }

    public function test_a_reset_link_is_mailed_and_sets_a_new_password(): void
    {
        $user = User::factory()->writer()->create(['password' => 'the-old-password']);

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $user->email])
            ->call('request')
            ->assertHasNoFormErrors();

        $url = '';
        Notification::assertSentTo($user, ResetPasswordNotification::class, function (ResetPasswordNotification $notification) use (&$url): bool {
            $url = $notification->url;

            return true;
        });

        parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
        $token = $query['token'];

        Livewire::test(ResetPassword::class, ['email' => $user->email, 'token' => $token])
            ->fillForm(['password' => 'a-new-password-1', 'passwordConfirmation' => 'a-new-password-1'])
            ->call('resetPassword')
            ->assertHasNoFormErrors();

        $this->assertTrue(Hash::check('a-new-password-1', $user->fresh()->password));
    }

    public function test_an_account_without_a_role_gets_no_link(): void
    {
        $user = User::factory()->create();

        Livewire::test(RequestPasswordReset::class)
            ->fillForm(['email' => $user->email])
            ->call('request');

        Notification::assertNothingSent();
    }
}
