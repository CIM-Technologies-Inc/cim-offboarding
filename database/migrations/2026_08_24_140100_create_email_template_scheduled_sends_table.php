<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('email_template_scheduled_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_template_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offboarding_request_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['email_template_id', 'offboarding_request_id'], 'email_template_sched_sends_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('email_template_scheduled_sends');
    }
};
