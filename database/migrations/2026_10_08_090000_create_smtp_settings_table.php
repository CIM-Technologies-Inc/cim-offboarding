<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A singleton settings table — one row, ever (the spec's own "preferably
 * only one active configuration" is simplest to honor by never allowing
 * more than one row at all, rather than enforcing an invariant across
 * many). `is_active` defaults false so installing this feature never
 * silently switches which mail server the app actually uses — see
 * `MailConfigurator::apply()`, which only overrides `.env` when this row
 * exists AND is active.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('smtp_settings', function (Blueprint $table) {
            $table->id();
            $table->string('mail_driver')->default('smtp');
            $table->string('smtp_host')->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_encryption')->default('tls');
            $table->string('smtp_username')->nullable();
            // 'text', not 'string' — the 'encrypted' Eloquent cast's
            // ciphertext is far longer than the plaintext password it
            // holds.
            $table->text('smtp_password')->nullable();
            $table->boolean('smtp_authentication')->default(true);
            $table->unsignedInteger('smtp_timeout')->nullable();
            $table->string('from_email')->nullable();
            $table->string('from_name')->nullable();
            $table->boolean('is_active')->default(false);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users', indexName: 'smtp_settings_updated_by_fk')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('smtp_settings');
    }
};
