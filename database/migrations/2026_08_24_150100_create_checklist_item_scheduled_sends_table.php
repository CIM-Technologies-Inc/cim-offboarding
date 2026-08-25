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
        Schema::create('checklist_item_scheduled_sends', function (Blueprint $table) {
            $table->id();
            $table->foreignId('checklist_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('offboarding_request_id')->constrained()->cascadeOnDelete();
            $table->timestamp('sent_at');
            $table->timestamps();

            $table->unique(['checklist_item_id', 'offboarding_request_id'], 'checklist_item_sched_sends_unique');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('checklist_item_scheduled_sends');
    }
};
