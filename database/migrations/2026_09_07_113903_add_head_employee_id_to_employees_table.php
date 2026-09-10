<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Employee Master Excel import's `headID` column (the head/supervisor's
 * own `employeeNo`) resolves to this self-referencing FK — distinct from the
 * pre-existing free-text `head` column (the head's NAME, kept as-is for
 * backward compatibility). See `EmployeeGroupController::import()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->foreignId('head_employee_id')->nullable()->after('head')
                ->constrained('employees')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('employees', function (Blueprint $table) {
            $table->dropConstrainedForeignId('head_employee_id');
        });
    }
};
