<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tracks consecutive failed login attempts and account-blocked state
 * directly on the `users` row — see `AuthController::store()`'s own
 * docblock for why this must live on the account itself (immune to page
 * refresh/browser change/frontend tampering) rather than in session or
 * client-side state. `blocked_at` is the single source of truth for
 * "blocked" (null = not blocked) — no separate boolean needed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unsignedTinyInteger('failed_login_attempts')->default(0)->after('must_change_password');
            $table->timestamp('blocked_at')->nullable()->after('failed_login_attempts');
            $table->string('blocked_reason')->nullable()->after('blocked_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['failed_login_attempts', 'blocked_at', 'blocked_reason']);
        });
    }
};
