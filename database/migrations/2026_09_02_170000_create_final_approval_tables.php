<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Final Approval workflow — a completed offboarding request's
 * one-time sign-off by the active Final Signatory (see `FinalApprover`),
 * triggered from the Offboardee page's "Final Approval" button. Modeled
 * closely on `offboarding_request_general_signatories`/
 * `general_signatory_approval_tokens`, with the same "assignment row +
 * hashed-token email-link row" split.
 *
 * `offboarding_request_final_approvals.offboarding_request_id` is UNIQUE —
 * at most one Final Approval process ever exists per request, so re-clicking
 * "Final Approval" re-sends against the SAME row (never a duplicate) and
 * an already-approved one can never be re-initiated.
 *
 * `employee_id` snapshots whichever Final Signatory the email was actually
 * sent to (refreshed on every resend while still pending) — the approval
 * link is thereby tied to a specific request AND signatory, but
 * `FinalApprovalController` additionally re-validates at approval time that
 * this is STILL the currently active Final Approver, so a config change
 * between sending and clicking can never let a superseded signatory
 * approve.
 *
 * Every foreign key here is given an explicit short constraint name —
 * this table's own long name means MySQL's default auto-generated
 * constraint names (table + column + "_foreign") would exceed its 64-char
 * identifier limit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboarding_request_final_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_request_id');
            $table->foreignId('employee_id');
            $table->string('status')->default('pending');
            $table->foreignId('initiated_by')->nullable();
            $table->timestamp('initiated_at')->nullable();
            $table->timestamp('first_viewed_at')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->foreignId('approved_by')->nullable();
            $table->timestamps();

            $table->unique('offboarding_request_id', 'final_approvals_request_unique');
            $table->foreign('offboarding_request_id', 'final_approvals_request_fk')
                ->references('id')->on('offboarding_requests')->cascadeOnDelete();
            $table->foreign('employee_id', 'final_approvals_employee_fk')
                ->references('id')->on('employees')->restrictOnDelete();
            $table->foreign('initiated_by', 'final_approvals_initiator_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('approved_by', 'final_approvals_approver_fk')
                ->references('id')->on('users')->nullOnDelete();
        });

        Schema::create('final_approval_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('offboarding_request_final_approval_id');
            $table->string('token');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->foreign('offboarding_request_final_approval_id', 'final_approval_tokens_approval_fk')
                ->references('id')->on('offboarding_request_final_approvals')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_approval_tokens');
        Schema::dropIfExists('offboarding_request_final_approvals');
    }
};
