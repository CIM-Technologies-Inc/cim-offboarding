<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * "Reset Offboarding Request" — gates the Offboardee page's Reset
 * Offboarding button/endpoint (see `OffboardingRequestController::reset()`),
 * which wipes an existing offboarding request's checklist/approval progress
 * and reinitializes it exactly like a newly created request. Deliberately
 * its own permission (not folded into `offboardees.view`) since it's a
 * destructive, irreversible action, not a read.
 *
 * Granted to the Admin role only by default, per the feature's own
 * requirement — every other existing role (including Approver) gets nothing
 * here. Uses `givePermissionTo()` (additive), not `syncPermissions()`, so it
 * can never undo permission customizations an admin already made to any
 * role since they were first seeded.
 */
return new class extends Migration
{
    private const PERMISSION = 'offboarding-requests.reset';

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
