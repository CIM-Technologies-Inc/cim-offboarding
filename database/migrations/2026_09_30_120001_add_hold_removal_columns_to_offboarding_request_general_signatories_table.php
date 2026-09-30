<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * General Signatory equivalent of the same columns just added to
 * `offboarding_request_approvers` — see that migration's own docblock.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->dateTime('on_hold_removed_at')->nullable()->after('decline_reason');
            // Explicit short constraint name — the auto-generated one
            // (`offboarding_request_general_signatories_on_hold_removed_by_foreign`)
            // is 66 characters, over MySQL's 64-character identifier limit.
            $table->foreignId('on_hold_removed_by')->nullable()->after('on_hold_removed_at')
                ->constrained('users', indexName: 'ob_request_gs_on_hold_removed_by_fk')->nullOnDelete();
            $table->text('on_hold_removal_reason')->nullable()->after('on_hold_removed_by');
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_request_general_signatories', function (Blueprint $table) {
            $table->dropForeign('ob_request_gs_on_hold_removed_by_fk');
            $table->dropColumn(['on_hold_removed_at', 'on_hold_removed_by', 'on_hold_removal_reason']);
        });
    }
};
