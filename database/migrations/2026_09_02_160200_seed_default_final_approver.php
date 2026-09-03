<?php

use App\Models\Employee;
use App\Models\FinalApprover;
use Illuminate\Database\Migrations\Migration;

/**
 * Seeds the default Final Approver — Victoriano T. Yap, the Clearance
 * Form's previous hardcoded final signatory — as the initial active row,
 * matched against the already-existing Employee record (EMP89002) rather
 * than creating a duplicate. His `designation` is backfilled to "President"
 * only if currently blank, so the Clearance Form's printed title matches
 * exactly what it always hardcoded, with no other Employee fields touched.
 *
 * A no-op (never fails the migration) if this specific employee record
 * doesn't exist in a given environment — an admin can always set a Final
 * Approver manually from the Offboarding Checklist page afterward.
 */
return new class extends Migration
{
    private const EMPLOYEE_CODE = 'EMP89002';

    public function up(): void
    {
        $employee = Employee::where('employee_code', self::EMPLOYEE_CODE)->first();

        if (! $employee) {
            return;
        }

        if (blank($employee->designation)) {
            $employee->update(['designation' => 'President']);
        }

        FinalApprover::firstOrCreate(
            ['employee_id' => $employee->id],
            ['is_active' => true]
        );
    }

    public function down(): void
    {
        $employee = Employee::where('employee_code', self::EMPLOYEE_CODE)->first();

        if ($employee) {
            FinalApprover::where('employee_id', $employee->id)->delete();
        }
    }
};
