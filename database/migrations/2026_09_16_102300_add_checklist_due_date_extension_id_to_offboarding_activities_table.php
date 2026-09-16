<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Extend Due" equivalent of `offboarding_request_final_approval_id` —
 * lets a `due_date_extended` activity say exactly which
 * `checklist_due_date_extensions` row it belongs to, so
 * `OffboardingActivity::label()` can build its Timeline sentence
 * ("...extended from X to Y by Z.") from that row's own structured
 * previous/new due dates instead of re-parsing them out of the activity's
 * free-text `comment`. Explicitly named constraint: the default name would
 * exceed MySQL's 64-char identifier limit, same issue as the General
 * Signatory/Final Approval columns before it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_activities', function (Blueprint $table) {
            $table->foreignId('checklist_due_date_extension_id')->nullable()->after('offboarding_request_final_approval_id');
            $table->foreign('checklist_due_date_extension_id', 'offboarding_activities_due_date_ext_fk')
                ->references('id')->on('checklist_due_date_extensions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_activities', function (Blueprint $table) {
            $table->dropForeign('offboarding_activities_due_date_ext_fk');
            $table->dropColumn('checklist_due_date_extension_id');
        });
    }
};
