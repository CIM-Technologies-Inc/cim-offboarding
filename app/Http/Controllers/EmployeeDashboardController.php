<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItem;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Services\ChecklistFollowUpService;
use Illuminate\View\View;

class EmployeeDashboardController extends Controller
{
    public function __construct(private ChecklistFollowUpService $followUpService)
    {
    }

    /**
     * The offboardee's own self-service dashboard — always scoped to
     * `auth()->user()->employee`'s own offboarding request, never a route
     * parameter, so there is no id to tamper with and no way for one
     * employee to ever reach another's data through this page.
     */
    public function index(): View
    {
        $employee = auth()->user()->employee;

        abort_if(! $employee, 404, 'No employee record is linked to this account.');

        $offboardingRequest = OffboardingRequest::where('employee_id', $employee->id)
            ->with([
                'approvers.checklistTemplate.items.signatory',
                'approvers.itemProgress.checkedBy',
                'approvers.itemAssignments.assignedEmployee',
                'approvers.employee',
                // `.generalSignatory.clearanceSignatory`/`.tasks` added so
                // the Checklist Status section below can show each General
                // Signatory's name and whether they have a Task List
                // without an N+1 query per row.
                'generalSignatoryApprovals.generalSignatory.clearanceSignatory',
                'generalSignatoryApprovals.generalSignatory.tasks',
                // Needed by `buildStages()`'s own "Clearance" stage — see
                // its docblock for why the Final Approver's own sign-off
                // (not merely the `status` column) is what that stage
                // actually gates on.
                'finalApproval',
                'activities',
            ])
            ->latest()
            ->first();

        if (! $offboardingRequest) {
            return view('pages.employee.dashboard', [
                'title' => 'My Dashboard',
                'offboardingRequest' => null,
            ]);
        }

        $approvers = $offboardingRequest->approvers;
        $totalChecklists = $approvers->count();
        $approvedChecklists = $approvers->where('status', 'approved')->count();

        // The Follow Up button's own two gates (daily-per-checklist
        // cooldown, and the request's shared attempt budget) are enforced
        // authoritatively — again, under a lock — by
        // `ChecklistFollowUpService::send()` when the button is actually
        // clicked; what's built here is purely for what the button should
        // look like on this page load.
        $hasRecipient = (bool) $offboardingRequest->creator;
        $requestIsActive = in_array($offboardingRequest->status, ['pending', 'in_progress'], true);

        $checklists = $approvers->map(function (OffboardingRequestApprover $approver) use ($employee, $hasRecipient, $requestIsActive) {
            $items = $approver->checklistTemplate?->items ?? collect();
            $progressByItemId = $approver->itemProgress->keyBy('checklist_item_id');
            $completedItems = $items->filter(fn (ChecklistItem $item) => (bool) ($progressByItemId->get($item->id)?->is_checked ?? false));
            $statusLabel = $approver->clearanceStatusLabel();

            $isFollowUpEligible = $requestIsActive
                && $hasRecipient
                && ! in_array($statusLabel, ['Cleared', 'Declined'], true);

            $followUpState = $isFollowUpEligible ? $this->followUpService->stateFor($approver, $employee) : null;

            return [
                'assignmentId' => $approver->id,
                'title' => $approver->checklistTemplate?->title ?? 'Untitled Checklist',
                'department' => $approver->department(),
                'approverName' => $approver->employee?->name,
                'approverCode' => $approver->employee?->employee_code,
                'items' => $items->map(function (ChecklistItem $item) use ($approver, $progressByItemId) {
                    $progress = $progressByItemId->get($item->id);
                    $signatory = $approver->effectiveSignatoryFor($item);

                    return [
                        'title' => $item->title,
                        'signatoryName' => $signatory?->name,
                        'checked' => (bool) ($progress?->is_checked ?? false),
                        'onHold' => $progress?->status === 'hold' && ! ($progress?->is_checked ?? false),
                    ];
                })->values()->all(),
                'completedItemsCount' => $completedItems->count(),
                'totalItemsCount' => $items->count(),
                'dueAt' => $approver->due_at?->format('M d, Y'),
                'isOverdue' => $approver->isOverdue(),
                'status' => $statusLabel,
                'completedAt' => $approver->approved_at?->format('M d, Y'),
                'followUpEligible' => $isFollowUpEligible,
                'followUpUrl' => $isFollowUpEligible ? route('employee.follow-up', $approver->id) : null,
                'followUpCanSendNow' => $followUpState['canSendNow'] ?? false,
                'followUpLastSentAt' => ($followUpState['lastSentAt'] ?? null)?->format('M d, Y g:i A'),
                'followUpNextAllowedAt' => ($followUpState['nextAllowedAt'] ?? null)?->format('M d, Y g:i A'),
                'followUpAttemptsUsed' => $followUpState['attemptsUsed'] ?? 0,
                'followUpMaxAttempts' => $followUpState['maxAttempts'] ?? (int) config('offboarding.max_follow_up_attempts'),
                'followUpMaxReached' => $followUpState['maxReached'] ?? false,
            ];
        })->values();

        // The General Signatory equivalent of `$checklists` above — same
        // reused `OffboardingRequestGeneralSignatory::clearanceStatusLabel()`
        // every other General Signatory display (Approvals page, Clearance
        // Form) already calls, so this can never drift into its own,
        // separately-computed notion of "approved"/"on hold"/etc. Purely
        // additive visibility for the offboardee — nothing here reads from
        // or writes to the approval workflow itself.
        $generalSignatories = $offboardingRequest->generalSignatoryApprovals->map(function ($approval) {
            $clearanceSignatory = $approval->generalSignatory?->clearanceSignatory;
            $hasTasks = $approval->generalSignatory?->tasks->isNotEmpty() ?? false;
            $statusLabel = $approval->clearanceStatusLabel();

            // A still-outstanding ("Pending") signatory with no Task List
            // at all has nothing to wait on — nobody else needs to finish
            // anything before they can act — so it reads more accurately
            // to the offboardee as "Ready for Approval" instead of a plain
            // "Pending" that implies something else is still in progress.
            // Every other status ('Cleared'/'Declined'/'On Hold') is left
            // exactly as `clearanceStatusLabel()` already returns it —
            // this only ever relabels the one 'Pending' case.
            if ($statusLabel === 'Pending' && ! $hasTasks) {
                $statusLabel = 'Ready for Approval';
            }

            return [
                'name' => $clearanceSignatory?->name ?? 'Unassigned',
                'status' => $statusLabel,
                'dueAt' => $approval->due_at?->format('M d, Y'),
                'isOverdue' => $approval->isClearanceSigningOverdue(),
                'approvedAt' => $approval->approved_at?->format('M d, Y'),
            ];
        })->values();

        return view('pages.employee.dashboard', [
            'title' => 'My Dashboard',
            'offboardingRequest' => $offboardingRequest,
            'overview' => [
                'name' => $employee->name,
                'employeeCode' => $employee->employee_code,
                'position' => $employee->designation,
                'department' => $employee->department,
                'requestDate' => $offboardingRequest->created_at->format('M d, Y'),
                'lastWorkingDay' => $offboardingRequest->last_working_day->format('M d, Y'),
                'status' => ucfirst(str_replace('_', ' ', $offboardingRequest->displayStatus())),
                'progressPercent' => $totalChecklists > 0 ? (int) round($approvedChecklists / $totalChecklists * 100) : 0,
            ],
            'stages' => $this->buildStages($offboardingRequest),
            'checklists' => $checklists,
            'generalSignatories' => $generalSignatories,
            'timeline' => $offboardingRequest->timeline(),
        ]);
    }

