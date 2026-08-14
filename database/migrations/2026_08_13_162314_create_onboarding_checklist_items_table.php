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
        Schema::create('onboarding_checklist_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('onboarding_checklist_template_id')
                ->constrained('onboarding_checklist_templates', 'id', 'onb_checklist_items_template_id_fk')
                ->cascadeOnDelete();
            $table->string('title');
            $table->foreignId('signatory_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_checklist_items');
    }
};
