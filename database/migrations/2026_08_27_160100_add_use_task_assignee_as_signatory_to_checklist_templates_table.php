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
            $table->boolean('use_task_assignee_as_signatory')->default(false)->after('is_immediate_head_checklist');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('checklist_templates', function (Blueprint $table) {
            $table->dropColumn('use_task_assignee_as_signatory');
        });
    }
};
