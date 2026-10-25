<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Reverts `2026_10_21_120000_add_is_system_default_to_email_templates_table`
 * — the Customizable/Default System radio feature has been removed from the
 * Email Template form entirely (see that migration's own docblock for the
 * feature this column backed). `EmailTemplate::isSystemDefault()` is back to
 * its original, purely derived form (name-list match or
 * `is_default_reactivation`), so this column has no remaining reader.
 * Every new template is Customizable by default — simply by never having a
 * flag to override that — and every existing System template stays System
 * exactly as before, since that classification was always derived from
 * `template_name`/`is_default_reactivation`, never genuinely dependent on
 * this column (it was consulted first, but every row that ever had it
 * `true` also satisfied one of the other two checks already, via that
 * migration's own backfill).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('is_system_default');
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->boolean('is_system_default')->default(false)->after('is_default_reactivation');
        });
    }
};
