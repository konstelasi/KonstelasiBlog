<?php

namespace Tests\Feature;

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RbacTest extends TestCase
{
    use RefreshDatabase;

    /** @return array<string, array{string, list<string>}> */
    public static function roles(): array
    {
        $writer = ['post.view', 'post.create', 'post.update.own', 'post.delete.own'];
        $editor = [...$writer, 'post.update.any', 'post.publish', 'post.delete.any', 'tag.manage'];

        return [
            'writer' => ['writer', $writer],
            'editor' => ['editor', $editor],
            'admin' => ['admin', [...$editor, 'user.manage']],
        ];
    }

    /** @param list<string> $expected */
    #[DataProvider('roles')]
    public function test_each_role_holds_exactly_its_permissions(string $role, array $expected): void
    {
        Rbac::sync();

        $held = Role::findByName($role)->permissions->pluck('name')->all();

        sort($held);
        sort($expected);
        $this->assertSame($expected, $held);
    }

    public function test_syncing_twice_changes_nothing(): void
    {
        Rbac::sync();
        $roles = Role::count();
        $permissions = Permission::count();
        $links = DB::table('role_has_permissions')->count();

        Rbac::sync();

        $this->assertSame($roles, Role::count());
        $this->assertSame($permissions, Permission::count());
        $this->assertSame($links, DB::table('role_has_permissions')->count());
        $this->assertSame(3, $roles);
        $this->assertSame(count(Rbac::permissions()), $permissions);
    }

    public function test_syncing_takes_back_a_permission_the_table_does_not_grant(): void
    {
        Rbac::sync();
        Role::findByName('writer')->givePermissionTo('post.publish');
        $this->assertTrue(Role::findByName('writer')->hasPermissionTo('post.publish'));

        Rbac::sync();

        $this->assertFalse(Role::findByName('writer')->fresh()->hasPermissionTo('post.publish'));
    }

    public function test_the_factory_states_give_each_role(): void
    {
        $this->assertTrue(User::factory()->admin()->create()->hasRole('admin'));
        $this->assertTrue(User::factory()->editor()->create()->hasRole('editor'));

        $writer = User::factory()->writer()->create();
        $this->assertTrue($writer->can('post.create'));
        $this->assertFalse($writer->can('post.publish'));
        $this->assertFalse($writer->can('user.manage'));
    }

    public function test_the_migration_makes_every_existing_account_an_admin(): void
    {
        $accounts = User::factory()->count(2)->create();
        $this->assertSame(0, DB::table('model_has_roles')->count());

        (require database_path('migrations/2026_10_09_100000_create_roles_and_make_users_admins.php'))->up();

        foreach ($accounts as $user) {
            $this->assertTrue($user->fresh()->hasRole('admin'));
        }
    }

    public function test_rbac_assign_gives_one_role_and_replaces_the_old_one(): void
    {
        $user = User::factory()->create(['email' => 'rani@example.com']);

        $this->artisan('rbac:assign', ['email' => 'rani@example.com', 'role' => 'writer'])->assertSuccessful();
        $this->artisan('rbac:assign', ['email' => 'rani@example.com', 'role' => 'editor'])->assertSuccessful();

        $this->assertSame(['editor'], $user->fresh()->getRoleNames()->all());
    }

    public function test_rbac_assign_rejects_an_unknown_role(): void
    {
        $user = User::factory()->create(['email' => 'rani@example.com']);

        $this->artisan('rbac:assign', ['email' => 'rani@example.com', 'role' => 'owner'])->assertFailed();

        $this->assertSame([], $user->fresh()->getRoleNames()->all());
    }

    public function test_rbac_assign_will_not_demote_the_last_admin(): void
    {
        $admin = User::factory()->admin()->create(['email' => 'damar@example.com']);

        $this->artisan('rbac:assign', ['email' => 'damar@example.com', 'role' => 'editor'])->assertFailed();

        $this->assertTrue($admin->fresh()->hasRole('admin'));

        User::factory()->admin()->create();
        $this->artisan('rbac:assign', ['email' => 'damar@example.com', 'role' => 'editor'])->assertSuccessful();
    }

    public function test_assign_refuses_an_unknown_role(): void
    {
        $user = User::factory()->writer()->create();

        $this->expectException(InvalidArgumentException::class);

        Rbac::assign($user, 'owner');
    }

    public function test_rbac_assign_rejects_an_unknown_email(): void
    {
        $this->artisan('rbac:assign', ['email' => 'nobody@example.com', 'role' => 'admin'])->assertFailed();
    }

    public function test_rbac_sync_command_runs(): void
    {
        $this->assertSame(0, Artisan::call('rbac:sync'));
        $this->assertSame(3, Role::count());
    }
}
