<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Permanent, surviving audit facts for "Cancel Offboarding" — who
 * cancelled this request and when — kept directly on the request row
 * itself (which is NEVER deleted by cancellation, only its dependent
 * records are; see `OffboardingRequestController::cancel()`), the same
 * "the row is the permanent record" convention `completed_at` already
 * follows for a successfully finished request.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->timestamp('cancelled_at')->nullable()->after('completed_at');
            $table->foreignId('cancelled_by')->nullable()->after('cancelled_at')
                ->constrained('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropForeign(['cancelled_by']);
            $table->dropColumn(['cancelled_at', 'cancelled_by']);
        });
    }
};
