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
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->timestamp('immediate_head_checklists_released_at')->nullable()->after('final_pay_notified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropColumn('immediate_head_checklists_released_at');
        });
    }
};
