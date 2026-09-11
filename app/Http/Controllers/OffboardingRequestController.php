<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItemScheduledSend;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\FinalApprover;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\SeparationType;
use App\Models\User;
use App\Services\ChecklistApprovalNotifier;
use App\Services\ChecklistCompletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

class OffboardingRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'immediate_head_id' => ['nullable', 'exists:employees,id', 'different:employee_id'],
            'notice_date' => ['required', 'date'],
            'last_working_day' => ['required', 'date', 'after_or_equal:notice_date'],
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
            'approval_mode' => ['required', Rule::in(['sync', 'async'])],
            // Per-request overrides for the 3 fixed-name email templates
            // that fire at creation time — see the matching Select fields
            // on the New Offboarding Request modal, and
            // `ChecklistApprovalNotifier`'s use of these once stored.
            'approver_notification_template_id' => ['nullable', 'exists:email_templates,id'],
            'offboardee_notification_template_id' => ['nullable', 'exists:email_templates,id'],
            'general_signatory_notification_template_id' => ['nullable', 'exists:email_templates,id'],
        ]);

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
            $offboardingRequest->status === 'completed',
            422,
            'This offboarding request is already completed and cannot be reset.'
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
