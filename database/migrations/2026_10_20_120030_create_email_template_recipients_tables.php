<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "Recipients / CC" configuration on an Email Template — individual
 * employees and whole Employee Master groups an admin wants CC'd whenever
 * that template is used to send an email, in addition to whatever the
 * triggering workflow already sends to (see `EmailTemplate::ccRecipientEmails()`).
 * Two plain many-to-many pivots rather than one polymorphic table, matching
 * how the rest of this app models similar "picked from two distinct kinds
 * of thing" relationships.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_template_recipient_employees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['email_template_id', 'employee_id'], 'email_template_recipient_employees_unique');
        });

        Schema::create('email_template_recipient_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('employee_group_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['email_template_id', 'employee_group_id'], 'email_template_recipient_groups_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_template_recipient_groups');
        Schema::dropIfExists('email_template_recipient_employees');
    }
};
