<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Backs the User<->Employee relationship once `users.username` no longer
// equals `employees.employee_code` verbatim (usernames drop the "EMP"
// prefix; employee_code itself is never touched — see the matching
// migration/model changes). Kept in sync automatically by
// `Employee::booted()`'s `saving` listener, so nothing else in the app
// needs to maintain it by hand.
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->string('employee_code_digits')->nullable()->after('employee_code')->index();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropColumn('employee_code_digits');
        });
    }
};
