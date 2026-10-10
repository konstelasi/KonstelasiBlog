<?php

namespace Tests\Feature;

use App\Filament\Pages\Profile;
use App\Models\User;
use Filament\Auth\Notifications\NoticeOfEmailChangeRequest;
use Filament\Auth\Notifications\VerifyEmailChange;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\RunTestsInSeparateProcesses;
use Tests\TestCase;

/**
 * The profile page verifies an email change by mail once a real mailer is
 * set. The panel registers the verification routes while it boots, so the
 * mail settings have to be in place before the app is built. They are set
 * here as environment variables, with a made-up password, and removed again
 * afterwards so no other test sees them. Each test runs in its own process,
 * because Laravel rewrites variables it read from `.env` on the next boot and
 * would put the real settings back over these. Nothing is ever sent, because
 * the notifications are faked.
 */
#[RunTestsInSeparateProcesses]
class EmailChangeTest extends TestCase
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

    public function test_the_mailbox_settings_read_as_smtp_over_implicit_tls(): void
    {
        $this->assertSame('smtp', config('mail.default'));
        $this->assertSame('smtps', config('mail.mailers.smtp.scheme'));
        $this->assertSame('mx3.mailspace.id', config('mail.mailers.smtp.host'));
        $this->assertSame(465, (int) config('mail.mailers.smtp.port'));
        $this->assertSame('hello@konstelasi.co.id', config('mail.mailers.smtp.username'));
        $this->assertSame('hello@konstelasi.co.id', config('mail.from.address'));
    }

    public function test_an_email_change_waits_for_the_link(): void
    {
        $user = User::factory()->writer()->create(['email' => 'old@example.com', 'password' => 'the-old-password']);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm(['email' => 'new@example.com', 'currentPassword' => 'the-old-password'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertSame('old@example.com', $user->fresh()->email);
        Notification::assertSentOnDemand(VerifyEmailChange::class);
        Notification::assertSentTo($user, NoticeOfEmailChangeRequest::class);
    }

    public function test_opening_the_link_changes_the_email(): void
    {
        $user = User::factory()->writer()->create(['email' => 'old@example.com', 'password' => 'the-old-password']);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm(['email' => 'new@example.com', 'currentPassword' => 'the-old-password'])
            ->call('save');

        $url = null;
        Notification::assertSentOnDemand(VerifyEmailChange::class, function (VerifyEmailChange $notification) use (&$url): bool {
            $url = $notification->url;

            return true;
        });

        $this->get($url)->assertRedirect();

        $this->assertSame('new@example.com', $user->fresh()->email);
    }

    public function test_the_current_password_is_still_needed(): void
    {
        $user = User::factory()->writer()->create(['email' => 'old@example.com', 'password' => 'the-old-password']);
        $this->actingAs($user);

        Livewire::test(Profile::class)
            ->fillForm(['email' => 'new@example.com', 'currentPassword' => 'wrong'])
            ->call('save')
            ->assertHasFormErrors(['currentPassword']);

        Notification::assertNothingSent();
    }
}
