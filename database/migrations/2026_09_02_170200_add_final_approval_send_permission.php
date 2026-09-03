<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * "Send Final Approval" — gates the Offboardee page's "Final Approval"
 * button/endpoint (see `FinalApprovalController::send()`), which emails the
 * currently active Final Signatory to sign off on a COMPLETED offboarding
 * request. Granted to the Admin role only by default. Uses
 * `givePermissionTo()` (additive), not `syncPermissions()`, so it can never
 * undo permission customizations an admin already made to any role.
 */
return new class extends Migration
{
    private const PERMISSION = 'final-approval.send';

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
