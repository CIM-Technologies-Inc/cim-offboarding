<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task Exemption no longer asks for an Exemption Name — `name` is now a
 * label generated from the rule's own targets (see
 * `TaskExemptionController::generatedName()`), so two rules with the same
 * targets can legitimately share it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('task_exemptions', function (Blueprint $table) {
            $table->dropUnique(['name']);
        });
    }

    public function down(): void
    {
        Schema::table('task_exemptions', function (Blueprint $table) {
            $table->unique('name');
        });
    }
};
