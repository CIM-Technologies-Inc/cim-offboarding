<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The configurable replacement for the Clearance Form's previously
 * hardcoded final signatory ("Victoriano T. Yap", President) — see
 * `App\Models\FinalApprover`. Modeled closely on `general_signatories`
 * (same `employee_id`/`is_active`/`created_by` shape), but semantically a
 * SINGLE current signatory rather than a list: the app enforces "only one
 * active row at a time" in `FinalApproverController`, so this table doubles
 * as a history of every employee ever set as Final Approver, each with its
 * own Active/Inactive status and creation date for the admin's table view.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('final_approvers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('employee_id')->constrained('employees')->restrictOnDelete();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('final_approvers');
    }
};
