<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permission for the new "Extend Due" action on the Offboarding Status
 * page — its own dedicated permission (not folded into `approvals.approve`)
 * so an Admin can grant/revoke it independently of the existing
 * approve/decline/remind permissions, per the feature's own requirement.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'checklists.extend-due',
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