    /**
     * The 6-stage progress stepper — each stage's `done` flag is derived
     * from existing, already-tracked approval-state signals (no new state
     * to maintain): request creation is always true by the time this page
     * exists; "assigned" once at least one checklist is attached; "in
     * progress" reuses the existing `hasApproverActivity()` helper.
     *
     * "Checklists Completed", "Clearance", and "Offboarding Completed" were
     * previously wrong in a way that let Clearance (and therefore
     * Completed) show as done while Checklists Completed was still not —
     * `clearance_generated` is logged by `ClearanceFormController` the
     * FIRST TIME ANYONE VIEWS the Clearance Form PDF (a read-only,
     * no-approval-implied action — see that controller's own guarded
     * `activities()->create()` call), not when every required signature
     * actually lands on it. Simply opening/printing the form early — which
     * nothing prevents — permanently flipped this stage to "done" from
     * then on, regardless of actual approval state. Replaced below with
     * the real per-category completion signals instead:
     *
     * - "Checklists Completed": every checklist currently attached to this
     *   request (Clearance Signatory checklists AND Immediate Head
     *   checklists alike — both are just `OffboardingRequestApprover` rows,
     *   see `ChecklistTemplate.is_immediate_head_checklist`) is
     *   `status === 'approved'`. Deliberately excludes General Signatories
     *   — those are a separate, independent approval track (see
     *   `OffboardingRequestGeneralSignatory`'s own docblock) validated by
     *   the next stage instead, matching this stepper's own "Checklists"
     *   vs. "Clearance" label distinction. Note this can go from done back
     *   to not-done if a Final Pay checklist is attached afterward (see
     *   `ChecklistCompletionService::checkRegularChecklistsCompletion()`)
     *   — correct, not a bug: a newly-required checklist genuinely isn't
     *   approved yet.
     * - "Clearance": every OTHER signature the Clearance Form requires
     *   beyond the checklists above — every attached General Signatory
     *   approved (vacuously true if none are attached, same convention
     *   `ChecklistCompletionService::allRegularGeneralSignatoriesApproved()`
     *   already uses), AND the Final Approver has actually signed. Checking
     *   `$offboardingRequest->finalApproval?->status === 'approved'` rather
     *   than the request's own `status` column is deliberate: `status`
     *   flips to `'completed'` the moment checklists+General Signatories
     *   finish (see `ChecklistCompletionService::checkFinalPayCompletion()`)
     *   — which is also the EARLIEST moment `FinalApprovalController::send()`
     *   is even allowed to initiate the Final Approval process, meaning the
     *   Final Approver has almost always NOT signed yet at that exact
     *   instant. Reusing `status` here would reintroduce the exact same
     *   "marked done too early" bug this fix exists to remove.
     * - "Offboarding Completed": mirrors Clearance above rather than
     *   re-reading `status` independently — by this app's own definition
     *   there is no further distinct backend milestone beyond every
     *   required Clearance Form signature being attached, and re-deriving
     *   it from `status` alone would let this stage (wrongly) show done
     *   before Clearance again, for the same reason described above.
     */
    private function buildStages(OffboardingRequest $offboardingRequest): array
    {
        $hasApprovers = $offboardingRequest->approvers->isNotEmpty();

        $allChecklistsApproved = $hasApprovers
            && $offboardingRequest->approvers->every(fn (OffboardingRequestApprover $approver) => $approver->status === 'approved');

        $allGeneralSignatoriesApproved = $offboardingRequest->generalSignatoryApprovals->isEmpty()
            || $offboardingRequest->generalSignatoryApprovals->every(fn ($approval) => $approval->status === 'approved');

        $finalApproverSigned = $offboardingRequest->finalApproval?->status === 'approved';

        $hasClearance = $allChecklistsApproved && $allGeneralSignatoriesApproved && $finalApproverSigned;

        return [
            ['label' => 'Offboarding Request Created', 'done' => true],
            ['label' => 'Checklists Assigned', 'done' => $hasApprovers],
            ['label' => 'Checklists In Progress', 'done' => $offboardingRequest->hasApproverActivity()],
            ['label' => 'Checklists Completed', 'done' => $allChecklistsApproved],
            ['label' => 'Clearance', 'done' => $hasClearance],
            ['label' => 'Offboarding Completed', 'done' => $hasClearance],
        ];
    }
}
