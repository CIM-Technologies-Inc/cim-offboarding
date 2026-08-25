<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Every permission this app currently has a meaningful admin capability
     * for, grouped by module (the "module.action" prefix is what the Roles
     * & Permissions page groups by). Existing routes are NOT retrofitted to
     * check these individually in this pass — they stay role-gated (now
     * Spatie-backed, see `EnsureUserHasRole`) — this taxonomy exists so the
     * new page has real, meaningful permissions to create/assign/remove,
     * and so the two brand-new admin pages (Roles & Permissions, Users)
     * can genuinely gate on them.
     */
    private const PERMISSIONS = [
        'dashboard.view',
        'offboarding-requests.view',
        'offboarding-requests.create',
        'offboarding-checklists.view',
        'offboarding-checklists.create',
        'offboarding-checklists.edit',
        'offboarding-checklists.delete',
        'onboarding-checklists.view',
        'onboarding-checklists.create',
        'onboarding-checklists.edit',
        'onboarding-checklists.delete',
        'department-heads.view',
        'department-heads.manage',
        'employee-master.view',
        'employee-master.manage',
        'offboardees.view',
        'approvals.view',
        'email-templates.view',
        'email-templates.manage',
        'roles.view',
        'roles.manage',
        'users.view',
        'users.manage-roles',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
        $approver = Role::firstOrCreate(['name' => User::ROLE_APPROVER, 'guard_name' => 'web']);
        Role::firstOrCreate(['name' => User::ROLE_EMPLOYEE, 'guard_name' => 'web']);

        // Admin gets every permission; approver's default matches their
        // actual current access (calendar + approvals only); employee gets
        // none — their dashboard access stays purely route/identity-scoped,
        // not permission-gated.
        $admin->syncPermissions(self::PERMISSIONS);
        $approver->syncPermissions(['approvals.view']);

        // Backfill every existing account's Spatie role from their current
        // `role` column value, so `hasRole()` agrees with the legacy column
        // for every account from the moment this ships — nobody's access
        // changes as a result of this migration.
        User::whereIn('role', [User::ROLE_ADMIN, User::ROLE_APPROVER, User::ROLE_EMPLOYEE])
            ->each(function (User $user) {
                if (! $user->hasRole($user->role)) {
                    $user->assignRole($user->role);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Role::whereIn('name', [User::ROLE_ADMIN, User::ROLE_APPROVER, User::ROLE_EMPLOYEE])
            ->where('guard_name', 'web')
            ->delete();

        Permission::whereIn('name', self::PERMISSIONS)
            ->where('guard_name', 'web')
            ->delete();
    }
};
