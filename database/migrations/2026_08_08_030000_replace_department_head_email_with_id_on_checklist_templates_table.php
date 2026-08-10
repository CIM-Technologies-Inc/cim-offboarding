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
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropColumn('department_head_email');
            $table->foreignId('department_head_id')->nullable()->after('title')
                ->constrained('employees')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropConstrainedForeignId('department_head_id');
            $table->string('department_head_email')->nullable()->after('title');
        });
    }
};
