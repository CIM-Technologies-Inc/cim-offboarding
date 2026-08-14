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
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->timestamp('ready_for_approval_notified_at')->nullable()->after('delegate_completed_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropColumn('ready_for_approval_notified_at');
        });
    }
};
