<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * Seeds (or updates) a single fixed-credential admin account —
 * username `admin`, password `123456` — with every permission the app
 * currently has. Re-running this seeder is always safe: it never creates a
 * duplicate account (matched on `username`), and it re-syncs the `admin`
 * Spatie role against `Permission::all()` every time rather than trusting
 * whatever the original `seed_roles_and_permissions` migration synced at
 * the time it ran, so this stays correct even after later migrations add
 * new permissions with no matching re-sync of their own.
 */
class AdminUserSeeder extends Seeder
{
    private const USERNAME = 'admin';

    private const PASSWORD = '123456';

    public function run(): void
    {
        $adminRole = Role::firstOrCreate(['name' => User::ROLE_ADMIN, 'guard_name' => 'web']);
        $adminRole->syncPermissions(Permission::all());

        $user = User::updateOrCreate(
            ['username' => self::USERNAME],
            [
                'name' => 'Admin',
                'role' => User::ROLE_ADMIN,
                'email' => 'admin@example.com',
                // Cast to 'hashed' on the User model — stored as a bcrypt
                // hash, never in plain text, even though the seeder source
                // itself is plain text.
                'password' => self::PASSWORD,
                // Deliberately false — a seeded, known dev/test credential
                // must keep working exactly as given on every login, not
                // force a change to some OTHER password after the first
                // one (which would break repeatable `db:seed` runs).
                'must_change_password' => false,
            ]
        );

        if (! $user->hasRole(User::ROLE_ADMIN)) {
            $user->assignRole(User::ROLE_ADMIN);
        }
    }
}
