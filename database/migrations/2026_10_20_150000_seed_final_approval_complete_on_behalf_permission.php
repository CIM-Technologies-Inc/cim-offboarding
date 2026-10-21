<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Adds the permission gating "Complete Final Approval on Behalf of Final
 * Approver" — same append-only convention every other permission addition
 * in this app already follows (see e.g.
 * `2026_10_08_120000_seed_users_login_as_permission.php`). Admin gets it by
 * default; every other role stays ungranted until an admin explicitly adds
 * it via the Roles & Permissions page. Falls under the existing
 * `final-approval` permission group (alongside `final-approval.send`), so
 * no changes are needed to `RoleController::groupedPermissions()`/
 * `MODULE_LABEL_OVERRIDES` for it to show up correctly.
 */
return new class extends Migration
{
    private const PERMISSION = 'final-approval.complete-on-behalf';

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
