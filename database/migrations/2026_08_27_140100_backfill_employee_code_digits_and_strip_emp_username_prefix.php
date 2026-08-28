<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time correction so every login username matches the app's new
 * format ("EMP03050" -> "03050"), without ever touching the employee
 * number itself:
 *
 * 1. Populates `employees.employee_code_digits` for every existing row —
 *    the "EMP" prefix stripped from `employee_code` (left as-is if a code
 *    somehow doesn't start with "EMP"). Every future save keeps this in
 *    sync automatically via `Employee::booted()`; this migration only
 *    covers rows that already existed before that listener did.
 * 2. Strips the same "EMP" prefix from every EXISTING `users.username`
 *    that currently has one. Since `username` was already unique and
 *    every affected value shared the identical 3-character prefix,
 *    stripping it preserves uniqueness — no collision is possible. A
 *    username that never had an "EMP" prefix (e.g. a manually created
 *    account unrelated to any employee) is left completely untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('employees')->orderBy('id')->chunkById(200, function ($employees) {
            foreach ($employees as $employee) {
                DB::table('employees')->where('id', $employee->id)->update([
                    'employee_code_digits' => $this->stripEmpPrefix($employee->employee_code),
                ]);
            }
        });

        DB::table('users')->orderBy('id')->chunkById(200, function ($users) {
            foreach ($users as $user) {
                if (Str::startsWith(strtoupper($user->username), 'EMP')) {
                    DB::table('users')->where('id', $user->id)->update([
                        'username' => $this->stripEmpPrefix($user->username),
                    ]);
                }
            }
        });
    }

    public function down(): void
    {
        // Not reversible — there's no record of which usernames originally
        // had the "EMP" prefix versus a manually created account that
        // never did, so blindly re-adding it risks corrupting the latter.
    }

    private function stripEmpPrefix(string $code): string
    {
        return Str::startsWith(strtoupper($code), 'EMP') ? substr($code, 3) : $code;
    }
};
