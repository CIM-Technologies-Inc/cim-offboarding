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
        // The general "log this person straight into their account" email
        // link — distinct from `password_reset_requests` (changes a
        // password, never authenticates) and from the three existing
        // scoped one-click approval-token tables (`checklist_approval_tokens`/
        // `general_signatory_approval_tokens`/`final_approval_tokens`, which
        // act via the token alone and never establish a session). The row
        // id is the lookup key; `token` is the secret, stored hashed and
        // only ever verified via `Hash::check()` — same convention as every
        // other token table in this app.
        Schema::create('login_link_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token');
            // A route NAME (e.g. "approvals.index"), never a raw URL, so a
            // stored link survives a domain/scheme change — null means
            // "use `User::defaultHomeRouteName()` at confirm time", which
            // already covers every recipient type this feature needs.
            $table->string('redirect_route')->nullable();
            // `dateTime`, not `timestamp` — this app's own
            // `2026_09_02_170400_fix_final_approval_tokens_expires_at_type.php`
            // migration already flagged `timestamp` as carrying MySQL's
            // implicit `ON UPDATE CURRENT_TIMESTAMP` behavior on the first
            // such column in a table, which would silently push this
            // column's value forward on any unrelated update.
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('login_link_tokens');
    }
};
