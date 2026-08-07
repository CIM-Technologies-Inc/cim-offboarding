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
            $table->string('resignation_type')->nullable()->after('reason');
            $table->string('notice_period')->nullable()->after('last_working_day');
            $table->enum('approval_mode', ['sync', 'async'])->default('async')->after('notice_period');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropColumn(['resignation_type', 'notice_period', 'approval_mode']);
        });
    }
};
