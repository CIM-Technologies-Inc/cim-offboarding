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
        Schema::create('onboarding_checklist_templates', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('department_head_id')->nullable()->constrained('employees')->nullOnDelete();
            $table->string('department')->nullable();
            $table->unsignedInteger('due_in_days')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('onboarding_checklist_templates');
    }
};
