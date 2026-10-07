<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The 3 permissions the new SMTP Settings module needs — same
 * create-and-grant-to-admin pattern as every other permission added this
 * session (e.g. `seed_logs_view_permission.php`).
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'smtp-settings.view',
        'smtp-settings.manage',
        'smtp-settings.test',
    ];

    public function up(): void
    {
        $admin = Role::where('name', User::ROLE_ADMIN)->where('guard_name', 'web')->first();

        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);

            if ($admin && ! $admin->hasPermissionTo($permission)) {
                $admin->givePermissionTo($permission);
            }
        }
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)->where('guard_name', 'web')->delete();
    }
};
