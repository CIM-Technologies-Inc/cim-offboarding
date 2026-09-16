<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permission for the new "Cancel Offboarding" action (Offboardee page) —
 * its own dedicated permission, same reasoning as `offboarding-requests.reset`
 * right beside it: a destructive, admin-gated action independent of the
 * general `offboardees.view`/`approvals.approve` permissions, so an Admin
 * can grant/revoke it separately.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'offboarding-requests.cancel',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::where('name', User::ROLE_ADMIN)->where('guard_name', 'web')->first();
        $admin?->givePermissionTo(self::PERMISSIONS);
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
    }
};
