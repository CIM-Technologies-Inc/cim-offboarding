<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistItemProgress;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use App\Notifications\OffboardingApprovalUpdated;
use App\Services\ChecklistCompletionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    /**
     * Email template used for the "Notify Approver" reminder.
     */
    private const REMINDER_TEMPLATE = 'Offboarding Reminder';

    public function index(): View
    {
        $reasonLabels = [
            'resignation' => 'Resignation',
            'termination' => 'Termination',
            'retirement' => 'Retirement',
            'layoff' => 'Layoff',
            'other' => 'Other',
        ];

        $user = auth()->user();
        $employee = $user->employee;

        $assignments = OffboardingRequestApprover::query()
            ->whereIn('status', ['pending', 'viewed'])
            ->whereHas('offboardingRequest', fn ($q) => $q->where('status', 'pending'))
            ->when(! $user->isAdmin(), function ($query) use ($employee) {
                $employee
                    ? $query->where(fn ($q) => $q->where('employee_id', $employee->id)
                        ->orWhere('delegated_employee_id', $employee->id)
                        ->orWhereHas('checklistTemplate.items', fn ($qi) => $qi->where('signatory_id', $employee->id))
                        ->orWhereHas('itemAssignments', fn ($qi) => $qi->where('assigned_employee_id', $employee->id)->where('status', 'active')))
                    : $query->whereRaw('1 = 0');
            })
            ->with(['offboardingRequest.employee', 'checklistTemplate.items.signatory', 'employee', 'delegatedEmployee', 'itemProgress.checkedBy.employee', 'itemProgress.heldBy.employee', 'itemAssignments.assignedEmployee'])
            ->get()
            ->filter(fn (OffboardingRequestApprover $assignment) => $assignment->offboardingRequest?->employee);

        // Loading your own queue counts as "viewing" whatever's still pending in
        // it — but only for the primary approver. A delegate merely opening
        // their queue must never flip the primary assignment's status.
        if (! $user->isAdmin() && $employee) {
            $assignments->each(function (OffboardingRequestApprover $assignment) use ($employee) {
                if ($assignment->employee_id === $employee->id && ! $assignment->first_viewed_at) {
                    $assignment->update(['first_viewed_at' => now(), 'status' => 'viewed']);
                }
            });
        }

        $approvals = $assignments
            ->map(function (OffboardingRequestApprover $assignment) use ($reasonLabels, $user, $employee) {
                $request = $assignment->offboardingRequest;
                $template = $assignment->checklistTemplate;

                $isDepartmentHead = $employee && $assignment->employee_id === $employee->id;
                $isPrimaryApprover = $user->isAdmin() || $isDepartmentHead;
                $isDelegate = $employee && $assignment->delegated_employee_id === $employee->id;

                $progressByItemId = $assignment->itemProgress->keyBy('checklist_item_id');

                // Genuinely tasked with this checklist — the real Department
                // Head, the delegate, or the signatory of at least one item —
                // as distinct from `isPrimaryApprover`, which is also true for
                // ANY admin merely browsing/monitoring. Drives whether action
                // buttons like Save Progress show up for someone who isn't
                // actually a participant in this specific checklist.
                $isAssignedApprover = $isDepartmentHead
                    || $isDelegate
                    || ($employee && $template?->items->contains(fn ($item) => $assignment->effectiveSignatoryFor($item)?->id === $employee->id));

                $isReadyForApproval = $assignment->usesPerItemApprovers()
                    && $assignment->allItemsCompleted()
                    && in_array($assignment->status, ['pending', 'viewed'], true);

                $displayStatus = $assignment->isOverdue()
                    ? 'overdue'
                    : (in_array($assignment->status, ['approved', 'declined'], true)
                        ? $assignment->status
                        : ($isReadyForApproval ? 'ready_for_approval' : ($assignment->delegation_status ?? 'pending')));

                return [
                    'id' => $assignment->id,
                    'name' => $request->employee->name,
                    'employeeCode' => $request->employee->employee_code,
                    'department' => $request->employee->department,
                    'designation' => $request->employee->designation,
                    'status' => $request->status,
                    'displayStatus' => $displayStatus,
                    'reason' => $reasonLabels[$request->reason] ?? ucfirst($request->reason),
                    'resignationType' => $request->resignation_type,
                    'noticeDate' => $request->notice_date?->format('M d, Y'),
                    'lastWorkingDay' => $request->last_working_day->format('M d, Y'),
                    'noticePeriod' => $request->notice_period,
                    'approvalMode' => $request->approval_mode === 'sync' ? 'Sync' : 'Async',
                    'checklistTemplates' => $template ? [$template->title] : [],
                    'checklistItems' => $template
                        ? $template->items->map(function ($item) use ($assignment, $template, $progressByItemId, $isPrimaryApprover, $isDelegate, $employee) {
                            $progress = $progressByItemId->get($item->id);
                            $isChecked = (bool) ($progress?->is_checked ?? false);
                            $onHold = $progress?->status === 'hold' && ! $isChecked;
                            // The Department Head's live reassignment, if any,
                            // takes precedence over the template's own static
                            // signatory — items with neither fall back to the
                            // primary approver, same as before per-item
                            // approvers existed.
                            $effectiveSignatory = $assignment->effectiveSignatoryFor($item);
                            $isReassigned = $effectiveSignatory?->id !== $item->signatory_id;
                            $isOwnItem = $employee && $effectiveSignatory && $effectiveSignatory->id === $employee->id;
                            $editable = $isPrimaryApprover || $isDelegate || $isOwnItem;
                            $checkedByEmployee = $progress?->checkedBy?->employee;
                            $heldByEmployee = $progress?->heldBy?->employee;

                            return [
                                'id' => $item->id,
                                'title' => $item->title,
                                'templateTitle' => $template->title,
                                'checked' => $isChecked,
                                'remark' => $progress?->remark,
                                'approverName' => $effectiveSignatory?->name,
                                'approverCode' => $effectiveSignatory?->employee_code,
                                // Set only when this item has actually been
                                // reassigned away from the template's own
                                // signatory — lets the UI show "was assigned
                                // to X" alongside the current assignee.
                                'originalApproverName' => $isReassigned ? $item->signatory?->name : null,
                                'originalApproverCode' => $isReassigned ? $item->signatory?->employee_code : null,
                                'isReassigned' => $isReassigned,
                                'editable' => $editable,
                                // True when the current viewer is literally the
                                // effective signatory for this item (regardless
                                // of whether they're also the primary approver)
                                // — drives the "Done" per-item UI, as distinct
                                // from `editable`, which is also true for the
                                // primary approver/delegate correcting someone
                                // else's item.
                                'isOwnItem' => $isOwnItem,
                                // A peer item-approver (already granted visibility
                                // into this card) may voluntarily take over any
                                // OTHER not-yet-checked item — "Check This List" —
                                // but never one that's on Hold: that's a deliberate
                                // pause only the item's own approver can resolve.
                                'canTakeOver' => ! $editable && ! $isChecked && ! $onHold,
                                'clearedByName' => $isChecked ? $progress?->checkedBy?->name : null,
                                'clearedByCode' => $isChecked ? $checkedByEmployee?->employee_code : null,
                                'clearedAt' => $isChecked ? $progress?->checked_at?->format('M d, Y g:i A') : null,
                                'onHold' => $onHold,
                                'heldByName' => $onHold ? $progress?->heldBy?->name : null,
                                'heldByCode' => $onHold ? $heldByEmployee?->employee_code : null,
                                'heldAt' => $onHold ? $progress?->held_at?->format('M d, Y g:i A') : null,
                                'holdUrl' => route('approvals.items.hold', [$assignment->id, $item->id]),
                                // The button itself is only rendered for the
                                // Department Head client-side; the route is
                                // authorized server-side regardless.
                                'assignItemUrl' => route('approvals.items.assign', [$assignment->id, $item->id]),
                            ];
                        })->values()->all()
                        : [],
                    'isPrimaryApprover' => $isPrimaryApprover,
                    'isAssignedApprover' => $isAssignedApprover,
                    'isDelegate' => $isDelegate,
                    'usesPerItemApprovers' => $assignment->usesPerItemApprovers(),
                    'allItemsCompleted' => $assignment->allItemsCompleted(),
                    'dueAt' => $assignment->due_at?->format('M d, Y'),
                    'isOverdue' => $assignment->isOverdue(),
                    'assignedByName' => $assignment->employee?->name,
                    'assignedByCode' => $assignment->employee?->employee_code,
                    'delegation' => $assignment->isDelegated() ? [
                        'delegatedEmployeeName' => $assignment->delegatedEmployee?->name,
                        'delegatedEmployeeCode' => $assignment->delegatedEmployee?->employee_code,
                        'delegationStatus' => $assignment->delegation_status,
                        'delegatedAt' => $assignment->delegated_at?->format('M d, Y g:i A'),
                        'delegateCompletedAt' => $assignment->delegate_completed_at?->format('M d, Y g:i A'),
                    ] : null,
                    'approveUrl' => route('approvals.approve', $assignment->id),
                    'assignUrl' => route('approvals.assign', $assignment->id),
                    'saveProgressUrl' => route('approvals.save-progress', $assignment->id),
                    // The "Offboarding In Progress" step is omitted here —
                    // this page is scoped to checklist approval activity, and
                    // that step is redundant alongside the approve/decline
                    // steps already shown. Left untouched in `timeline()`
                    // itself since the Calendar page's timeline still uses it.
                    'timeline' => collect($request->timeline())
                        ->reject(fn ($step) => $step['label'] === 'Offboarding In Progress')
                        ->values()
                        ->all(),
                ];
            })
            ->values();

        return view('pages.approvals.index', [
            'title' => 'Approvals',
            'approvals' => $approvals,
            'employees' => Employee::where('status', 'active')->orderBy('name')->get(['id', 'name', 'employee_code', 'department']),
        ]);
    }

    public function approve(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        $this->authorizeAssignment($offboardingRequestApprover);

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This has already been actioned.'
        );

        $validated = $request->validate([
            'items' => ['nullable', 'array'],
            'items.*.checklist_item_id' => ['required', 'exists:checklist_items,id'],
            'items.*.is_checked' => ['nullable', 'boolean'],
            'items.*.remark' => ['nullable', 'string'],
        ]);

        // The Department Head may check items themselves right up to the
        // moment of Submit — including items originally assigned to a
        // different signatory, since they can take over any item directly.
        // Persist that state BEFORE checking completeness below, since a
        // per-item-approver checklist may be finished off by the Department
        // Head's own checkbox in this very action.
        ChecklistItemProgress::syncForAssignment($offboardingRequestApprover, $validated['items'] ?? [], auth()->id());

        if ($offboardingRequestApprover->usesPerItemApprovers() && ! $offboardingRequestApprover->allItemsCompleted()) {
            abort(422, 'All checklist items must be checked before this checklist can be approved.');
        }

        $offboardingRequestApprover->update(['status' => 'approved', 'approved_at' => now()]);

        $this->resolveOverdueNotifications($offboardingRequestApprover);

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;

        $this->recordActivityAndNotify($offboardingRequest, 'approved', null);

        $completionService = app(ChecklistCompletionService::class);

        if ($offboardingRequestApprover->checklistTemplate->is_final_pay_checklist) {
            $completionService->checkFinalPayCompletion($offboardingRequest);
        } else {
            $completionService->checkRegularChecklistsCompletion($offboardingRequest);
        }

        return back()->with('success', $offboardingRequest->employee->name . '\'s offboarding request was approved.');
    }

    public function decline(Request $request, OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        $this->authorizeAssignment($offboardingRequestApprover);

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This has already been actioned.'
        );

        $comment = $request->string('comment')->trim()->value() ?: null;

        $offboardingRequestApprover->update([
            'status' => 'declined',
            'declined_at' => now(),
            'decline_reason' => $comment,
        ]);

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;

        // A decline from any single department is a hard stop for the whole request.
        $offboardingRequest->update(['status' => 'cancelled']);
        $offboardingRequest->employee()->update(['status' => 'active']);

        // Every other still-outstanding assignment on this request (if any)
        // is now moot too, so any overdue notices tied to them no longer
        // apply — not just the one that was just declined.
        $this->resolveOverdueNotificationsForRequest($offboardingRequest);

        $this->recordActivityAndNotify($offboardingRequest, 'declined', $comment);

        return back()->with('success', $offboardingRequest->employee->name . '\'s offboarding request was declined.');
    }

    /**
     * HR/Admin-only reminder for an approver who hasn't approved/declined
     * yet. Uses the existing email template mechanism (fixed-name lookup,
     * same convention as the initial announcement) rather than hard-coding
     * the message. Authorized here explicitly (not just via route
     * middleware), and every reminder is logged as its own timeline
     * activity — who sent it and who it went to — not just the inline
     * `reminder_sent_at` timestamp on the assignment.
     */
    public function remind(OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        abort_if(
            in_array($offboardingRequestApprover->status, ['approved', 'declined'], true),
            422,
            'This approver has already acted — no reminder needed.'
        );

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;
        $offboardee = $offboardingRequest->employee;
        $approverEmployee = $offboardingRequestApprover->employee;

        $emailTemplate = EmailTemplate::where('is_active', true)
            ->where('template_name', self::REMINDER_TEMPLATE)
            ->latest('updated_at')
            ->first();

        if (! $emailTemplate) {
            return back()->with('error', 'No "' . self::REMINDER_TEMPLATE . '" email template found. Please create one first.');
        }

        if (! $approverEmployee->email || ! filter_var($approverEmployee->email, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'This approver has no valid email address on file.');
        }

        [$subject, $body] = $emailTemplate->render(
            $approverEmployee->name,
            $offboardee->name,
            auth()->user()->name,
            $offboardee->employee_code,
            $offboardingRequestApprover->checklistTemplate?->title,
            $offboardingRequestApprover->due_at?->format('M d, Y'),
        );

        try {
            Mail::to($approverEmployee->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
            $offboardingRequestApprover->update(['reminder_sent_at' => now()]);

            $offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'action' => 'reminder_sent',
                'status' => $offboardingRequest->status,
                'comment' => 'Sent to: ' . $approverEmployee->name,
            ]);

            return redirect()
                ->route('offboardees.index', ['offboardee' => $offboardee->id])
                ->with('success', 'Reminder sent to ' . $approverEmployee->name . '.');
        } catch (\Throwable $e) {
            Log::error('Failed to send offboarding reminder email.', [
                'offboarding_request_approver_id' => $offboardingRequestApprover->id,
                'recipient' => $approverEmployee->email,
                'exception' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to send the reminder email.');
        }
    }

    /**
     * Marks any still-unread "checklist overdue" notifications tied to this
     * assignment as read, now that it's been submitted/approved — they stop
     * counting toward the bell's unread badge and stop showing as active.
     * A no-op when the assignment was never actually overdue (or the
     * notifications were already read), so this is always safe to call
     * unconditionally from `approve()`.
     */
    private function resolveOverdueNotifications(OffboardingRequestApprover $offboardingRequestApprover): void
    {
        DatabaseNotification::where('type', 'checklist_overdue')
            ->where('data->offboarding_request_approver_id', $offboardingRequestApprover->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Same as `resolveOverdueNotifications()`, but for every assignment on
     * the given request — used on decline, since cancelling the whole
     * request makes every other still-outstanding assignment's overdue
     * notice moot too, not just the one that was declined.
     */
    private function resolveOverdueNotificationsForRequest(OffboardingRequest $offboardingRequest): void
    {
        $assignmentIds = $offboardingRequest->approvers()->pluck('id');

        if ($assignmentIds->isEmpty()) {
            return;
        }

        DatabaseNotification::where('type', 'checklist_overdue')
            ->whereIn('data->offboarding_request_approver_id', $assignmentIds)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * Blocks approve/decline on an assignment the user isn't the assigned
     * approver for, even if they reach it by guessing/typing the URL.
     */
    private function authorizeAssignment(OffboardingRequestApprover $offboardingRequestApprover): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        abort_unless(
            $offboardingRequestApprover->employee_id === $user->employee?->id,
            403
        );
    }

    /**
     * Records who approved/declined the request (and why, for declines) and
     * notifies every admin. Never allowed to affect the already-saved
     * approve/decline outcome if something here fails.
     */
    private function recordActivityAndNotify(OffboardingRequest $offboardingRequest, string $action, ?string $comment): void
    {
        try {
            $offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'action' => $action,
                'status' => $offboardingRequest->status,
                'comment' => $comment,
            ]);

            Notification::send(
                User::where('role', User::ROLE_ADMIN)->get(),
                new OffboardingApprovalUpdated($offboardingRequest, auth()->user(), $action, $comment)
            );
        } catch (\Throwable $e) {
            Log::error('Failed to record offboarding activity/notification.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'action' => $action,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
