<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Admin/HR-provided reason a "Cancel Offboarding" action was taken —
 * required at submission time (see `OffboardingRequestController::cancel()`),
 * kept alongside `cancelled_at`/`cancelled_by` on the request row itself
 * (never deleted by cancellation, only its dependent records are), and
 * never editable afterward — there is no update path for this column once
 * set, since a request can only ever be cancelled once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->text('cancellation_reason')->nullable()->after('cancelled_by');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropColumn('cancellation_reason');
        });
    }
};
