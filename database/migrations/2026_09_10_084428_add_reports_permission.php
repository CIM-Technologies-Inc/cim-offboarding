<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permission for the new Reports page — read-only, so unlike most other
 * modules in this app it only ever needs a `.view` gate (no create/edit/
 * delete concept for a reporting page).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'reports.view',
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
