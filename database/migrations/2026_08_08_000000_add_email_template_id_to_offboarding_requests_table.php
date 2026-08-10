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
            $table->foreignId('email_template_id')->nullable()->after('approval_mode')
                ->constrained('email_templates')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('email_template_id');
        });
    }
};
