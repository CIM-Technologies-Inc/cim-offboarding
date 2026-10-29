<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manually entered recipient email addresses on an email template's
 * Recipients field (alongside the selected employees and Employee Master
 * groups) — company or personal addresses that need not belong to any
 * employee record. Stored lowercased and de-duplicated; CC'd on every send
 * via `EmailTemplate::ccRecipientEmails()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->json('recipient_emails')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('email_templates', function (Blueprint $table) {
            $table->dropColumn('recipient_emails');
        });
    }
};
