<?php

namespace App\Support;

use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * The one definition of who may do what in the admin. Roles and permissions
 * live in database tables, but this table is the truth: `sync()` makes the
 * database match it, so a change here plus `php artisan rbac:sync` is the
 * whole procedure.
 *
 * Admin lists every permission on purpose. There is no bypass rule, so what
 * a role can do is only what this table says.
 */
final class Rbac
{
    public const ADMIN = 'admin';

    public const EDITOR = 'editor';

    public const WRITER = 'writer';

    public const POST_VIEW = 'post.view';

    public const POST_CREATE = 'post.create';

    public const POST_UPDATE_OWN = 'post.update.own';

    public const POST_UPDATE_ANY = 'post.update.any';

    public const POST_PUBLISH = 'post.publish';

    public const POST_DELETE_OWN = 'post.delete.own';

    public const POST_DELETE_ANY = 'post.delete.any';

    public const USER_MANAGE = 'user.manage';

    /** @var list<string> */
    public const ROLES = [self::ADMIN, self::EDITOR, self::WRITER];

    /**
     * Each role with the permissions it holds.
     *
     * @return array<string, list<string>>
     */
    public static function table(): array
    {
        $writer = [
            self::POST_VIEW,
            self::POST_CREATE,
            self::POST_UPDATE_OWN,
            self::POST_DELETE_OWN,
        ];

        $editor = [
            ...$writer,
            self::POST_UPDATE_ANY,
            self::POST_PUBLISH,
            self::POST_DELETE_ANY,
        ];

        return [
            self::WRITER => $writer,
            self::EDITOR => $editor,
            self::ADMIN => [...$editor, self::USER_MANAGE],
        ];
    }

    /** @return list<string> */
    public static function permissions(): array
    {
        return array_values(array_unique(array_merge(...array_values(self::table()))));
    }

    /**
     * Create what is missing and reset each role to the table. It is safe to
     * run again and again. Permissions that are no longer in the table are
     * removed, and the permission cache is cleared at the end.
     */
    public static function sync(): void
    {
        $guard = config('auth.defaults.guard');

        foreach (self::permissions() as $name) {
            Permission::findOrCreate($name, $guard);
        }

        Permission::where('guard_name', $guard)->whereNotIn('name', self::permissions())->delete();

        foreach (self::table() as $name => $permissions) {
            Role::findOrCreate($name, $guard)->syncPermissions($permissions);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
