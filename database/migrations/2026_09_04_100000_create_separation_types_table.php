<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-managed replacement for the old hardcoded `OffboardingRequest::REASONS`
 * list — each row is a Separation Type an admin can create/edit/delete from
 * the Separation Type Management page, with its own Description/Definition
 * and Default Notice Period (days). `title` is unique so the New Offboarding
 * Request form's picker never shows two indistinguishable options.
 *
 * Deliberately holds no reference back to `offboarding_requests` — a
 * request never reads this table live after creation; see
 * `2026_09_04_100100_...` for the frozen-snapshot columns on
 * `offboarding_requests` that make later edits/deletes here safe.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('separation_types', function (Blueprint $table) {
            $table->id();
            $table->string('title')->unique();
            $table->text('description');
            $table->unsignedSmallInteger('default_notice_period_days');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('separation_types');
    }
};
