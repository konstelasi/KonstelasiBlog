<?php

namespace Tests\Feature;

use App\Filament\Pages\Profile;
use App\Models\User;
use App\Support\BrowserSessions;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

class BrowserSessionsTest extends TestCase
{
    use RefreshDatabase;

    private const CHROME = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/126.0.0.0 Safari/537.36';

    private const IPHONE = 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_5 like Mac OS X) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/17.5 Mobile/15E148 Safari/604.1';

    protected function setUp(): void
    {
        parent::setUp();

        config(['session.driver' => 'database', 'session.lifetime' => 120]);
    }

    private function addSession(string $id, ?User $user, string $agent = self::CHROME, ?int $lastActivity = null): void
    {
        DB::table('sessions')->insert([
            'id' => $id,
            'user_id' => $user?->id,
            'ip_address' => '203.0.113.7',
            'user_agent' => $agent,
            'payload' => '',
            'last_activity' => $lastActivity ?? now()->getTimestamp(),
        ]);
    }

    private function signOutOthers(): TestAction
    {
        return TestAction::make('signOutOthers')->schemaComponent('sessionActions', schema: 'content');
    }

    public function test_it_lists_only_the_accounts_own_live_sessions_and_marks_the_current_one(): void
    {
        $me = User::factory()->writer()->create();
        $other = User::factory()->writer()->create();
        $this->addSession('mine-here', $me);
        $this->addSession('mine-phone', $me, self::IPHONE, now()->subMinutes(10)->getTimestamp());
        $this->addSession('mine-expired', $me, self::CHROME, now()->subMinutes(500)->getTimestamp());
        $this->addSession('theirs', $other);

        $sessions = (new BrowserSessions($me, 'mine-here'))->all();

        $this->assertSame(['mine-here', 'mine-phone'], $sessions->pluck('id')->all());
        $this->assertSame('Chrome on Windows', $sessions[0]['description']);
        $this->assertTrue($sessions[0]['current']);
        $this->assertSame('Safari on iOS', $sessions[1]['description']);
        $this->assertFalse($sessions[1]['current']);
    }

    public function test_signing_out_others_keeps_the_current_session_and_other_accounts(): void
    {
        $me = User::factory()->writer()->create();
        $other = User::factory()->writer()->create();
        $this->addSession('mine-here', $me);
        $this->addSession('mine-phone', $me);
        $this->addSession('mine-old', $me);
        $this->addSession('theirs', $other);

        $ended = (new BrowserSessions($me, 'mine-here'))->signOutOthers();

        $this->assertSame(2, $ended);
        $this->assertEqualsCanonicalizing(['mine-here', 'theirs'], DB::table('sessions')->pluck('id')->all());
    }

    public function test_the_page_lists_the_sessions_and_signs_the_others_out_after_the_password(): void
    {
        $me = User::factory()->writer()->create();
        $this->actingAs($me);
        $this->addSession(session()->getId(), $me);
        $this->addSession('mine-phone', $me, self::IPHONE);

        $page = Livewire::test(Profile::class)->set('section', 'security')
            ->assertSee('Where you are signed in')
            ->assertSee('This browser')
            ->assertSee('Safari on iOS');

        $page->callAction($this->signOutOthers(), ['password' => 'not-my-password'])
            ->assertHasActionErrors(['password']);

        $this->assertDatabaseHas('sessions', ['id' => 'mine-phone']);

        $page->callAction($this->signOutOthers(), ['password' => 'password'])
            ->assertHasNoActionErrors();

        $this->assertDatabaseMissing('sessions', ['id' => 'mine-phone']);
        $this->assertDatabaseHas('sessions', ['id' => session()->getId()]);
    }

    public function test_signing_out_others_also_replaces_the_remember_token(): void
    {
        $me = User::factory()->writer()->create(['remember_token' => 'the-old-remember-token']);
        $this->addSession('mine-here', $me);
        $this->addSession('mine-phone', $me);

        (new BrowserSessions($me, 'mine-here'))->signOutOthers();

        $this->assertNotSame('the-old-remember-token', $me->fresh()->getRememberToken());
        $this->assertSame(60, strlen($me->fresh()->getRememberToken()));
    }

    public function test_guessing_the_password_is_limited_to_five_tries_a_minute(): void
    {
        $me = User::factory()->writer()->create();
        $this->actingAs($me);
        $this->addSession(session()->getId(), $me);
        $this->addSession('mine-phone', $me, self::IPHONE);

        $page = Livewire::test(Profile::class)->set('section', 'security');

        foreach (range(1, 5) as $try) {
            $page->callAction($this->signOutOthers(), ['password' => "guess-{$try}"])
                ->assertHasActionErrors(['password']);
        }

        // The sixth try has the right password and is still refused, so the
        // limit cannot be used to tell a right guess from a wrong one.
        $page->callAction($this->signOutOthers(), ['password' => 'password'])
            ->assertHasActionErrors(['password']);

        $this->assertDatabaseHas('sessions', ['id' => 'mine-phone']);
    }

    public function test_there_is_nothing_to_sign_out_when_this_is_the_only_browser(): void
    {
        $me = User::factory()->writer()->create();
        $this->actingAs($me);
        $this->addSession(session()->getId(), $me);

        Livewire::test(Profile::class)->set('section', 'security')
            ->assertSee('This browser')
            ->assertDontSee('Sign out other browsers');
    }

    public function test_the_section_is_left_out_unless_sessions_are_kept_in_the_database(): void
    {
        config(['session.driver' => 'file']);
        $this->actingAs(User::factory()->writer()->create());

        Livewire::test(Profile::class)->set('section', 'security')
            ->assertDontSee('Where you are signed in')
            ->assertDontSee('Sign out other browsers');
    }
}
