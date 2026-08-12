<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->foreignId('delegated_employee_id')->nullable()->after('employee_id')
                ->constrained('employees')->nullOnDelete();
            $table->string('delegation_status')->nullable()->after('status');
            $table->timestamp('delegated_at')->nullable()->after('assigned_at');
            $table->timestamp('delegate_completed_at')->nullable()->after('approved_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delegated_employee_id');
            $table->dropColumn(['delegation_status', 'delegated_at', 'delegate_completed_at']);
        });
    }
};
