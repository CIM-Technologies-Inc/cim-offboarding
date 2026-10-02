<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * This app's audit trail for authentication/account-security events —
 * the same "a queryable DB table, not just a flat file" convention
 * `offboarding_activities` already established for business events, now
 * applied to login/lockout/reactivation events. See `SecurityLog::record()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('security_logs', function (Blueprint $table) {
            $table->id();
            // Null when the attempted username doesn't match any real
            // account at all (e.g. a brute-force scan against a made-up
            // username) — still worth recording for monitoring, just with
            // no account to attach to.
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('event');
            $table->string('attempted_username')->nullable();
            // Who performed an ADMIN action on this log entry's subject
            // (e.g. the admin who reactivated the account) — distinct from
            // `user_id`, which is always the account the event happened TO.
            $table->foreignId('performed_by_user_id')->nullable()->constrained('users', indexName: 'security_logs_performed_by_user_id_fk')->nullOnDelete();
            $table->string('ip_address')->nullable();
            $table->string('user_agent')->nullable();
            $table->json('context')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('security_logs');
    }
};
