<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ActivityLog;
use App\Models\ChecklistItemScheduledSend;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\FinalApprover;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\SeparationType;
use App\Models\User;
use App\Services\ChecklistApprovalNotifier;
use App\Services\ChecklistCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;

class OffboardingRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'immediate_head_id' => ['nullable', 'exists:employees,id', 'different:employee_id'],
            'notice_date' => ['required', 'date'],
            // No `after_or_equal:notice_date` — Last Working Day is picked
            // freely (see new-request-modal.blade.php's own Separation
            // Type/Dates block) and the HR/Admin team may submit even when
            // Resignation Date falls after it; this is intentionally not
            // treated as an error.
            'last_working_day' => ['required', 'date'],
            // Only the Separation Type is trusted from the client — its
            // title, Description/Definition, and Default Notice Period are
            // always looked up server-side from THIS id below, never taken
            // from any other submitted field (the New Offboarding Request
            // modal's own "Notice Period" input is `disabled` and has no
            // `name` at all, precisely so there's nothing else to trust).
            'separation_type_id' => ['required', 'exists:separation_types,id'],
            // Which checklist approval workflow this request follows —
            // frozen here at creation time and never re-derived, so a later
            // change to how the New Request form defaults/behaves can never
            // alter an already-created request (see
            // `ChecklistCompletionService::checkRegularChecklistsCompletion()`
            // for where this actually changes behavior).
            // Nullable, not required: the New Offboarding Request modal's
            // Approval Mode picker is currently commented out (see
            // `new-request-modal.blade.php`), so nothing is ever submitted
            // for this field — defaulted to 'async' (Parallel) below once
            // validation passes. Still validated against the same enum so a
            // stray/tampered value can't sneak in if the picker is ever
            // re-enabled.
            'approval_mode' => ['nullable', Rule::in(['sync', 'async'])],
            // Per-request overrides for the 3 fixed-name email templates
            // that fire at creation time — see the matching Select fields
            // on the New Offboarding Request modal, and
            // `ChecklistApprovalNotifier`'s use of these once stored.
            'approver_notification_template_id' => ['nullable', 'exists:email_templates,id'],
            'offboardee_notification_template_id' => ['nullable', 'exists:email_templates,id'],
            'general_signatory_notification_template_id' => ['nullable', 'exists:email_templates,id'],
        ]);

        // Default to Parallel ('async') while the Approval Mode picker
        // stays commented out — see the note on the validation rule above.
        $validated['approval_mode'] = $validated['approval_mode'] ?? 'async';

        // Every request must have a real, currently-active Final Approver
        // configured system-wide — checked here so a misconfigured system
        // fails loudly at creation time rather than silently producing a
        // Clearance Form with a blank final signatory later. The resolved
        // row's `employee_id` is snapshotted below purely as a HISTORICAL
        // record of who was active at creation time; the Clearance Form
        // itself never reads that snapshot — it always resolves whichever
        // `FinalApprover` is active live, at render time (see
        // `ClearanceFormController::buildData()`), so this same check
        // passing here is not what makes a later Clearance Form correct —
        // it's a defensive guard against the system ever having zero
        // active Final Approvers at all.
        $activeFinalApprover = FinalApprover::where('is_active', true)->first();

        if (! $activeFinalApprover) {
            return back()->withErrors([
                'final_approver' => 'No active Final Approver is configured. Please set one on the Offboarding Checklist page before creating an offboarding request.',
            ])->withInput();
        }

        $employee = Employee::findOrFail($validated['employee_id']);

        // Guards against creating a second, duplicate offboarding request
        // for an employee who already has one in progress — most
        // importantly, this also covers the "Try Again after a timeout"
        // case: `$employee->update(['status' => 'offboarding'])` below runs
        // BEFORE the slow, timeout-prone notification step further down, so
        // even if THAT step is what actually times out, this status flip
        // has already durably committed by then — a resubmission correctly
        // lands here instead of creating a duplicate. The New Request
        // modal's own employee picker already only offers `status = 'active'`
        // employees in the first place; this is the server-side backstop
        // for a stale page, a resubmitted form, or a direct request.
        if ($employee->status !== 'active') {
            return back()->withErrors([
                'employee_id' => "{$employee->name} already has an offboarding request in progress — refresh the page and select a different employee, or use the existing request instead of submitting a new one.",
            ])->withInput();
        }

        // Fallback: if the admin didn't manually pick an Immediate Head,
        // use the offboardee's Employee Master Group Head instead — the
        // same "group's registered head" concept `Employee::departmentHead()`
        // already uses for checklist template department heads, applied
        // here to the per-request Immediate Head field. A manually-picked
        // Immediate Head is never overridden (this only runs when the field
        // came in empty), and an employee with no group (or a group with no
        // registered head) simply gets no fallback, same as today.
        if (empty($validated['immediate_head_id'])) {
            $groupHead = $employee->employeeGroup?->groupHead;

            if ($groupHead && $groupHead->id !== $employee->id) {
                $validated['immediate_head_id'] = $groupHead->id;
            }
        }

        // Separation Type — its title/description/Default Notice Period are
        // frozen onto this request right now, so a later edit or delete on
        // the Separation Type Management page can never change a request
        // that already exists (see `SeparationType`'s own docblock).
        $separationType = SeparationType::findOrFail($validated['separation_type_id']);

        // Notification Date = the actual moment this request is submitted
        // (right now — never the admin-picked "Resignation Date"/`notice_date`
        // field, and never the Separation Type's own Default Notice Period
        // if it's edited later) plus the exact Notice Period this
        // Separation Type had at THIS moment. Computed and frozen ONCE,
        // here, at creation time.
        $noticePeriodDays = $separationType->default_notice_period_days;
        $notificationDate = now()->addDays($noticePeriodDays)->toDateString();

        // Everything here is a fast, purely-database write — the request
        // row, the employee's status flip, and both login accounts —
        // wrapped in one transaction so a failure partway through (a
        // dropped DB connection, say) can never leave the request created
        // but the employee still "active", or vice versa. Deliberately
        // does NOT include `notifyDepartmentHeads()` below: that step sends
        // real emails over the network, which can genuinely take a long
        // time — holding a DB transaction open for that entire duration
        // would block other requests against these same rows for no
        // reason, and is exactly the kind of slow I/O a DB transaction
        // should never wrap.
        [$offboardingRequest, $employeeUser, $isNewEmployeeAccount] = DB::transaction(function () use ($validated, $request, $activeFinalApprover, $separationType, $noticePeriodDays, $notificationDate, $employee) {
            $offboardingRequest = OffboardingRequest::create($validated + [
                'status' => 'pending',
                'created_by' => $request->user()->id,
                'final_approver_employee_id' => $activeFinalApprover->employee_id,
                // Frozen once, here, at creation — "Extend Due" (see
                // `ApprovalController::extendAllDue()`) only ever updates
                // `last_working_day` itself, never this column, so it stays
                // the one immutable baseline the Offboardee page compares
                // against to detect/display an extension.
                'original_last_working_day' => $validated['last_working_day'],
                'reason' => $separationType->title,
                'separation_type_description' => $separationType->description,
                'notice_period_days' => $noticePeriodDays,
                'notification_date' => $notificationDate,
            ]);

            $employee->update(['status' => 'offboarding']);

            // Every offboardee gets their own login the moment their request is
            // created, so they can track their own process from day one — see
            // `ChecklistApprovalNotifier::notifyOffboardee()` below, which emails
            // these exact credentials only when the account is genuinely new.
            // Never promotes/downgrades an existing account's role (see
            // `User::findOrCreateEmployee()`), so an employee who already has an
            // account (e.g. as someone else's approver) keeps that role and
            // simply never receives credentials in this email.
            $existingEmployeeUser = User::firstWhere('username', $employee->employee_code_digits);
            $employeeUser = $existingEmployeeUser ?? User::findOrCreateEmployee($employee);
            $isNewEmployeeAccount = $existingEmployeeUser === null;

            // The Immediate Head is an additional authorized signatory on the
            // Clearance Form, outside the checklist approval workflow — they
            // still need a login account to access whatever offboarding/
            // clearance functions they're granted, same convention as any other
            // approver account (username/password = employee_code_digits).
            if (! empty($validated['immediate_head_id'])) {
                User::findOrCreateApprover(Employee::findOrFail($validated['immediate_head_id']));
            }

            return [$offboardingRequest, $employeeUser, $isNewEmployeeAccount];
        });

        ActivityLog::record('offboarding_request_created', 'Offboarding', "Offboarding request created for {$employee->name}.", [
            'subject_type' => 'OffboardingRequest',
            'subject_id' => $offboardingRequest->id,
        ]);

        $successMessage = 'Offboarding request submitted.';

        try {
            // The step actually seen timing out in practice (sending each
            // Department Head/General Signatory/offboardee email
            // synchronously over SMTP) — extended well past the default
            // execution limit so a request with many recipients has room to
            // finish normally instead of hitting it. Harmless to call even
            // when `max_execution_time` is unlimited (e.g. `0` via CLI).
            // If this genuinely still isn't enough, the PHP-level fatal it
            // triggers is handled gracefully app-wide — see
            // `bootstrap/app.php`'s exception `render()` callback.
            set_time_limit(120);

            $this->notifyDepartmentHeads($offboardingRequest, $request->user(), $employeeUser, $isNewEmployeeAccount);
        } catch (\Throwable $e) {
            Log::error('Failed to process offboarding approver notifications.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return back()->with('success', $successMessage);
    }

    /**
     * Wipes every checklist/approval progress artifact this offboarding
     * request has accumulated and reinitializes it exactly the way `store()`
     * initializes a brand-new one — same `notifyDepartmentHeads()` call,
     * against whatever checklist templates/General Signatories are active
     * and applicable RIGHT NOW (not whatever was attached originally), so a
     * template added/retired since the original submission is correctly
     * reflected. The request row itself (id, employee, notice/last-working-
     * day dates, reason, immediate head, `created_at`/`created_by`) is left
     * untouched — this restarts the WORKFLOW, not the request's own
     * identity/history — and nothing here ever touches `Employee` fields
     * beyond the same `status` flip `store()` already makes. Gated by the
     * `offboarding-requests.reset` permission at the route level (Admin-only
     * by default), so only an authorized admin ever reaches this action,
     * including via a direct request.
     *
     * `completed` is a final, locked state — enforced HERE, not just by
     * hiding the button in the view, so a direct POST/API call against a
     * completed request is rejected exactly the same way regardless of how
     * it was triggered. Checked against the real `status` column (never
     * `displayStatus()`, which can read "overdue"/"in_progress" for other
     * reasons but only ever reflects `completed` when the column itself
     * genuinely is).
     */
    public function reset(Request $request, OffboardingRequest $offboardingRequest): RedirectResponse
    {
        abort_if(
            in_array($offboardingRequest->status, ['completed', 'cancelled'], true),
            422,
            $offboardingRequest->status === 'completed'
                ? 'This offboarding request is already completed and cannot be reset.'
                : 'This offboarding request has been retracted and cannot be reset.'
        );

        // Re-resolved fresh purely to refresh the historical snapshot
        // column (see `OffboardingRequest::finalApproverEmployee()`'s own
        // docblock) and to re-run the same defensive "must have one active"
        // guard `store()` applies — the Clearance Form itself never reads
        // this snapshot regardless, always resolving the live active
        // `FinalApprover` at render time, so this re-resolution doesn't
        // change anything about what a Clearance Form displays.
        $activeFinalApprover = FinalApprover::where('is_active', true)->first();

        abort_if(
            ! $activeFinalApprover,
            422,
            'No active Final Approver is configured. Please set one on the Offboarding Checklist page before resetting this offboarding request.'
        );

        $offboardingRequest->loadMissing('employee');
        $employee = $offboardingRequest->employee;
        $admin = $request->user();

        DB::transaction(function () use ($offboardingRequest, $employee, $admin, $activeFinalApprover) {
            // Deleting the approver rows cascades (DB-enforced) to every
            // per-checklist artifact keyed off them: item progress, holds,
            // delegations, item reassignments, approval tokens, and pool
            // members — see the matching `cascadeOnDelete()` migrations.
            $offboardingRequest->approvers()->delete();

            // Same cascade shape for the General Signatory track: deleting
            // these rows also removes their approval tokens.
            $offboardingRequest->generalSignatoryApprovals()->delete();

            $offboardingRequest->followUps()->delete();
            $offboardingRequest->scheduledEmailSends()->delete();
            ChecklistItemScheduledSend::where('offboarding_request_id', $offboardingRequest->id)->delete();

            // Clears the checklist-templates pivot entirely rather than
            // leaving stale entries for a template that's since been
            // deactivated/retired — `notifyDepartmentHeads()` below re-syncs
            // it from scratch against whatever is active right now.
            $offboardingRequest->checklistTemplates()->detach();

            $offboardingRequest->activities()->delete();

            $offboardingRequest->update([
                'status' => 'pending',
                'completed_at' => null,
                'final_pay_notified_at' => null,
                // Mirrors `final_pay_notified_at` above — the Sync
                // workflow's own Primary -> Secondary one-shot lock (see
                // `ChecklistCompletionService::checkPrimaryChecklistsCompletion()`)
                // must also be cleared, or a reset Sync request would find
                // it already set from before the reset and never re-attach
                // Secondary checklists at all.
                'secondary_notified_at' => null,
                // Same rationale, for the independent General Signatory
                // track's own Core -> Secondary -> Final Pay locks (see
                // `ChecklistCompletionService::checkPrimaryGeneralSignatoriesCompletion()`/
                // `attachFinalPayGeneralSignatoriesIfReady()`).
                'general_signatory_secondary_notified_at' => null,
                'general_signatory_final_pay_notified_at' => null,
                'remarks' => null,
                'final_approver_employee_id' => $activeFinalApprover->employee_id,
            ]);

            $employee->update(['status' => 'offboarding']);

            // The one thing that survives the wipe above — an explicit,
            // dated audit record of who reset this request and when, so
            // "the process restarted from scratch" is never silent even
            // though every prior progress/activity row is now gone.
            $offboardingRequest->activities()->create([
                'user_id' => $admin->id,
                'action' => 'offboarding_reset',
                'status' => 'pending',
                'comment' => "All checklist and approval progress was cleared by {$admin->name}; the offboarding process was restarted from the beginning.",
            ]);
        });

        $offboardingRequest->refresh();

        ActivityLog::record('offboarding_request_reset', 'Offboarding', "Offboarding request for {$employee->name} was reset and restarted.", [
            'subject_type' => 'OffboardingRequest',
            'subject_id' => $offboardingRequest->id,
        ]);

        // Identical account-provisioning shape to `store()` — idempotent,
        // never promotes/downgrades an existing account, so re-running this
        // on reset never touches the offboardee's (or immediate head's)
        // existing login/role.
        $existingEmployeeUser = User::firstWhere('username', $employee->employee_code_digits);
        $employeeUser = $existingEmployeeUser ?? User::findOrCreateEmployee($employee);
        $isNewEmployeeAccount = $existingEmployeeUser === null;

        if (! empty($offboardingRequest->immediate_head_id)) {
            User::findOrCreateApprover(Employee::findOrFail($offboardingRequest->immediate_head_id));
        }

        try {
            // Same reasoning as `store()`'s identical call — see its own
            // comment.
            set_time_limit(120);

            $this->notifyDepartmentHeads($offboardingRequest, $admin, $employeeUser, $isNewEmployeeAccount);
        } catch (\Throwable $e) {
            Log::error('Failed to process offboarding approver notifications after reset.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'exception' => $e->getMessage(),
            ]);
        }

        return back()->with('success', "Offboarding request for {$employee->name} has been reset and restarted.");
    }

    /**
     * "Retract Offboarding" (user-facing label; the stored `status` value
     * stays `'cancelled'` — no new DB enum value, see
     * `OffboardingRequest::isReadOnly()`'s own docblock) — stops the
     * offboarding process WITHOUT deleting anything it ever accumulated.
     * Every checklist approver (and everything DB-related to it — item
     * progress, holds, delegations, item reassignments, approval tokens,
     * pool members, follow-ups, due date extensions), every General
     * Signatory approval, every checklist template attachment, every
     * scheduled email/item send, any Final Approval process, and the
     * entire activity/timeline log all SURVIVE untouched — the request
     * becomes a permanently frozen, read-only historical record instead of
     * an emptied-out shell. Only the request's own `status`/`cancelled_at`/
     * `cancelled_by`/`cancellation_reason` columns change. This is the same
     * "row survives forever, only its status changes" convention a
     * signatory-declined checklist already follows (see
     * `ApprovalController::decline()`), so a retracted request reads
     * identically on the Offboardee page (still listed, "Retracted" badge,
     * full history still viewable) instead of the employee simply
     * vanishing from it.
     *
     * Every other action that could otherwise still mutate this now-frozen
     * data (approve/decline/remind/assign/reset, and "Extend Last Working
     * Day" via `extendDueApplicableApprovers()`/
     * `extendDueApplicableGeneralSignatories()` returning empty once
     * `isReadOnly()`) is independently guarded by `isReadOnly()` at its own
     * call site — this method has no special responsibility for enforcing
     * that beyond flipping `status`.
     *
     * Nothing here ever touches the `Employee` master record beyond the
     * same `status` flip back to 'active' every other
     * request-no-longer-active path already makes (this is what makes the
     * SAME employee immediately eligible for a brand new, independent
     * offboarding request — the old retracted request and the new one
     * coexist as separate rows, see `OffboardeeController::index()`), nor
     * any checklist template/General Signatory/Separation Type
     * configuration — those are shared, independent data this one
     * request's cancellation must never affect.
     *
     * Gated by its own dedicated `offboarding-requests.cancel` permission
     * at the route level (same reasoning as `reset()` above), and rejected
     * here too — not just hidden in the view — for a request that's
     * already `cancelled` or `completed` (a finished request is a locked,
     * final state; a cancelled one can't be cancelled twice).
     *
     * Notification recipients (every Clearance Signatory, General
     * Signatory, and Task Assignee this request ever had) are resolved
     * once, up front, purely so the email wording reflects who was
     * involved at the moment of retraction — the emails themselves are only
     * sent AFTER the transaction commits successfully, per spec: a
     * rolled-back cancellation must never notify anyone their request was
     * cancelled.
     */
    public function cancel(Request $request, OffboardingRequest $offboardingRequest): RedirectResponse|JsonResponse
    {
        abort_if(
            in_array($offboardingRequest->status, ['cancelled', 'completed'], true),
            422,
            $offboardingRequest->status === 'completed'
                ? 'This offboarding request is already completed and cannot be retracted.'
                : 'This offboarding request has already been retracted.'
        );

        // Required BEFORE any destructive action below — a cancellation
        // must never partially proceed (or even start the deletion
        // cascade) without a reason on file.
        if ($request->has('reason')) {
            $request->merge(['reason' => trim((string) $request->input('reason'))]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ], [
            'reason.required' => 'A reason is required to retract this offboarding request.',
        ]);

        $reason = $validated['reason'];

        $offboardingRequest->loadMissing('employee');
        $employee = $offboardingRequest->employee;
        $admin = $request->user();

        $recipients = $this->collectCancellationRecipients($offboardingRequest);

        // Snapshotted up front for both the audit log below and the
        // cancellation emails after the transaction commits, so the
        // notification wording reflects the employee's details at the
        // moment of retraction regardless of anything that changes on the
        // `Employee` record afterward (e.g. its `status` flip to 'active'
        // a few lines down).
        $offboardingRequestId = $offboardingRequest->id;
        $employeeSnapshot = [
            'name' => $employee->name,
            'employeeCode' => $employee->employee_code,
            'department' => $employee->department,
            'originalLastWorkingDay' => $offboardingRequest->original_last_working_day?->format('M d, Y')
                ?? $offboardingRequest->last_working_day?->format('M d, Y'),
        ];

        DB::transaction(function () use ($offboardingRequest, $employee, $admin, $reason) {
            // Deliberately nothing is deleted here anymore — see this
            // method's own docblock. Every approver, General Signatory
            // approval, checklist template attachment, scheduled send,
            // Final Approval, activity, and bell notification tied to this
            // request survives untouched; only the columns below change.
            $offboardingRequest->update([
                'status' => 'cancelled',
                'cancelled_at' => now(),
                'cancelled_by' => $admin->id,
                // Never editable after this point — there is no update path
                // for this column anywhere else in the app, since a request
                // can only ever be cancelled once (the guard at the top of
                // this method rejects a second cancellation outright).
                'cancellation_reason' => $reason,
                'completed_at' => null,
                'final_pay_notified_at' => null,
                'secondary_notified_at' => null,
                'general_signatory_secondary_notified_at' => null,
                'general_signatory_final_pay_notified_at' => null,
            ]);

            $employee->update(['status' => 'active']);

            // Same "never silent" convention `reset()`'s own final activity
            // follows. The reason is embedded directly in this comment
            // (this table has no dedicated reason column of its own), so
            // it's part of the permanent timeline/audit trail exactly like
            // every other activity comment in this app.
            $offboardingRequest->activities()->create([
                'user_id' => $admin->id,
                'action' => 'offboarding_cancelled',
                'status' => 'cancelled',
                'comment' => "Offboarding request retracted by {$admin->name}; all checklist, clearance, and approval history for this request is preserved as a read-only historical record. Reason: {$reason}",
            ]);
        });

        Log::info('Offboarding request cancelled.', [
            'offboarding_request_id' => $offboardingRequestId,
            'employee' => $employeeSnapshot['name'] . ' (' . $employeeSnapshot['employeeCode'] . ')',
            'cancelled_by' => $admin->name,
            'cancelled_at' => now()->toDateTimeString(),
            'result' => 'success',
        ]);

        ActivityLog::record('offboarding_request_cancelled', 'Offboarding', "Offboarding request for {$employeeSnapshot['name']} was retracted.", [
            'subject_type' => 'OffboardingRequest',
            'subject_id' => $offboardingRequestId,
            'new_values' => ['reason' => $reason],
        ]);

        // Reads the SAVED column (the same in-memory instance `update()`
        // above already set), never the raw request input directly — the
        // email must reflect exactly what was persisted, not merely what
        // was typed into the modal.
        $this->sendCancellationNotifications($recipients, $employeeSnapshot, $offboardingRequestId, $admin, $offboardingRequest->cancellation_reason);

        // The Offboardee Page's Cancel Offboarding button submits via
        // `fetch()` (not a native form post) so it can remove just this one
        // card and show a success/error toast without a full-page
        // reload — it sends `Accept: application/json` to opt into this
        // branch. A non-AJAX caller (there currently isn't one) still gets
        // the original redirect-with-flash behavior.
        if ($request->wantsJson()) {
            // Read back the SAVED columns (the same in-memory instance the
            // transaction's own `update()` already set), never the raw
            // request input directly — the Offboardee card's retraction
            // history must reflect exactly what was persisted, and who
            // actually performed it, not merely what was typed/who is
            // currently viewing the page. See
            // `OffboardeeController::index()`'s identical fields for the
            // same data on a normal (non-AJAX) page load.
            return response()->json([
                'success' => true,
                'message' => 'Offboarding request has been retracted.',
                'cancelledByName' => $admin->name,
                'cancellationReason' => $offboardingRequest->cancellation_reason,
                'cancelledAt' => $offboardingRequest->cancelled_at?->format('M d, Y g:i A'),
            ]);
        }

        return back()->with('success', 'Offboarding request has been retracted.');
    }

    /**
     * Every person tied to this specific offboarding request — resolved
     * entirely from ITS OWN snapshot rows, never from the live checklist
     * template configuration (a template can be edited/reassigned after
     * this request was created, which must never leak into who gets told
     * about ITS cancellation). Covers:
     *
     *  - The Offboardee themselves — every checklist, clearance, approval,
     *    and task activity tied to this request no longer applies to them.
     *  - The Immediate Head selected on the request (see `immediateHead()`).
     *  - Each checklist's own Clearance Signatory (a normal, single-owner
     *    checklist's `employee_id` on its `OffboardingRequestApprover` row).
     *  - Every Task Assignee on EVERY item of EVERY checklist (via
     *    `effectiveSignatoryFor()`, which reads THIS request's own
     *    `itemAssignments` snapshot — not the template's live signatory —
     *    and regardless of whether they'd already finished their own item:
     *    a cancelled request needs everyone told, not just whoever was
     *    still pending).
     *  - Every General Signatory's own Clearance Signatory.
     *
     * Deduplicated by EMPLOYEE ID — matches how `ChecklistApprovalNotifier`
     * already notifies at creation time: the same PERSON holding more than
     * one role, or assigned to more than one checklist, gets only ONE
     * email, but two genuinely different employees are always notified
     * separately even if their records happen to share an email address
     * (as this app's own test data deliberately does — one real inbox
     * standing in for many distinct people while testing). Rows with no
     * usable email are dropped here rather than in the send loop, so the
     * recipient count actually reflects who will be emailed.
     *
     * @return Collection<int, Employee>
     */
    private function collectCancellationRecipients(OffboardingRequest $offboardingRequest): Collection
    {
        $offboardingRequest->loadMissing([
            'employee', 'immediateHead',
            'approvers.employee', 'approvers.checklistTemplate.items.signatory', 'approvers.itemAssignments.assignedEmployee',
            'generalSignatoryApprovals.generalSignatory.clearanceSignatory',
        ]);

        $recipients = collect([$offboardingRequest->employee, $offboardingRequest->immediateHead]);

        foreach ($offboardingRequest->approvers as $approver) {
            if ($approver->employee_id !== null) {
                $recipients->push($approver->employee);
            }

            foreach ($approver->checklistTemplate?->items ?? [] as $item) {
                $recipients->push($approver->effectiveSignatoryFor($item));
            }
        }

        foreach ($offboardingRequest->generalSignatoryApprovals as $gsApproval) {
            $recipients->push($gsApproval->generalSignatory?->clearanceSignatory);
        }

        return $recipients
            ->filter(fn (?Employee $recipient) => $recipient && $recipient->email && filter_var($recipient->email, FILTER_VALIDATE_EMAIL))
            ->unique('id')
            ->values();
    }

    /**
     * Sends the "Offboarding Request Cancelled" notification to every
     * resolved recipient — called only after `cancel()`'s transaction has
     * already committed, per spec ("only after the cancellation and
     * related database operations have completed successfully"). Mirrors
     * `remind()`'s own graceful degradation: silently does nothing if the
     * template is missing/inactive, and a per-recipient send failure is
     * logged and skipped rather than thrown, since the cancellation itself
     * already succeeded and must never appear to fail because one email
     * bounced.
     *
     * @param  Collection<int, Employee>  $recipients
     * @param  array{name: string, employeeCode: ?string, department: ?string, originalLastWorkingDay: ?string}  $employeeSnapshot
     */
    private function sendCancellationNotifications(Collection $recipients, array $employeeSnapshot, int $offboardingRequestId, User $admin, ?string $cancellationReason): void
    {
        if ($recipients->isEmpty()) {
            return;
        }

        $emailTemplate = EmailTemplate::where('is_active', true)
            ->where('template_name', 'Offboarding Request Cancelled')
            ->latest('updated_at')
            ->first();

        if (! $emailTemplate) {
            Log::warning('No "Offboarding Request Cancelled" email template found — cancellation notifications were not sent.', [
                'offboarding_request_id' => $offboardingRequestId,
            ]);

            return;
        }

        $cancelledAt = now()->format('M d, Y g:i A');
        $sent = 0;
        $failed = 0;

        // Recipients arriving here are already deduplicated by email and
        // pre-validated (see `collectCancellationRecipients()`) — every one
        // of them gets attempted regardless of how many others came before,
        // and one failure never skips or aborts the rest of the list.
        foreach ($recipients as $recipient) {
            try {
                [$subject, $body] = $emailTemplate->render(
                    approverName: $recipient->name,
                    offboardeeName: $employeeSnapshot['name'],
                    employeeNumber: $employeeSnapshot['employeeCode'],
                    department: $employeeSnapshot['department'],
                    separationDate: $employeeSnapshot['originalLastWorkingDay'],
                    cancelledBy: $admin->name,
                    cancelledAt: $cancelledAt,
                    offboardingRequestId: (string) $offboardingRequestId,
                    cancellationReason: $cancellationReason,
                );

                Mail::to($recipient->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
                $sent++;

                Log::info('Offboarding cancellation notification sent.', [
                    'offboarding_request_id' => $offboardingRequestId,
                    'recipient_employee_id' => $recipient->id,
                    'recipient' => $recipient->email,
                ]);
            } catch (\Throwable $e) {
                $failed++;

                Log::error('Failed to send offboarding cancellation notification.', [
                    'offboarding_request_id' => $offboardingRequestId,
                    'recipient_employee_id' => $recipient->id,
                    'recipient' => $recipient->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }

        Log::info('Offboarding cancellation notifications complete.', [
            'offboarding_request_id' => $offboardingRequestId,
            'recipients_total' => $recipients->count(),
            'sent' => $sent,
            'failed' => $failed,
        ]);
    }

    /**
     * Attach every active, non-final-pay checklist template that applies to
     * this employee's department to this request — a template with no
     * `department` set applies to everyone as before; one WITH a
     * `department` set only attaches when it exactly matches the
     * offboardee's own `department` (Final Pay is a separate, later stage
     * handled by ApprovalController once these are all approved). The
     * dedicated "Offboarding Request Notification" email (see
     * `ChecklistApprovalNotifier::notifyDepartmentHeadsOfNewRequest()`) is
     * what actually informs each Department Head — the older, freely
     * admin-editable "default Offboarding Announcement" template is
     * deliberately NOT used here (passing `null` skips that email loop
     * entirely inside `attachAndNotify()`), since it's redundant with the
     * dedicated notification and no longer wanted on this trigger.
     *
     * An Immediate Head checklist (see `ChecklistApprovalNotifier::attachAndNotify()`)
     * attaches and notifies right alongside every other template here —
     * it is NOT a prerequisite for the rest. Every assigned Department Head
     * and signatory gets access to, and is notified about, their own
     * checklist immediately, independent of whether/when the Immediate Head
     * completes theirs. The Immediate Head checklist still only ever
     * assigns to that specific request's Immediate Head, and still requires
     * every item checked before it can be submitted (see
     * `OffboardingRequestApprover::requiresAllItemsCompletedBeforeApproval()`)
     * — none of that changes, only the "wait for it first" sequencing does.
     */
    private function notifyDepartmentHeads(OffboardingRequest $offboardingRequest, User $creator, User $employeeUser, bool $isNewEmployeeAccount): void
    {
        $isSyncMode = $offboardingRequest->approval_mode === 'sync';

        $templatesQuery = ChecklistTemplate::where('is_active', true)
            ->where('is_final_pay_checklist', false)
            ->applicableToDepartment($offboardingRequest->employee->department)
            ->with(['departmentHead', 'items.signatory']);

        // Sync: only the FIRST stage (Primary) is attached/notified now —
        // Secondary and the Final Pay Checklist only follow once the
        // required prior stage is approved (see
        // `ChecklistCompletionService::checkPrimaryChecklistsCompletion()`).
        // Async: unchanged from before this feature existed — every
        // non-final-pay template is attached/notified in one batch,
        // immediately, in whatever order the query returns them.
        $templates = $isSyncMode
            ? (clone $templatesQuery)->where('sequence_type', ChecklistTemplate::SEQUENCE_TYPE_PRIMARY)->get()
            : $templatesQuery->get();

        app(ChecklistApprovalNotifier::class)->notifyDepartmentHeadsOfNewRequest($offboardingRequest, $templates);

        app(ChecklistApprovalNotifier::class)->attachAndNotify(
            $offboardingRequest,
            $templates,
            null,
            $creator->name
        );

        if ($isSyncMode) {
            // Defensive, not the expected common case: self-heals a
            // department with zero Primary checklists configured by
            // immediately advancing to Secondary (and beyond, if that's
            // also empty) rather than stalling forever waiting for a
            // Primary approval that can never happen, since none exist.
            // A no-op whenever Primary checklists genuinely were attached
            // above (nothing on them is approved yet).
            app(ChecklistCompletionService::class)->checkRegularChecklistsCompletion($offboardingRequest);
        }

        // Sent last so the checklist summary reflects the templates just
        // attached above (with their resolved due dates/signatories),
        // rather than the bare template list.
        app(ChecklistApprovalNotifier::class)->notifyOffboardee(
            $offboardingRequest,
            $employeeUser,
            $isNewEmployeeAccount,
            $isNewEmployeeAccount ? $offboardingRequest->employee->employee_code_digits : null,
        );

        // General Signatories are a completely independent, checklist-free
        // workflow (see `GeneralSignatory`'s own docblock), but stage
        // through the same Core -> Secondary -> Final Pay pipeline as
        // checklists once classified — mirrors the checklist template
        // query/staging immediately above, just against `GeneralSignatory`
        // instead of `ChecklistTemplate`, and completely independent of
        // department/Immediate Head (a General Signatory applies
        // company-wide).
        $generalSignatoriesQuery = GeneralSignatory::where('is_active', true)
            ->where('is_final_pay_signatory', false)
            ->whereNotIn('id', $offboardingRequest->generalSignatories()->pluck('general_signatories.id'))
            ->with(['clearanceSignatory', 'tasks.signatory']);

        // Sync: only Core is attached/notified now — Secondary and the
        // Final Pay tier only follow once the required prior stage is
        // approved (see `ChecklistCompletionService::checkPrimaryGeneralSignatoriesCompletion()`).
        // Async: Core + Secondary attach/notify together immediately; only
        // the Final Pay tier is deferred.
        $generalSignatoriesToAttach = $isSyncMode
            ? (clone $generalSignatoriesQuery)->where('sequence_type', ChecklistTemplate::SEQUENCE_TYPE_PRIMARY)->get()
            : $generalSignatoriesQuery->get();

        app(ChecklistApprovalNotifier::class)->notifyGeneralSignatories($offboardingRequest, $generalSignatoriesToAttach);

        if ($isSyncMode) {
            // Same self-heal rationale as the checklist block above: a
            // company with zero Core General Signatories configured
            // advances straight to Secondary (and beyond) rather than
            // stalling forever waiting for a Core approval that can never
            // happen.
            app(ChecklistCompletionService::class)->checkRegularGeneralSignatoriesCompletion($offboardingRequest);
        }
    }
}
