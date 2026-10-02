<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Adds the one new permission the account-lockout feature needs
 * (reactivating a blocked account) — kept as its own migration rather
 * than editing `2026_08_24_093000_seed_roles_and_permissions.php` in
 * place, same append-only convention every other permission addition in
 * this app already follows.
 */
return new class extends Migration
{
    private const PERMISSION = 'users.manage-status';

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
