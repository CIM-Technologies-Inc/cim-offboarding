<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The app's unified audit trail — replaces the narrower `security_logs`
 * table (auth-only) with one table covering authentication, user/role/
 * employee management, and (in a follow-up pass) offboarding/checklist
 * transactions. See `ActivityLog::record()` for the single write path
 * every call site uses.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();

            // The ACTOR — the account that performed the action. Null for
            // a failed login against a username that doesn't match any
            // account, or a system/scheduled-command-triggered event (e.g.
            // an expired-session sweep logs the SESSION's owner here, not
            // "no one" — see LogExpiredSessions).
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();

            // Actor snapshot — frozen at write time, same "survives the
            // underlying record changing/being deleted later" convention
            // this app already uses for Separation Type/signatories/etc.
            // on an OffboardingRequest.
            $table->string('employee_number')->nullable();
            $table->string('employee_name')->nullable();
            $table->string('username')->nullable();
            $table->string('roles')->nullable();

            $table->string('action');
            $table->string('module');
            $table->text('description')->nullable();

            // The record the action was performed ON, when distinct from
            // the actor (e.g. an admin reactivating a DIFFERENT user's
            // account) — polymorphic-style pointer, same shape as
            // Laravel's own morph columns.
            $table->string('subject_type')->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();

            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();

            $table->string('status')->default('success');
            $table->string('failure_reason')->nullable();

            $table->string('ip_address')->nullable();
            $table->text('user_agent')->nullable();
            $table->string('browser')->nullable();
            $table->string('browser_version')->nullable();
            $table->string('platform')->nullable();
            $table->string('session_id')->nullable();
            $table->string('route')->nullable();

            $table->timestamps();

            $table->index('action');
            $table->index('module');
            $table->index('status');
            $table->index('subject_id');
            $table->index('created_at');
            $table->index(['user_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('activity_logs');
    }
};
