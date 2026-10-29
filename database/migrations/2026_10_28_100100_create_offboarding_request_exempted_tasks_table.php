<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The per-request snapshot of which checklist tasks were exempted when the
 * request was submitted — one row per exempted task (however many rules
 * matched it; `matched_rules` lists them all), with the checklist/task
 * titles and each rule's name/reason copied in so editing, deactivating,
 * or deleting a rule, or renaming/removing the task itself, never alters
 * what an already-submitted request shows or does.
 *
 * `task_exemption_context` on `offboarding_requests` records exactly which
 * Employee Master values (department/position/employee) the rules were
 * matched against, and when.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('offboarding_request_exempted_tasks', function (Blueprint $table) {
            $table->id();
            // Explicit short FK names — the generated ones exceed MySQL's
            // 64-character identifier limit for this table name.
            $table->foreignId('offboarding_request_id')->constrained('offboarding_requests', 'id', 'orxt_request_fk')->cascadeOnDelete();
            $table->foreignId('checklist_template_id')->nullable()->constrained('checklist_templates', 'id', 'orxt_template_fk')->nullOnDelete();
            $table->foreignId('checklist_item_id')->nullable()->constrained('checklist_items', 'id', 'orxt_item_fk')->nullOnDelete();
            $table->string('checklist_title');
            $table->string('item_title');
            $table->json('matched_rules');
            $table->timestamps();

            $table->unique(['offboarding_request_id', 'checklist_item_id'], 'offboarding_request_exempted_tasks_unique');
        });

        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->json('task_exemption_context')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('offboarding_requests', function (Blueprint $table) {
            $table->dropColumn('task_exemption_context');
        });

        Schema::dropIfExists('offboarding_request_exempted_tasks');
    }
};
