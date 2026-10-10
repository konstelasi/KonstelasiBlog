<?php

namespace Tests\Feature;

use App\Filament\Pages\Profile;
use App\Filament\Resources\Users\Pages\CreateUser;
use App\Filament\Resources\Users\Pages\EditUser;
use App\Filament\Resources\Users\Pages\ListUsers;
use App\Models\Post;
use App\Models\User;
use Filament\Actions\Testing\TestAction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class UserAdminTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string}> */
    public static function nonAdmins(): array
    {
        return ['writer' => ['writer'], 'editor' => ['editor']];
    }

    #[DataProvider('nonAdmins')]
    public function test_writers_and_editors_do_not_see_the_users_screen(string $role): void
    {
        $this->actingAs(User::factory()->{$role}()->create());

        $this->get('/admin/users')->assertForbidden();
        $this->get('/admin/users/create')->assertForbidden();
        $this->get('/admin')->assertOk()->assertDontSee('/admin/users');
    }

    public function test_admins_see_the_users_screen(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $this->get('/admin/users')->assertOk();
        $this->get('/admin')->assertOk()->assertSee('/admin/users');
    }

    public function test_creating_an_account_gives_it_the_chosen_role(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Rani Putri',
                'email' => 'rani@example.com',
                'password' => 'a-long-password',
                'role' => 'writer',
            ])
            ->call('create')
            ->assertHasNoFormErrors();

        $rani = User::where('email', 'rani@example.com')->sole();
        $this->assertSame(['writer'], $rani->getRoleNames()->all());
        $this->assertTrue(Hash::check('a-long-password', $rani->password));
        $this->assertTrue($rani->canAccessPanel(filament()->getPanel('admin')));
    }

    public function test_a_new_account_needs_a_password_and_a_role(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->fillForm(['name' => 'Rani Putri', 'email' => 'rani@example.com'])
            ->call('create')
            ->assertHasFormErrors(['password' => 'required', 'role' => 'required']);

        $this->assertDatabaseMissing('users', ['email' => 'rani@example.com']);
    }

    public function test_an_unknown_role_is_rejected(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        Livewire::test(CreateUser::class)
            ->fillForm([
                'name' => 'Rani Putri',
                'email' => 'rani@example.com',
                'password' => 'a-long-password',
                'role' => 'owner',
            ])
            ->call('create')
            ->assertHasFormErrors(['role']);

        $this->assertDatabaseMissing('users', ['email' => 'rani@example.com']);
    }

    public function test_editing_changes_the_role_and_keeps_the_password_when_it_is_left_empty(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $rani = User::factory()->writer()->create(['password' => 'the-old-password']);

        Livewire::test(EditUser::class, ['record' => $rani->getRouteKey()])
            ->assertSchemaStateSet(['role' => 'writer'])
            ->fillForm(['name' => 'Rani P.', 'role' => 'editor', 'password' => ''])
            ->call('save')
            ->assertHasNoFormErrors();

        $rani->refresh();
        $this->assertSame('Rani P.', $rani->name);
        $this->assertSame(['editor'], $rani->getRoleNames()->all());
        $this->assertTrue(Hash::check('the-old-password', $rani->password));
    }

    public function test_an_admin_cannot_delete_themself(): void
    {
        $admin = User::factory()->admin()->create();
        User::factory()->admin()->create();
        $this->actingAs($admin);

        $this->assertFalse($admin->can('delete', $admin));

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->assertActionHidden('delete');
    }

    public function test_the_last_admin_cannot_be_deleted_or_demoted(): void
    {
        $admin = User::factory()->admin()->create();
        $editor = User::factory()->editor()->create();
        $this->actingAs($admin);

        $this->assertTrue($admin->can('update', $admin));

        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['role' => 'editor'])
            ->call('save')
            ->assertHasFormErrors(['role']);

        $this->assertTrue($admin->fresh()->hasRole('admin'));

        // With a second Admin, the first can step down, and the new last Admin is protected.
        $editor->syncRoles('admin');
        Livewire::test(EditUser::class, ['record' => $admin->getRouteKey()])
            ->fillForm(['role' => 'editor'])
            ->call('save')
            ->assertHasNoFormErrors();

        $this->assertTrue($editor->fresh()->hasRole('admin'));
        $this->assertFalse($editor->can('delete', $editor));
    }

    public function test_an_admin_deletes_another_account_and_its_posts_keep_the_byline(): void
    {
        $this->actingAs(User::factory()->admin()->create());
        $rani = User::factory()->writer()->create(['name' => 'Rani Putri']);
        $post = Post::factory()->create(['user_id' => $rani->id, 'author' => 'Rani Putri']);

        Livewire::test(EditUser::class, ['record' => $rani->getRouteKey()])
            ->callAction('delete');

        $this->assertModelMissing($rani);
        $post->refresh();
        $this->assertNull($post->user_id);
        $this->assertSame('Rani Putri', $post->byline());
    }

    public function test_the_last_admin_is_skipped_by_the_bulk_delete(): void
    {
        $admin = User::factory()->admin()->create();
        $other = User::factory()->admin()->create();
        $writer = User::factory()->writer()->create();
        $this->actingAs($admin);

        Livewire::test(ListUsers::class)
            ->selectTableRecords([$admin->getKey(), $other->getKey(), $writer->getKey()])
            ->callAction(TestAction::make('delete')->table()->bulk());

        $this->assertModelExists($admin);
        $this->assertModelMissing($other);
        $this->assertModelMissing($writer);
    }

    public function test_everyone_can_open_their_own_profile(): void
    {
        foreach (['writer', 'editor', 'admin'] as $role) {
            $this->actingAs(User::factory()->{$role}()->create());

            Livewire::test(Profile::class)->assertSuccessful();
        }
    }
}
