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
        Schema::table('email_templates', function (Blueprint $table) {
            $table->boolean('is_scheduled')->default(false)->after('is_default_announcement');
            $table->string('schedule_timing')->nullable()->after('is_scheduled');
            $table->unsignedInteger('schedule_days')->nullable()->after('schedule_timing');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn(['is_scheduled', 'schedule_timing', 'schedule_days']);
        });
    }
};
