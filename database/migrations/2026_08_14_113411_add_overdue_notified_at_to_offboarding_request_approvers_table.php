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
            $table->timestamp('overdue_notified_at')->nullable()->after('ready_for_approval_notified_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('offboarding_request_approvers', function (Blueprint $table) {
            $table->dropColumn('overdue_notified_at');
        });
    }
};
