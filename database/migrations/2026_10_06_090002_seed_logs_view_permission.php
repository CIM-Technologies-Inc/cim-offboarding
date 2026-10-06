<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The one permission the new Logs module needs — view-only by design,
 * since no role (admin included) may edit or delete an audit-trail entry
 * through the UI, so there's no matching `logs.manage`/`logs.delete`.
 */
return new class extends Migration
{
    private const PERMISSION = 'logs.view';

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
