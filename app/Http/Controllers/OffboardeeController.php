<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\SeparationType;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OffboardeeController extends Controller
{
    // 'cancelled' deliberately included — user-facing label "Retracted"
    // (see the blade's own $statusLabels/$statusLabelsForFilter), stored
    // status value unchanged (see `OffboardingRequest::isReadOnly()`'s own
    // docblock for why no new DB enum value was introduced).
    private const STATUSES = ['pending', 'in_progress', 'overdue', 'completed', 'cancelled'];

    /**
     * Thin null-safe wrapper around `OffboardingRequest::extendDueApplicableApprovers()`
     * for a request that may not exist at all — see that method's own
     * docblock for what "applicable" means (it already returns empty for a
     * retracted/read-only request, so no extra check is needed here). Kept
     * here (rather than inlining `?->extendDueApplicableApprovers()`
     * everywhere below) purely so every `extendDue*` field reads
     * identically.
     */
    private function extendDueApplicableApprovers(?OffboardingRequest $offboardingRequest)
    {
        return $offboardingRequest?->extendDueApplicableApprovers();
    }

    public function index(Request $request): View
    {
        $statusFilter = in_array($request->query('status'), self::STATUSES, true)
            ? $request->query('status')
            : null;

        $departmentFilter = $request->query('department') ?: null;

        // Sort By — Created Date/Time only (never name/Last Working Day/any
        // other field), defaulting to newest-first. An invalid/missing
        // query value silently falls back to the default rather than
        // erroring, same convention as `$statusFilter` above.
        $sortFilter = $request->query('sort') === 'oldest' ? 'oldest' : 'newest';

        $openOffboardeeId = $request->query('offboardee') ? (int) $request->query('offboardee') : null;

        $eagerLoad = ['checklistTemplates', 'approvers.checklistTemplate', 'approvers.employee', 'approvers.itemProgress', 'generalSignatoryApprovals', 'immediateHead', 'finalApproval.employee', 'cancelledBy'];

        // `offboarding` (still in progress) and `offboarded` (fully
        // completed — see `ChecklistCompletionService::checkFinalPayCompletion()`)
        // both belong here: this is every employee with a CURRENT
        // offboarding case, regardless of how far along it is.
        $activeEmployees = Employee::whereIn('status', ['offboarding', 'offboarded'])
            ->with(collect($eagerLoad)->map(fn ($relation) => 'latestOffboardingRequest.' . $relation)->all())
            ->get();

        // A retracted request is deliberately kept forever as a read-only
        // historical record (see `OffboardingRequestController::cancel()`'s
        // own docblock) rather than disappearing once its employee reverts
        // to `active` — so it's fetched here as its OWN source, additive to
        // the active-employee query above, rather than folded into
        // `latestOffboardingRequest` (which only ever resolves to the
        // newest request per employee and would hide an older retracted one
        // the moment a fresh request is created for the same employee).
        $retractedRequests = OffboardingRequest::where('status', 'cancelled')
            ->with(array_merge(['employee'], $eagerLoad))
            ->get();

        // Every card this page can show, paired as [employee, request] —
        // unified here so both sources sort/filter/map through the exact
        // same code below instead of duplicating it. An employee can
        // legitimately appear twice (one retracted historical pair, one
        // active pair) once they've been offboarded more than once.
        $pairs = $activeEmployees
            ->map(fn (Employee $employee) => ['employee' => $employee, 'request' => $employee->latestOffboardingRequest])
            ->concat($retractedRequests->map(fn (OffboardingRequest $offboardingRequest) => ['employee' => $offboardingRequest->employee, 'request' => $offboardingRequest]));

        // Sorted by the offboarding REQUEST's own `created_at` — never
        // alphabetically by name/employee number/Last Working Day/any other
        // field — direction controlled by the "Sort By" control
        // ($sortFilter above), newest-first by default. Applies uniformly
        // to every card regardless of status (active, completed, or
        // retracted), since it's the same single `sortBy*` call over the
        // whole unified `$pairs` collection built above.
        $pairs = ($sortFilter === 'oldest' ? $pairs->sortBy(fn (array $pair) => $pair['request']?->created_at) : $pairs->sortByDesc(fn (array $pair) => $pair['request']?->created_at))
            ->values();

        $departments = $pairs->pluck('employee.department')->unique()->sort()->values();

        if ($statusFilter) {
            $pairs = $pairs->filter(
                fn (array $pair) => ($pair['request']?->displayStatus() ?? 'pending') === $statusFilter
            );
        }

        if ($departmentFilter) {
            $pairs = $pairs->filter(
                fn (array $pair) => $pair['employee']->department === $departmentFilter
            );
        }

        $mapRequest = function (Employee $employee, ?OffboardingRequest $offboardingRequest) {
            return [
            'id' => $offboardingRequest?->id,
            // Kept alongside the request-scoped `id` above (which is what
            // this page now keys cards/deep-links by, since one employee
            // can have multiple cards) for anything that still needs the
            // employee identity specifically.
            'employeeId' => $employee->id,
            'name' => $employee->name,
            'employeeCode' => $employee->employee_code,
            'department' => $employee->department,
            'designation' => $employee->designation,
            'status' => $offboardingRequest?->displayStatus() ?? 'pending',
            'lastWorkingDay' => $offboardingRequest?->last_working_day?->format('M d, Y'),
            // Original + extended Last Working Day display — `lastWorkingDay`
            // above stays the CURRENT effective value (what Extend Due
            // itself reads/recalculates from); these two let the Offboardee
            // Status modal show the immutable original date alongside the
            // latest extension instead of silently overwriting it. See
            // `OffboardingRequest::isLastWorkingDayExtended()`.
            'originalLastWorkingDay' => $offboardingRequest?->original_last_working_day?->format('M d, Y'),
            'isLastWorkingDayExtended' => $offboardingRequest?->isLastWorkingDayExtended() ?? false,
            'immediateHead' => $offboardingRequest?->immediateHead?->name,
            // Separation Type + Notice Period feature — SAVED/frozen values
            // only, never re-resolved from live Separation Type Management
            // config (see `OffboardingRequestController::store()`), so this
            // matches whatever `noticePeriodStatus()` and the Calendar page
            // show for the exact same request, and never changes just
            // because a type was edited/deleted afterward.
            'separationType' => $offboardingRequest?->reason,
            'separationTypeDescription' => $offboardingRequest?->separation_type_description,
            'noticePeriodDays' => $offboardingRequest?->notice_period_days,
            'notificationDate' => $offboardingRequest?->notification_date?->format('M d, Y'),
            'noticePeriodStatus' => $offboardingRequest?->noticePeriodStatus(),
            'checklistTemplates' => $offboardingRequest?->checklistTemplates->pluck('title')->all() ?? [],
            'timeline' => $offboardingRequest?->approverActivityTimeline() ?? [],
            // URLs are generated whenever a request exists, regardless of its
            // status — admins see the Clearance buttons unconditionally (see
            // the status-timeline-modal component), while everyone else stays
            // gated to a completed request by that same component's
            // `x-if`. `ClearanceFormController` still independently enforces
            // "completed only" server-side, so a non-admin can never actually
            // generate the document early even if this URL were exposed to them.
            'clearanceFormUrl' => $offboardingRequest
                ? route('clearance-form.pdf', $offboardingRequest)
                : null,
            'printClearanceFormUrl' => $offboardingRequest
                ? route('clearance-form.print', $offboardingRequest)
                : null,
            // Same "generate the URL unconditionally, gate the button in the
            // view" convention as the Clearance Form URLs above — the Reset
            // Offboarding button itself is only ever rendered for a user
            // with the `offboarding-requests.reset` permission (see the
            // card partial), and the route independently re-enforces that
            // same permission server-side, so exposing this URL to everyone
            // here is never itself a privilege escalation.
            'resetOffboardingUrl' => $offboardingRequest
                ? route('offboarding-requests.reset', $offboardingRequest)
                : null,
            // Gates the Reset Offboarding button's visibility (on top of the
            // `offboarding-requests.reset` permission check in the view) —
            // deliberately based on actual checklist/task/General Signatory
            // records via `hasApprovedOrCompletedProgress()`, never on the
            // request's overall display status, so a request nobody has
            // acted on yet never shows a destructive reset action with
            // nothing real to reset.
            'hasOffboardingProgress' => $offboardingRequest?->hasApprovedOrCompletedProgress() ?? false,
            // Cancel Offboarding — same "generate the URL unconditionally,
            // gate the button in the view" convention as the Reset URL
            // above. The button itself is only ever rendered for a request
            // whose displayed status is neither 'cancelled' (Retracted) nor
            // 'completed' (see the card partial), gated by the
            // `offboarding-requests.cancel` permission; the route
            // independently re-enforces both server-side.
            'cancelOffboardingUrl' => $offboardingRequest
                ? route('offboarding-requests.cancel', $offboardingRequest)
                : null,
            // Retraction history — read-only, shown only for a retracted
            // (`status === 'cancelled'`) request, in the space the Retract/
            // Extend buttons would otherwise occupy (see the card partial).
            // Frozen at the moment of retraction (`OffboardingRequestController::cancel()`
            // sets `cancelled_by`/`cancellation_reason` once, there is no
            // update path for either afterward), so this always reflects
            // who ACTUALLY performed the retraction, never the current
            // viewer.
            'cancelledByName' => $offboardingRequest?->cancelledBy?->name,
            'cancellationReason' => $offboardingRequest?->cancellation_reason,
            'cancelledAt' => $offboardingRequest?->cancelled_at?->format('M d, Y g:i A'),
            // Gates "Retract Offboarding" the same "generate unconditionally,
            // gate in the view" way as every other action URL here — once
            // the offboardee's Last Working Day is reached (today or any day
            // after), retraction is no longer offered, regardless of the
            // request's status. See `OffboardingRequest::isBeforeLastWorkingDay()`.
            // Already correctly false for a retracted request too, since
            // `isBeforeLastWorkingDay()` doesn't need `isReadOnly()`
            // awareness of its own — the card partial's own
            // `status !== 'cancelled'` check (see the blade) is the
            // authoritative gate for that case.
            'canRetractOffboarding' => $offboardingRequest?->isBeforeLastWorkingDay() ?? false,
            // Extend Due (bulk, the ONLY Extend Due entry point — there is
            // no more per-checklist button) — same "generate the URL
            // unconditionally, gate the button in the view" convention as
            // the other action URLs above. `canBulkExtendDue` mirrors
            // `ApprovalController::extendAllDue()`'s own `abort_unless`
            // check exactly: AT LEAST ONE still-applicable checklist (not
            // yet approved/declined, AND actually carrying a due date — a
            // template with no `due_in_days` configured never gets a
            // `due_at` and is excluded rather than counting toward this at
            // all) must exist. The HR/Admin can extend the Last Working Day
            // at any time — this no longer requires any checklist to have
            // actually REACHED its due date first (`canExtendDue()` is still
            // used, unchanged, purely for the informational "Due"/"Not Due"
            // badge on each checklist in the Extend Due modal below — see
            // `extendDueChecklists`).
            'canBulkExtendDue' => (function () use ($offboardingRequest) {
                $applicable = $this->extendDueApplicableApprovers($offboardingRequest);

                return $applicable !== null && $applicable->isNotEmpty();
            })(),
            // One calendar day AFTER the offboardee's CURRENT Last Working
            // Day — "Extend Due" now extends the Last Working Day itself
            // (every applicable checklist's due date is then recalculated
            // from it using its own template's `due_in_days` offset — see
            // `ApprovalController::extendAllDue()`), so the picker's floor
            // is the current Last Working Day, not any one checklist's own
            // due date. Flatpickr's `minDate` is inclusive of the given
            // day, so this is what actually makes the current Last Working
            // Day itself unselectable in the calendar UI (not just rejected
            // after the fact) — the server independently re-checks the same
            // constraint.
            'extendDueMinSelectableDateIso' => $offboardingRequest?->last_working_day
                ?->copy()->addDay()->format('Y-m-d'),
            // Per-checklist breakdown for the Extend Due modal — every
            // applicable checklist (same set `canBulkExtendDue` above uses)
            // with its OWN due date and whether it specifically has reached
            // it, so the modal can show WHICH checklist(s) triggered the
            // button rather than only a single combined date (a request can
            // easily have some checklists still not due yet alongside
            // others already overdue — one summary value can't distinguish
            // that, and showing "Last Working Day" next to it isn't enough
            // either, since a checklist's own due date is Last Working Day
            // + its template's `due_in_days` offset and routinely differs
            // from it).
            'extendDueChecklists' => (function () use ($offboardingRequest) {
                $applicable = $this->extendDueApplicableApprovers($offboardingRequest);

                return $applicable
                    ?->map(fn ($approver) => [
                        'title' => $approver->checklistTemplate?->title,
                        'dueDate' => $approver->due_at?->format('M d, Y'),
                        'hasReachedDueDate' => $approver->canExtendDue(),
                    ])
                    ->values()
                    ->all() ?? [];
            })(),
            'extendAllDueUrl' => $offboardingRequest
                ? route('offboarding-requests.extend-all-due', $offboardingRequest)
                : null,
            // Final Approval — the button itself is only ever rendered for
            // a request whose real `status` column is 'completed' (see the
            // card partial), gated by the `final-approval.send` permission;
            // the route independently re-enforces both server-side. A
            // retracted request can never reach 'completed' (`cancel()`
            // rejects a request that's already completed, and completing
            // one that's already cancelled is equally impossible — single
            // mutually-exclusive `status` column), so this is already
            // correctly unavailable for a retracted card too.
            'finalApprovalUrl' => $offboardingRequest
                ? route('final-approval.send', $offboardingRequest)
                : null,
            // null (never sent) | 'pending' (sent, awaiting the Final
            // Signatory) | 'approved' — the card uses this to switch
            // between showing the button and a plain "Approved" badge.
            'finalApprovalStatus' => $offboardingRequest?->finalApproval?->status,
            'finalSignatoryName' => $offboardingRequest?->finalApproval?->employee?->name,
            ];
        };

        $offboardees = $pairs->map(fn (array $pair) => $mapRequest($pair['employee'], $pair['request']))->values();

        // The Offboardee page's `?offboardee=` query param (used by every
        // notification/email deep-link — see the 7 call sites updated
        // alongside this change) now identifies an OffboardingRequest, not
        // an Employee, since one employee can have multiple cards. A
        // request that's fallen off the filtered/sorted `$offboardees`
        // list above (e.g. a status/department filter is active) is still
        // fetched separately here so the deep link still opens it.
        $deepLinkOffboardee = null;

        if ($openOffboardeeId) {
            $deepLinkOffboardee = $offboardees->firstWhere('id', $openOffboardeeId);

            if (! $deepLinkOffboardee) {
                $targetRequest = OffboardingRequest::with(array_merge(['employee'], $eagerLoad))->find($openOffboardeeId);
                $deepLinkOffboardee = $targetRequest ? $mapRequest($targetRequest->employee, $targetRequest) : null;
            }
        }

        $employeesNotOffboarded = Employee::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'department', 'designation', 'sup_one', 'head_employee_id']);

        // For the New Offboarding Request modal's per-request email
        // template overrides — every function's Select is populated from
        // this same active list, matching the `is_active` scope every
        // fixed-name lookup in `ChecklistApprovalNotifier` already uses.
        $activeEmailTemplates = EmailTemplate::where('is_active', true)
            ->orderBy('template_name')
            ->get(['id', 'template_name']);

        // For the New Offboarding Request modal's "Separation Type" picker
        // — see `SeparationTypeController`/`OffboardingRequestController::store()`.
        $separationTypes = SeparationType::orderBy('title')->get();

        return view('pages.offboardees.index', [
            'title' => 'Offboardees',
            'offboardees' => $offboardees,
            'statusFilter' => $statusFilter,
            'departmentFilter' => $departmentFilter,
            'sortFilter' => $sortFilter,
            'departments' => $departments,
            'deepLinkOffboardee' => $deepLinkOffboardee,
            'employeesNotOffboarded' => $employeesNotOffboarded,
            'activeEmailTemplates' => $activeEmailTemplates,
            'separationTypes' => $separationTypes,
        ]);
    }
}
