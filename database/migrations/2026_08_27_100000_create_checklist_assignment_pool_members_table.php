<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('checklist_assignment_pool_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_request_approver_id');
            $table->foreignId('employee_id')->constrained();
            $table->foreignId('added_by_user_id')->constrained('users');
            $table->timestamp('added_at');
            $table->timestamps();

            $table->unique(['offboarding_request_approver_id', 'employee_id'], 'checklist_pool_members_assignment_employee_unique');

            // Named explicitly — the auto-generated constraint name exceeds
            // MySQL's 64-character identifier limit.
            $table->foreign('offboarding_request_approver_id', 'checklist_pool_members_assignment_fk')
                ->references('id')->on('offboarding_request_approvers')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('checklist_assignment_pool_members');
    }
};
