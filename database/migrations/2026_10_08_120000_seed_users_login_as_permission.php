<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Adds the one new permission the "Login As User" impersonation feature
 * needs — same append-only convention every other permission addition in
 * this app already follows (see e.g.
 * `2026_10_02_090002_seed_users_manage_status_permission.php`). Admin gets
 * it by default; every other role stays ungranted until an admin
 * explicitly adds it via the Roles & Permissions page.
 */
return new class extends Migration
{
    private const PERMISSION = 'users.login-as';

    public function up(): void
    {
        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        $admin = Role::where('name', User::ROLE_ADMIN)->where('guard_name', 'web')->first();

        if ($admin && ! $admin->hasPermissionTo(self::PERMISSION)) {
            $admin->givePermissionTo(self::PERMISSION);
        }
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
    }
};
