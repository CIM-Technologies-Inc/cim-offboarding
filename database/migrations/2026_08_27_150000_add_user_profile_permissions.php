<?php

use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * New "User Profile" permission group — the Roles & Permissions page groups
 * permissions by their "module." prefix (see `RoleController::groupedPermissions()`),
 * so these automatically appear there as a "User Profile" section with no
 * further changes to that page needed. Enforced field-by-field on the
 * Profile page itself (`ProfileController`, `resources/views/components/profile/*`).
 *
 * Granted to every existing built-in role by default — today, EVERY user
 * can already edit every one of these regardless of role, so seeding them
 * all as granted preserves that exact behavior the moment this ships. An
 * admin can then deliberately restrict any of them per role from the Roles
 * & Permissions page; nothing changes on its own. Uses `givePermissionTo()`
 * (additive), not `syncPermissions()`, so it can never undo permission
 * customizations an admin already made to these roles since they were
 * first seeded.
 */
return new class extends Migration
{
    private const PERMISSIONS = [
        'user-profile.view',
        'user-profile.edit-personal-info',
        'user-profile.edit-contact-info',
        'user-profile.edit-employment-info',
        'user-profile.edit-photo',
        'user-profile.edit-signature',
        'user-profile.view-documents',
    ];

    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        Role::where('guard_name', 'web')
            ->get()
            ->each(fn (Role $role) => $role->givePermissionTo(self::PERMISSIONS));
    }

    public function down(): void
    {
        Permission::whereIn('name', self::PERMISSIONS)
            ->where('guard_name', 'web')
            ->delete();
    }
};
