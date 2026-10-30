<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Central email notification audit history (Logs module → Email
 * Notifications). One row per send ATTEMPT, written by
 * `EmailLogRecorder` from Laravel's own mail events/transport, so every
 * delivery path is covered (immediate, queued, scheduled). Retries of the
 * same notification share `message_key`, carry an increasing `attempt`, and
 * point back to the first attempt via `retry_of_id`. Email bodies are never
 * stored (they can contain temporary credentials / login links).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('message_key')->index();
            $table->unsignedSmallInteger('attempt')->default(1);
            $table->foreignId('retry_of_id')->nullable()->constrained('email_logs', 'id', 'email_logs_retry_of_fk')->nullOnDelete();

            $table->string('notification_type', 20)->index(); // system | scheduled
            $table->string('status', 20)->index();            // sending | sent | failed

            $table->string('subject', 500)->nullable();
            $table->string('template_name')->nullable();
            $table->string('mailable', 150)->nullable();

            $table->json('to_recipients')->nullable();
            $table->json('cc_recipients')->nullable();
            $table->json('bcc_recipients')->nullable();
            // Every To/Cc/Bcc address in one plain column, for the recipient filter.
            $table->text('recipients_search')->nullable();
            $table->string('redirected_to')->nullable();

            $table->string('trigger')->nullable();
            $table->string('trigger_source', 30)->nullable(); // web | console | queue
            $table->string('delivery', 20)->nullable();       // immediate | queued
            $table->string('schedule_name')->nullable();
            $table->date('scheduled_for')->nullable();

            $table->foreignId('offboarding_request_id')->nullable()->constrained('offboarding_requests', 'id', 'email_logs_request_fk')->nullOnDelete();
            $table->string('offboardee_name')->nullable();
            $table->foreignId('triggered_by_user_id')->nullable()->constrained('users', 'id', 'email_logs_user_fk')->nullOnDelete();
            $table->string('triggered_by_name')->nullable();

            $table->string('smtp_message_id')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('sent_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->timestamps();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_logs');
    }
};
