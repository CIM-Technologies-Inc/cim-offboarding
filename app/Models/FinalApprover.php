<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The configurable final signatory on the Clearance Form ("Approved for
 * Payment by:") — replaces what used to be the hardcoded name/title
 * "VICTORIANO T. YAP" / "President" in `clearance-form/_content.blade.php`.
 *
 * Unlike `GeneralSignatory` (a LIST — several can be active at once),
 * this is meant to have exactly one active row at a time: it represents
 * a single org-wide role (e.g. the President) rather than a set of
 * per-department clearance requirements. `FinalApproverController`
 * enforces that invariant (activating one deactivates every other row),
 * so this table doubles as a history of every employee ever set as Final
 * Approver, each with its own Active/Inactive status for the admin's
 * table view — never edited in place, only superseded by a new row or
 * reactivated.
 *
 * `ClearanceFormController::buildData()` always queries `where('is_active',
 * true)` fresh on every render — deliberately, by product decision, so
 * activating a different row here takes effect immediately on EVERY
 * offboarding request's Clearance Form, not just ones created afterward.
 * `OffboardingRequest.final_approver_employee_id` snapshots which employee
 * was active at a given request's creation/reset time purely as a
 * historical record; it is never read when rendering the form.
 */
class FinalApprover extends Model
{
    protected $fillable = [
        'employee_id',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
        ];
    }

    public function employee(): BelongsTo
    {
        return $this->belongsTo(Employee::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
