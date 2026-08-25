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
        Schema::create('checklist_follow_ups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_request_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offboarding_request_approver_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->foreignId('recipient_user_id')->nullable()->constrained('users')->nullOnDelete();
            // The employee's Nth follow-up across their WHOLE offboarding
            // request (every checklist shares one budget, not one per
            // checklist — see `ChecklistFollowUpService`), so this is a
            // running count scoped to `offboarding_request_id`, not to this
            // one checklist alone.
            $table->unsignedInteger('attempt_number');
            $table->string('status')->default('sent');
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->index(['offboarding_request_id', 'employee_id']);
            $table->index(['offboarding_request_approver_id', 'sent_at'], 'checklist_follow_ups_approver_sent_at_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_follow_ups');
    }
};
