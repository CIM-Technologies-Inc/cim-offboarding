<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * "Manage Final Approver" — gates the Offboarding Checklist page's Final
 * Approver section (Set Final Approver button, and activating/deactivating
 * a configured Final Approver). A dedicated permission rather than reusing
 * `offboarding-checklists.*` (unlike General Signatory, which deliberately
 * reuses that page's own permissions) since this feature controls a
 * sensitive, org-wide signatory used on every future Clearance Form —
 * worth its own toggle on the Roles & Permissions page.
 *
 * Granted to the Admin role only by default. Uses `givePermissionTo()`
 * (additive), not `syncPermissions()`, so it can never undo permission
 * customizations an admin already made to any role since they were first
 * seeded.
 */
return new class extends Migration
{
    private const PERMISSION = 'final-approver.manage';

    public function up(): void
    {
        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        Role::where('name', User::ROLE_ADMIN)
            ->where('guard_name', 'web')
            ->first()
            ?->givePermissionTo(self::PERMISSION);
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)
            ->where('guard_name', 'web')
            ->delete();
    }
};
