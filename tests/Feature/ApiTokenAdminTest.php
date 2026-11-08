<?php

namespace Tests\Feature;

use App\Filament\Pages\Profile;
use App\Models\ApiToken;
use App\Models\User;
use App\Support\Rbac;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Features\SupportTesting\Testable;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTokenAdminTest extends TestCase
{
    use RefreshDatabase;

    private function createToken(): TestAction
    {
        return TestAction::make('createToken')->schemaComponent('tokenActions', schema: 'content');
    }

    private function tokensSection(): Testable
    {
        return Livewire::test(Profile::class)->set('section', 'tokens');
    }

    public function test_only_an_admin_may_manage_tokens(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->can(Rbac::TOKEN_MANAGE));
        $this->assertFalse(User::factory()->editor()->create()->can(Rbac::TOKEN_MANAGE));
        $this->assertFalse(User::factory()->writer()->create()->can(Rbac::TOKEN_MANAGE));
    }

    public function test_the_tokens_are_a_section_of_the_profile_for_admins_only(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $this->tokensSection()->assertSee('Create token')->assertSee('You have no tokens yet.');

        foreach (['editor', 'writer'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());

            // Asking for the section by hand lands on the overview instead.
            // (The overview lists what the role allows, so the words "API
            // tokens" can appear there. The menu hint and the button cannot.)
            $this->tokensSection()
                ->assertDontSee('Scripts and the push command')
                ->assertDontSee('Create token');
        }
    }

    public function test_the_old_token_screen_is_gone_from_the_menu(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/api-tokens')->assertNotFound();
        $this->get('/admin')->assertOk()->assertDontSee('/admin/api-tokens', false);
    }

    public function test_an_admin_creates_a_token_that_belongs_to_their_account(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->tokensSection()
            ->callAction($this->createToken(), ['name' => 'Push from the office', 'abilities' => ['posts:write'], 'expires_in' => 90])
            ->assertHasNoActionErrors()
            ->assertNotified('Token created')
            ->assertSee('Push from the office');

        $token = ApiToken::sole();
        $this->assertSame('Push from the office', $token->name);
        $this->assertSame(['posts:write'], $token->abilities);
        $this->assertSame($admin->id, $token->tokenable_id);
        $this->assertEqualsWithDelta(90, now()->diffInDays($token->expires_at, true), 1);
    }

    public function test_the_form_offers_a_lifetime_and_never_a_token_that_does_not_expire(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->tokensSection()
            ->callAction($this->createToken(), ['name' => 'Forever', 'abilities' => ['posts:write'], 'expires_in' => 0])
            ->assertHasActionErrors(['expires_in']);

        $this->assertSame(0, ApiToken::count());
    }

    public function test_publishing_without_writing_is_refused_by_the_form(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->tokensSection()
            ->callAction($this->createToken(), ['name' => 'Odd', 'abilities' => ['posts:publish'], 'expires_in' => 30])
            ->assertHasActionErrors(['abilities']);

        $this->assertSame(0, ApiToken::count());
    }

    public function test_a_token_may_hold_the_tag_ability_alone_or_with_writing(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach ([['tags:manage'], ['posts:write', 'tags:manage']] as $abilities) {
            $this->tokensSection()
                ->callAction($this->createToken(), ['name' => 'Tags', 'abilities' => $abilities, 'expires_in' => 30])
                ->assertHasNoActionErrors();
        }

        $this->assertSame([['tags:manage'], ['posts:write', 'tags:manage']], ApiToken::orderBy('id')->get()->map->abilities->all());
    }

    public function test_a_token_needs_a_name_and_an_ability(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->tokensSection()
            ->callAction($this->createToken(), ['name' => '', 'abilities' => [], 'expires_in' => 90])
            ->assertHasActionErrors(['name', 'abilities']);
    }

    public function test_an_account_without_the_permission_cannot_use_the_token_actions(): void
    {
        // An Editor can hold a token, for instance after being demoted from
        // Admin. The create action lives in a section they cannot open, and the
        // revoke action refuses them as well.
        $editor = User::factory()->editor()->create();
        $token = $editor->createToken('left over', ['posts:write'], now()->addDay())->accessToken;
        $this->actingAs($editor);

        // Sent by hand, the way a browser's Livewire call would send it.
        $this->tokensSection()
            ->assertActionHidden('revokeToken')
            ->call('mountAction', 'revokeToken', ['token' => $token->id])
            ->call('callMountedAction');

        $this->assertNotNull($token->fresh());
        $this->assertSame(1, ApiToken::count());
    }

    public function test_a_new_token_carries_a_prefix_a_secret_scanner_can_find(): void
    {
        $user = User::factory()->admin()->create();

        $plain = $user->createToken('push', ['posts:write'], now()->addDay())->plainTextToken;

        $this->assertStringContainsString('|kblog_', $plain);
        $this->assertStringNotContainsString($plain, (string) ApiToken::sole()->token);
    }

    public function test_revoking_a_token_removes_it_so_its_secret_no_longer_matches(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);
        $created = $admin->createToken('push', ['posts:write'], now()->addDay());
        $this->assertNotNull(ApiToken::findToken($created->plainTextToken));

        $this->tokensSection()
            ->assertSee('push')
            ->callAction('revokeToken', arguments: ['token' => $created->accessToken->id])
            ->assertNotified('Token revoked');

        $this->assertSame(0, ApiToken::count());
        $this->assertNull(ApiToken::findToken($created->plainTextToken));
    }

    public function test_the_list_and_the_revoke_action_cover_only_your_own_tokens(): void
    {
        $other = User::factory()->admin()->create();
        $theirs = $other->createToken('theirs', ['posts:write'], now()->addDay())->accessToken;
        $admin = User::factory()->admin()->create();
        $mine = $admin->createToken('mine', ['posts:write'], now()->addDay())->accessToken;
        $this->actingAs($admin);

        $this->tokensSection()
            ->assertSee('mine')
            ->assertDontSee('theirs')
            ->callAction('revokeToken', arguments: ['token' => $theirs->id]);

        $this->assertNotNull($theirs->fresh());
        $this->assertNotNull($mine->fresh());
        $this->assertFalse($admin->can('delete', $theirs));
        $this->assertTrue($admin->can('delete', $mine));
    }

    public function test_an_expired_token_is_marked_in_the_list(): void
    {
        $admin = User::factory()->admin()->create();
        $admin->createToken('old', ['posts:write'], now()->subDay());
        $this->actingAs($admin);

        $this->tokensSection()->assertSee('old')->assertSee('Expired');
    }

    public function test_deleting_an_account_deletes_its_tokens(): void
    {
        $user = User::factory()->editor()->create();
        $user->createToken('push', ['posts:write'], now()->addDay());
        $other = User::factory()->editor()->create();
        $other->createToken('other', ['posts:write'], now()->addDay());

        $user->delete();

        $this->assertSame(['other'], ApiToken::pluck('name')->all());
    }
}
