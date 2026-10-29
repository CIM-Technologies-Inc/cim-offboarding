<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Task Exemption rules (System Administration -> Task Exemption) — which
 * checklist tasks (`checklist_items`) to leave out of an offboarding
 * request for offboardees matching a department, position, or specific
 * employee. Departments/positions have no master table of their own in this
 * app (both are free text on `employees`), so those targets store the text
 * value itself; employee targets are a real FK.
 *
 * Rules only ever affect FUTURE requests: what a rule exempted on a given
 * request is frozen into `offboarding_request_exempted_tasks` (see the next
 * migration) at submission time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('task_exemptions', function (Blueprint $table) {
            $table->id();
            $table->string('name')->unique();
            $table->text('reason')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('task_exemption_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_exemption_id')->constrained()->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->string('target_value')->nullable();
            $table->foreignId('employee_id')->nullable()->constrained('employees')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['target_type', 'target_value']);
            $table->unique(['task_exemption_id', 'target_type', 'target_value', 'employee_id'], 'task_exemption_targets_unique');
        });

        Schema::create('task_exemption_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_exemption_id')->constrained()->cascadeOnDelete();
            $table->foreignId('checklist_item_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['task_exemption_id', 'checklist_item_id'], 'task_exemption_items_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('task_exemption_items');
        Schema::dropIfExists('task_exemption_targets');
        Schema::dropIfExists('task_exemptions');
    }
};
