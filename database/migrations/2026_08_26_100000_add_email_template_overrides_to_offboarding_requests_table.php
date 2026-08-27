<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-request overrides for the 3 fixed-name email templates that fire
 * automatically, once each, at request-creation time (see
 * `ChecklistApprovalNotifier::notifyDepartmentHeadsOfNewRequest()`/
 * `notifyOffboardee()`/`notifyGeneralSignatories()`). Nullable — an
 * unselected override falls back to that function's current default
 * template at send time, exactly today's existing behavior. Once set, it's
 * a frozen snapshot: unaffected by later changes to the global default, or
 * to the pointed-at template itself, for this specific request — see the
 * matching change in `ChecklistApprovalNotifier.php`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->foreignId('approver_notification_template_id')->nullable()->after('email_template_id')
                ->constrained('email_templates', indexName: 'ob_requests_approver_notif_tpl_fk')
                ->nullOnDelete();
            $table->foreignId('offboardee_notification_template_id')->nullable()->after('approver_notification_template_id')
                ->constrained('email_templates', indexName: 'ob_requests_offboardee_notif_tpl_fk')
                ->nullOnDelete();
            $table->foreignId('general_signatory_notification_template_id')->nullable()->after('offboardee_notification_template_id')
                ->constrained('email_templates', indexName: 'ob_requests_gensig_notif_tpl_fk')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropForeign('ob_requests_approver_notif_tpl_fk');
            $table->dropForeign('ob_requests_offboardee_notif_tpl_fk');
            $table->dropForeign('ob_requests_gensig_notif_tpl_fk');
            $table->dropColumn([
                'approver_notification_template_id',
                'offboardee_notification_template_id',
                'general_signatory_notification_template_id',
            ]);
        });
    }
};
