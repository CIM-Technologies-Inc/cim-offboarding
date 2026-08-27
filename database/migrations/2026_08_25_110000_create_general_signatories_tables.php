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
        Schema::create('general_signatories', function (Blueprint $table) {
            $table->id();
            $table->foreignId('clearance_signatory_id')->constrained('employees')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('general_signatory_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('general_signatory_id')->constrained()->cascadeOnDelete();
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
        Schema::dropIfExists('general_signatory_tasks');
        Schema::dropIfExists('general_signatories');
    }
};
