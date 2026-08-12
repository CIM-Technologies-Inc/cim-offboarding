<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\ChecklistItemProgress;
use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use App\Notifications\OffboardingApprovalUpdated;
use App\Services\ChecklistApprovalNotifier;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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

    /**
     * Email template used to notify Final Pay Checklist approvers once every
     * regular checklist has been approved.
     */
    private const FINAL_PAY_APPROVAL_TEMPLATE = 'Final Pay Checklist Approval';

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
                        ->orWhere('delegated_employee_id', $employee->id))
                    : $query->whereRaw('1 = 0');
            })
            ->with(['offboardingRequest.employee', 'checklistTemplate.items', 'employee', 'delegatedEmployee', 'itemProgress'])
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

                $isPrimaryApprover = $user->isAdmin() || ($employee && $assignment->employee_id === $employee->id);

                $progressByItemId = $assignment->itemProgress->keyBy('checklist_item_id');

                $displayStatus = in_array($assignment->status, ['approved', 'declined'], true)
                    ? $assignment->status
                    : ($assignment->delegation_status ?? 'pending');

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
                        ? $template->items->map(fn ($item) => [
                            'id' => $item->id,
                            'title' => $item->title,
                            'templateTitle' => $template->title,
                            'checked' => (bool) ($progressByItemId->get($item->id)?->is_checked ?? false),
                            'remark' => $progressByItemId->get($item->id)?->remark,
                        ])->values()->all()
                        : [],
                    'isPrimaryApprover' => $isPrimaryApprover,
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
                    'timeline' => $request->timeline(),
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

        // The primary approver may check items/add remarks themselves (most
        // relevant when there's no delegate) — Submit saves that state and
        // approves in the same action, since they have no separate "Save
        // Progress" step.
        ChecklistItemProgress::syncForAssignment($offboardingRequestApprover, $validated['items'] ?? [], auth()->id());

        $offboardingRequestApprover->update(['status' => 'approved', 'approved_at' => now()]);

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;

        $this->recordActivityAndNotify($offboardingRequest, 'approved', null);

        if ($offboardingRequestApprover->checklistTemplate->is_final_pay_checklist) {
            $this->checkFinalPayCompletion($offboardingRequest);
        } else {
            $this->checkRegularChecklistsCompletion($offboardingRequest);
        }

        return back()->with('success', $offboardingRequest->employee->name . '\'s offboarding request was approved.');
    }

    /**
     * Once every regular (non-final-pay) checklist assignment on this
     * request is approved, either finish up as before (if no Final Pay
     * Checklist is configured) or attach + notify the Final Pay Checklist
     * approver(s). Guarded by `final_pay_notified_at` under a row lock so
     * two near-simultaneous approvals can never trigger this twice.
     */
    private function checkRegularChecklistsCompletion(OffboardingRequest $offboardingRequest): void
    {
        $allRegularApproved = $offboardingRequest->approvers()
            ->whereHas('checklistTemplate', fn ($q) => $q->where('is_final_pay_checklist', false))
            ->where('status', '!=', 'approved')
            ->doesntExist();

        if (! $allRegularApproved) {
            return;
        }

        DB::transaction(function () use ($offboardingRequest) {
            $locked = OffboardingRequest::whereKey($offboardingRequest->id)
                ->whereNull('final_pay_notified_at')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                // Already claimed by a concurrent approval — nothing more to do here.
                return;
            }

            $locked->activities()->create([
                'action' => 'all_checklists_approved',
                'status' => $locked->status,
            ]);

            $finalPayTemplates = ChecklistTemplate::where('is_active', true)
                ->where('is_final_pay_checklist', true)
                ->with('departmentHead')
                ->get();

            $locked->update(['final_pay_notified_at' => now()]);

            if ($finalPayTemplates->isEmpty()) {
                $locked->update(['status' => 'in_progress']);

                return;
            }

            $emailTemplate = EmailTemplate::where('is_active', true)
                ->where('template_name', self::FINAL_PAY_APPROVAL_TEMPLATE)
                ->latest('updated_at')
                ->first();

            if (! $emailTemplate) {
                Log::warning('No "' . self::FINAL_PAY_APPROVAL_TEMPLATE . '" email template found — final pay approvers were not emailed.', [
                    'offboarding_request_id' => $locked->id,
                ]);
            }

            $notified = app(ChecklistApprovalNotifier::class)->attachAndNotify(
                $locked,
                $finalPayTemplates,
                $emailTemplate
            );

            $locked->activities()->create([
                'action' => 'final_pay_notified',
                'status' => $locked->status,
                'comment' => 'Sent to: ' . (count($notified) ? implode(', ', $notified) : 'no one — check the department heads\' emails'),
            ]);
        });
    }

    /**
     * Once every Final Pay Checklist assignment is approved, the whole
     * offboarding process is complete. Guarded the same way as the regular
     * -> final-pay trigger: locked inside a transaction so two final-pay
     * approvers finishing at nearly the same moment can never both mark the
     * request completed / create duplicate completion records.
     */
    private function checkFinalPayCompletion(OffboardingRequest $offboardingRequest): void
    {
        $allFinalPayApproved = $offboardingRequest->approvers()
            ->whereHas('checklistTemplate', fn ($q) => $q->where('is_final_pay_checklist', true))
            ->where('status', '!=', 'approved')
            ->doesntExist();

        if (! $allFinalPayApproved) {
            return;
        }

        DB::transaction(function () use ($offboardingRequest) {
            $locked = OffboardingRequest::whereKey($offboardingRequest->id)
                ->where('status', '!=', 'completed')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                // Already completed by a concurrent approval.
                return;
            }

            $locked->update(['status' => 'completed', 'completed_at' => now()]);

            $locked->activities()->create([
                'action' => 'completed',
                'status' => 'completed',
                'comment' => 'All required Final Pay Checklist approvals have been completed.',
            ]);
        });
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

        [$subject, $body] = $emailTemplate->render($approverEmployee->name, $offboardee->name, auth()->user()->name);

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
