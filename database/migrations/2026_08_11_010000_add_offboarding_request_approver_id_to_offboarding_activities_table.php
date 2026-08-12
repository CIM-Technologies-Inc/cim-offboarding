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
        Schema::table('offboarding_activities', function (Blueprint $table) {
            $table->foreignId('offboarding_request_approver_id')->nullable()->after('user_id')
                ->constrained()->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offboarding_activities', function (Blueprint $table) {
            $table->dropConstrainedForeignId('offboarding_request_approver_id');
        });
    }
};
