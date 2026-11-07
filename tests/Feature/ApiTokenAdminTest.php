<?php

namespace Tests\Feature;

use App\Filament\Resources\ApiTokens\Pages\CreateApiToken;
use App\Filament\Resources\ApiTokens\Pages\ListApiTokens;
use App\Models\ApiToken;
use App\Models\User;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ApiTokenAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_only_an_admin_may_manage_tokens(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->can(Rbac::TOKEN_MANAGE));
        $this->assertFalse(User::factory()->editor()->create()->can(Rbac::TOKEN_MANAGE));
        $this->assertFalse(User::factory()->writer()->create()->can(Rbac::TOKEN_MANAGE));
    }

    public function test_the_token_screen_is_for_admins_only(): void
    {
        foreach (['editor', 'writer'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());

            $this->get('/admin/api-tokens')->assertForbidden();
            Livewire::test(CreateApiToken::class)->assertForbidden();
        }

        $this->actingAs(User::factory()->admin()->create());
        $this->get('/admin/api-tokens')->assertOk();
    }

    public function test_an_admin_creates_a_token_that_belongs_to_their_account(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin);

        Livewire::test(CreateApiToken::class)
            ->fillForm(['name' => 'Push from the office', 'abilities' => ['posts:write'], 'expires_in' => 90])
            ->call('create')
            ->assertHasNoFormErrors()
            ->assertNotified('Token created');

        $token = ApiToken::sole();
        $this->assertSame('Push from the office', $token->name);
        $this->assertSame(['posts:write'], $token->abilities);
        $this->assertSame($admin->id, $token->tokenable_id);
        $this->assertEqualsWithDelta(90, now()->diffInDays($token->expires_at, true), 1);
    }

    public function test_the_form_offers_a_lifetime_and_never_a_token_that_does_not_expire(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateApiToken::class)
            ->fillForm(['name' => 'Forever', 'abilities' => ['posts:write'], 'expires_in' => 0])
            ->call('create')
            ->assertHasFormErrors(['expires_in']);

        $this->assertSame(0, ApiToken::count());
    }

    public function test_publishing_without_writing_is_refused_by_the_form(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateApiToken::class)
            ->fillForm(['name' => 'Odd', 'abilities' => ['posts:publish'], 'expires_in' => 30])
            ->call('create')
            ->assertHasFormErrors(['abilities']);

        $this->assertSame(0, ApiToken::count());
    }

    public function test_a_token_may_hold_the_tag_ability_alone_or_with_writing(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        foreach ([['tags:manage'], ['posts:write', 'tags:manage']] as $abilities) {
            Livewire::test(CreateApiToken::class)
                ->fillForm(['name' => 'Tags', 'abilities' => $abilities, 'expires_in' => 30])
                ->call('create')
                ->assertHasNoFormErrors();
        }

        $this->assertSame([['tags:manage'], ['posts:write', 'tags:manage']], ApiToken::orderBy('id')->get()->map->abilities->all());
    }

    public function test_a_token_needs_a_name_and_an_ability(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateApiToken::class)
            ->fillForm(['name' => '', 'abilities' => [], 'expires_in' => 90])
            ->call('create')
            ->assertHasFormErrors(['name', 'abilities']);
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

        Livewire::test(ListApiTokens::class)
            ->assertCanSeeTableRecords([$created->accessToken])
            ->callTableAction('delete', $created->accessToken);

        $this->assertSame(0, ApiToken::count());
        $this->assertNull(ApiToken::findToken($created->plainTextToken));
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
