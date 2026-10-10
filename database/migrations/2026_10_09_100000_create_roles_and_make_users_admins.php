<?php

use App\Models\User;
use App\Support\Rbac;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * Until now every account ran the blog. Making each existing one an
     * Admin keeps that true, so the deploy that starts enforcing roles
     * cannot lock anyone out.
     */
    public function up(): void
    {
        Rbac::sync();

        User::query()->each(fn (User $user) => $user->syncRoles(Rbac::ADMIN));
    }

    /** The tables go with the earlier migration, so there is nothing to undo here. */
    public function down(): void
    {
        //
    }
};
