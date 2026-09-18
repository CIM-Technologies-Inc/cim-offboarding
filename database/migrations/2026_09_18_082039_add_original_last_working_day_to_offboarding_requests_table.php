<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->date('original_last_working_day')->nullable()->after('last_working_day');
        });

        // Backfill every existing request with its CURRENT `last_working_day`
        // — this column is the "Extend Due" feature's own immutable
        // baseline, never touched again after this backfill/creation, so a
        // request that predates this migration simply treats whatever its
        // Last Working Day already was as its original one.
        DB::table('offboarding_requests')->update([
            'original_last_working_day' => DB::raw('last_working_day'),
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropColumn('original_last_working_day');
        });
    }
};
