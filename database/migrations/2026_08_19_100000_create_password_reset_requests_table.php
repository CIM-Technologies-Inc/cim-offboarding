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
        // Deliberately NOT Laravel's stock `password_reset_tokens` table
        // (primary-keyed by email) — this app's `users.email` column isn't
        // unique (see the 2026_08_11 migration dropping that constraint;
        // several employees legitimately share one email in this dataset),
        // so a request must be tied to a specific `user_id`, not an email
        // string. The reset link's row id is the lookup key; `token` is the
        // secret, stored hashed (never queried directly, always verified
        // via Hash::check — same convention as the stock broker).
        Schema::create('password_reset_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('token');
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_requests');
    }
};
