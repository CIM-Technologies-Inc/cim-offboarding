<?php

use App\Models\EmailTemplate;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Promotes "Default System" from a purely derived property (matched against
 * `EmailTemplate::SYSTEM_TEMPLATE_NAMES` by name, or the `is_default_reactivation`
 * flag — see `EmailTemplate::isSystemDefault()`) to a real, admin-settable
 * column — the "Customizable" / "Default System" radio on the Email
 * Template form. Every template whose name was already in that fixed list
 * (or already had `is_default_reactivation = true`) is backfilled to
 * `is_system_default = true` here, so nothing that was already classified
 * as System stops being System after this migration — `isSystemDefault()`
 * keeps checking all three signals (flag, name list, reactivation flag) as
 * a safety net, with the column now the primary, admin-facing one.
 *
 * This column is purely a categorization/protection flag (which tab a
 * template shows under, whether its name can be edited) — it does NOT
 * change which template any of the app's ~15 hardcoded `where('template_name', ...)`
 * lookups actually send, since those each need to identify one SPECIFIC
 * system purpose by name, not just a generic yes/no "is this System".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->boolean('is_system_default')->default(false)->after('is_default_reactivation');
        });

        DB::table('email_templates')
            ->whereIn('template_name', EmailTemplate::SYSTEM_TEMPLATE_NAMES)
            ->orWhere('is_default_reactivation', true)
            ->update(['is_system_default' => true]);
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('is_system_default');
        });
    }
};
