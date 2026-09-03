<?php

namespace App\Http\Controllers;

use App\Models\FinalApprover;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Manages the Final Approver configuration on the Offboarding Checklist
 * page — the configurable replacement for the Clearance Form's previously
 * hardcoded final signatory. See `FinalApprover`'s own docblock for why
 * this enforces "only one active row at a time" (unlike General Signatory,
 * a genuine list): every method here that activates a row deactivates
 * every other one in the same transaction, so `OffboardingRequestController::store()`/
 * `reset()` can always resolve a single, unambiguous active Final Approver
 * to snapshot onto a request.
 */
class FinalApproverController extends Controller
{
    /**
     * "Set Final Approver" — always creates a NEW row rather than editing
     * an existing one in place, so the table doubles as an honest history
     * of every employee who has ever held this role (each row's own
     * `created_at` is its "Date Added"). The new row is always created
     * active, per spec, which — same as `activate()` below — deactivates
     * every other row first so exactly one stays active.
     */
    public function store(Request $request): RedirectResponse|JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
        ]);

        DB::transaction(function () use ($validated, $request) {
            FinalApprover::where('is_active', true)->update(['is_active' => false]);

            FinalApprover::create([
                'employee_id' => $validated['employee_id'],
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);
        });

        return $this->respond($request, 'Final Approver set.');
    }

    /**
     * Activating a currently-inactive row supersedes whichever row is
     * active right now (deactivating it) — the same single-active
     * invariant `store()` enforces, applied to REACTIVATING a past Final
     * Approver instead of setting a brand new one. Deactivating the
     * current active row is also allowed and leaves none active — a valid,
     * if incomplete, state: `OffboardingRequestController::store()`/
     * `reset()` are what actually refuse to proceed without one, not this
     * config page itself.
     */
    public function toggleStatus(FinalApprover $finalApprover): RedirectResponse
    {
        if ($finalApprover->is_active) {
            $finalApprover->update(['is_active' => false]);
        } else {
            DB::transaction(function () use ($finalApprover) {
                FinalApprover::where('is_active', true)->update(['is_active' => false]);
                $finalApprover->update(['is_active' => true]);
            });
        }

        return redirect()->route('checklist-templates.index')
            ->with('success', 'Final Approver marked as ' . ($finalApprover->is_active ? 'active' : 'inactive') . '.');
    }

    /**
     * Deleting a Final Approver config row is always safe for existing
     * offboarding requests — `offboarding_requests.final_approver_employee_id`
     * snapshots the EMPLOYEE directly (see `FinalApprover`'s and
     * `OffboardingRequest::finalApproverEmployee()`'s own docblocks), with
     * no foreign key back to this `final_approvers` row at all, so removing
     * this row can never null out or otherwise affect a request that
     * already used it. Deleting the currently active row is allowed too —
     * it simply leaves no active Final Approver, the same valid-but-
     * incomplete state `toggleStatus()` above can already produce, which
     * `OffboardingRequestController::store()`/`reset()` already refuse to
     * proceed past.
     */
    public function destroy(FinalApprover $finalApprover): RedirectResponse
    {
        $finalApprover->delete();

        return redirect()->route('checklist-templates.index')->with('success', 'Final Approver deleted.');
    }

    /**
     * Same dual-mode response as `GeneralSignatoryController::respond()` —
     * the modal saves via `fetch()` (JSON acknowledgement, so it can close
     * itself and refresh just its own table), while a plain non-JS
     * submission falls back to the normal redirect-with-flash-message.
     */
    private function respond(Request $request, string $message): RedirectResponse|JsonResponse
    {
        if ($request->expectsJson()) {
            return response()->json(['message' => $message]);
        }

        return redirect()->route('checklist-templates.index')->with('success', $message);
    }
}
