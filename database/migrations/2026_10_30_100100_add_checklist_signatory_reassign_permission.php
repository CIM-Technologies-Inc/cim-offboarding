<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * HR/Admin's manual "Assign New Clearance Signatory" action (and the
 * Approvals page's Clearance Signatory Escalations panel). Users holding it
 * are also who the escalation alerts go to — see
 * `ClearanceSignatoryEscalationService::hrAdminUsers()`.
 */
return new class extends Migration
{
    private const PERMISSION = 'checklist-signatories.reassign';

    public function up(): void
    {
        Permission::firstOrCreate(['name' => self::PERMISSION, 'guard_name' => 'web']);

        Role::where('name', User::ROLE_ADMIN)->where('guard_name', 'web')->first()?->givePermissionTo(self::PERMISSION);

        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function down(): void
    {
        Permission::where('name', self::PERMISSION)->where('guard_name', 'web')->delete();
    }
};
