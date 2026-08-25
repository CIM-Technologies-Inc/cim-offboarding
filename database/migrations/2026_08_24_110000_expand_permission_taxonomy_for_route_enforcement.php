<?php

use App\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

return new class extends Migration
{
    /**
     * Permissions that no longer correspond to a real, distinctly-gated
     * page/action now that routes actually check permissions (see
     * `routes/web.php`) — split into finer create/edit/delete permissions
     * below, or dropped outright when nothing ever gated on them.
     */
    private const REMOVED_PERMISSIONS = [
        'offboarding-requests.view',
        'department-heads.manage',
        'employee-master.manage',
        'email-templates.manage',
    ];

    /**
     * Newly added permissions this migration creates — the finer
     * create/edit/delete split for the 3 modules above, plus
     * `approvals.approve` (distinct from `approvals.view`, so a delegate
     * can work on a checklist without needing final-approval capability)
     * and `calendar.view` (Calendar had no permission at all before this
     * pass, only a role gate).
     */
    private const ADDED_PERMISSIONS = [
        'department-heads.create',
        'department-heads.edit',
        'department-heads.delete',
        'employee-master.create',
        'employee-master.edit',
        'employee-master.delete',
        'email-templates.create',
        'email-templates.edit',
        'email-templates.delete',
        'approvals.approve',
        'calendar.view',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        foreach (self::ADDED_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        DB::table('permissions')->whereIn('name', self::REMOVED_PERMISSIONS)->where('guard_name', 'web')->delete();

        $admin = Role::where('name', User::ROLE_ADMIN)->first();
        $approver = Role::where('name', User::ROLE_APPROVER)->first();

        // Admin always gets every permission that currently exists.
        $admin->syncPermissions(Permission::where('guard_name', 'web')->pluck('name')->all());

        // Approver's real, current capability set under the new names —
        // identical in effect to what it already had (approvals.view +
        // the old approvals.act it lost earlier), plus calendar.view since
        // Calendar was already role-gated to admin+approver with no
        // permission representing it until now.
        $approver->syncPermissions(['approvals.view', 'approvals.approve', 'calendar.view']);

        // Employee role stays permission-less — its dashboard access is
        // identity-scoped (own offboarding request only), not an
        // admin-toggleable module.
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Permission::whereIn('name', self::ADDED_PERMISSIONS)->where('guard_name', 'web')->delete();

        foreach (self::REMOVED_PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $admin = Role::where('name', User::ROLE_ADMIN)->first();
        $approver = Role::where('name', User::ROLE_APPROVER)->first();

        $admin?->syncPermissions(Permission::where('guard_name', 'web')->pluck('name')->all());
        $approver?->syncPermissions(['approvals.view']);
    }
};
