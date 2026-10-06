<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

class RoleController extends Controller
{
    /**
     * The 3 roles the rest of the app's access control is hardcoded to by
     * name (route `role:` middleware groups, `User::ROLE_*` constants,
     * `MenuHelper`'s nav gating) — renaming or deleting any of these would
     * silently lock every account of that role out of the app, so both are
     * blocked regardless of what the request asks for. Their PERMISSIONS
     * remain fully editable — only the role's identity is protected.
     */
    private const SYSTEM_ROLES = [User::ROLE_ADMIN, User::ROLE_APPROVER, User::ROLE_EMPLOYEE];

    public function index(): View
    {
        $roles = Role::withCount('permissions')
            ->orderBy('name')
            ->get()
            ->map(fn (Role $role) => [
                'id' => $role->id,
                'name' => $role->name,
                'isSystemRole' => $this->isSystemRole($role),
                'permissionCount' => $role->permissions_count,
                'userCount' => $role->users()->count(),
                'permissionIds' => $role->permissions->pluck('id')->all(),
            ]);

        return view('pages.roles.index', [
            'title' => 'Roles & Permissions',
            'roles' => $roles,
            'permissionGroups' => $this->groupedPermissions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'unique:roles,name'],
        ]);

        $role = Role::create(['name' => $validated['name'], 'guard_name' => 'web']);

        ActivityLog::record('role_created', 'Roles & Permissions', "Role \"{$role->name}\" created.", [
            'subject_type' => 'Role',
            'subject_id' => $role->id,
        ]);

        return back()->with('success', "Role \"{$validated['name']}\" created.");
    }

    public function update(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', Rule::unique('roles', 'name')->ignore($role->id)],
        ]);

        if ($this->isSystemRole($role) && $validated['name'] !== $role->name) {
            return back()->with('error', "\"{$role->name}\" is a system role — its name can't be changed, since the app's access control is hardcoded to it.");
        }

        $previousName = $role->name;
        $role->update(['name' => $validated['name']]);

        ActivityLog::record('role_updated', 'Roles & Permissions', "Role renamed from \"{$previousName}\" to \"{$role->name}\".", [
            'subject_type' => 'Role',
            'subject_id' => $role->id,
            'old_values' => ['name' => $previousName],
            'new_values' => ['name' => $role->name],
        ]);

        return back()->with('success', 'Role updated.');
    }

    public function destroy(Role $role): RedirectResponse
    {
        if ($this->isSystemRole($role)) {
            return back()->with('error', "\"{$role->name}\" is a system role and cannot be deleted.");
        }

        $userCount = $role->users()->count();

        if ($userCount > 0) {
            return back()->with('error', "Cannot delete \"{$role->name}\" — it is currently assigned to {$userCount} user(s). Reassign them first.");
        }

        $roleName = $role->name;
        $roleId = $role->id;
        $role->delete();

        ActivityLog::record('role_deleted', 'Roles & Permissions', "Role \"{$roleName}\" deleted.", [
            'subject_type' => 'Role',
            'subject_id' => $roleId,
        ]);

        return back()->with('success', 'Role deleted.');
    }

    /**
     * One form submit assigns AND removes permissions in a single action —
     * whatever wasn't checked is removed, whatever was checked is added.
     */
    public function syncPermissions(Request $request, Role $role): RedirectResponse
    {
        $validated = $request->validate([
            'permissions' => ['array'],
            'permissions.*' => ['integer', 'exists:permissions,id'],
        ]);

        // Spatie's syncPermissions()/getStoredPermission() only resolves a
        // BARE integer as an id — an ARRAY of integers (exactly what these
        // checkboxes submit) is instead treated as an array of permission
        // NAMES, so passing raw ids here throws PermissionDoesNotExist for
        // every one of them. Resolving to actual Permission models first
        // sidesteps that entirely.
        $previousPermissions = $role->permissions->pluck('name')->values()->all();

        $permissions = Permission::whereIn('id', $validated['permissions'] ?? [])->get();

        $role->syncPermissions($permissions);

        ActivityLog::record('role_permissions_updated', 'Roles & Permissions', "Permissions updated for role \"{$role->name}\".", [
            'subject_type' => 'Role',
            'subject_id' => $role->id,
            'old_values' => ['permissions' => $previousPermissions],
            'new_values' => ['permissions' => $permissions->pluck('name')->values()->all()],
        ]);

        return back()->with('success', "Permissions updated for \"{$role->name}\".");
    }

    private function isSystemRole(Role $role): bool
    {
        return in_array($role->name, self::SYSTEM_ROLES, true);
    }

    /**
     * Every permission grouped by its "module.action" prefix, for the
     * checkbox panel — e.g. "offboarding-checklists.view" and
     * "offboarding-checklists.edit" both land under the "Offboarding
     * Checklists" group.
     *
     * @return array<int, array{label: string, permissions: \Illuminate\Support\Collection}>
     */
    private function groupedPermissions(): array
    {
        return Permission::orderBy('name')
            ->get()
            ->groupBy(fn (Permission $permission) => Str::before($permission->name, '.'))
            ->map(fn ($permissions, $module) => [
                'label' => Str::of($module)->replace('-', ' ')->title()->toString(),
                'permissions' => $permissions->values(),
            ])
            ->values()
            ->all();
    }
}
