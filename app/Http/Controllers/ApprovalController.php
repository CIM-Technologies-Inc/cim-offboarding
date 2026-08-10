<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\EmailTemplate;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use App\Notifications\OffboardingApprovalUpdated;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
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

        $assignments = OffboardingRequestApprover::query()
            ->where('status', 'pending')
            ->whereHas('offboardingRequest', fn ($q) => $q->where('status', 'pending'))
            ->when(! $user->isAdmin(), function ($query) use ($user) {
                $employee = $user->employee;

                $employee
                    ? $query->where('employee_id', $employee->id)
                    : $query->whereRaw('1 = 0');
            })
            ->with(['offboardingRequest.employee', 'checklistTemplate.items'])
            ->get()
            ->filter(fn (OffboardingRequestApprover $assignment) => $assignment->offboardingRequest?->employee);

        // Loading your own queue counts as "viewing" whatever's still pending in it.
        if (! $user->isAdmin()) {
            $assignments->each(function (OffboardingRequestApprover $assignment) {
                if (! $assignment->first_viewed_at) {
                    $assignment->update(['first_viewed_at' => now(), 'status' => 'viewed']);
                }
            });
        }

        $approvals = $assignments
            ->map(function (OffboardingRequestApprover $assignment) use ($reasonLabels) {
                $request = $assignment->offboardingRequest;
                $template = $assignment->checklistTemplate;

                return [
                    'id' => $assignment->id,
                    'name' => $request->employee->name,
                    'employeeCode' => $request->employee->employee_code,
                    'department' => $request->employee->department,
                    'designation' => $request->employee->designation,
                    'status' => $request->status,
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
                        ])->values()->all()
                        : [],
                    'approveUrl' => route('approvals.approve', $assignment->id),
                    'timeline' => $request->timeline(),
                ];
            })
            ->values();

        return view('pages.approvals.index', [
            'title' => 'Approvals',
            'approvals' => $approvals,
        ]);
    }

    public function approve(OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
        $this->authorizeAssignment($offboardingRequestApprover);

        abort_unless(
            in_array($offboardingRequestApprover->status, ['pending', 'viewed'], true),
            422,
            'This has already been actioned.'
        );

        $offboardingRequestApprover->update(['status' => 'approved', 'approved_at' => now()]);

        $offboardingRequest = $offboardingRequestApprover->offboardingRequest;

        $this->recordActivityAndNotify($offboardingRequest, 'approved', null);

        if ($offboardingRequest->approvers()->where('status', '!=', 'approved')->doesntExist()) {
            $offboardingRequest->update(['status' => 'in_progress']);
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

        $this->recordActivityAndNotify($offboardingRequest, 'declined', $comment);

        return back()->with('success', $offboardingRequest->employee->name . '\'s offboarding request was declined.');
    }

    /**
     * HR/Admin-only reminder for an approver who hasn't acted yet. Uses the
     * existing email template mechanism (fixed-name lookup, same convention
     * as the initial announcement) rather than hard-coding the message.
     */
    public function remind(OffboardingRequestApprover $offboardingRequestApprover): RedirectResponse
    {
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
