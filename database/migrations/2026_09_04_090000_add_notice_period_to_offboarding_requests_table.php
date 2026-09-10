<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Notice Period feature: how many days' notice this request was
 * submitted with (admin-entered, defaults to 30 on the New Offboarding
 * Request form — see `new-request-modal.blade.php`), and the Notification
 * Date it produces — the exact submission date/time
 * (`OffboardingRequest::created_at`) plus that many days, computed and
 * frozen ONCE at creation time (`OffboardingRequestController::store()`).
 * Neither value is ever recalculated later: changing the form's default
 * Notice Period afterward must never retroactively change what an
 * already-created request recorded.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->unsignedSmallInteger('notice_period_days')->default(30)->after('reason');
            $table->date('notification_date')->nullable()->after('notice_period_days');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropColumn(['notice_period_days', 'notification_date']);
        });
    }
};
