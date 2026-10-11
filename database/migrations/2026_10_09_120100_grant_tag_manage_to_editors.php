<?php

use App\Support\Rbac;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration
{
    /**
     * `tag.manage` is new, and the deploy does not run `rbac:sync`. Running
     * the sync here gives Editors and Admins the permission as soon as the
     * deploy migrates.
     */
    public function up(): void
    {
        Rbac::sync();
    }

    /** The sync is idempotent and the permission goes with the table, so there is nothing to undo. */
    public function down(): void
    {
        //
    }
};
