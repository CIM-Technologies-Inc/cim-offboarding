<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * No activity in this app has ever captured IP/User-Agent before — added
 * here as generic, nullable columns (not decline-specific) so any future
 * activity can populate them too. Currently only the checklist/General
 * Signatory decline actions populate these, since declining is the first
 * action in this app with an explicit audit-trail requirement for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_activities', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('comment');
            $table->string('user_agent', 512)->nullable()->after('ip_address');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_activities', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
    }
};
