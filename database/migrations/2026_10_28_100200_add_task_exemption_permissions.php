<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Permissions for the Task Exemption page (System Administration) — same
 * view/create/edit/delete split as Separation Types/Employee Master, each
 * independently gating its own route (see `routes/web.php`).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'task-exemptions.view',
        'task-exemptions.create',
        'task-exemptions.edit',
        'task-exemptions.delete',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::where('name', User::ROLE_ADMIN)->where('guard_name', 'web')->first();
        $admin?->givePermissionTo(self::PERMISSIONS);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
    }
};
