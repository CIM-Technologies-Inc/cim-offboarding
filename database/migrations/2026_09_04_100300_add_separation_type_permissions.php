<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions for the new Separation Type Management page — same
 * view/create/edit/delete split as Department Heads/Employee Master, each
 * independently gating its own route (see `routes/web.php`).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'separation-types.view',
        'separation-types.create',
        'separation-types.edit',
        'separation-types.delete',
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
