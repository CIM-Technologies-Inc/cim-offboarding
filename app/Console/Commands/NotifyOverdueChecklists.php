<?php

namespace App\Console\Commands;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\EmailTemplate;
use App\Models\OffboardingRequestApprover;
use App\Models\User;
use App\Notifications\ChecklistOverdueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

class NotifyOverdueChecklists extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notify-overdue-checklists';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify admins and the responsible approvers when a checklist assignment passes its due date without being submitted/approved.';

    /**
     * Email template used to email the Department Head when a checklist
     * first goes overdue — same fixed-name lookup convention as
     * `ApprovalController::OVERDUE_TEMPLATE` (kept as a separate constant
     * here rather than shared, since a Command and a Controller have no
     * natural common base to hold it).
     */
    private const OVERDUE_TEMPLATE = 'Offboarding Overdue Notice';

    /**
     * Finds every still-outstanding checklist assignment whose due date has
     * passed and that hasn't been flagged overdue yet, and notifies:
     *   - every admin
     *   - the Department Head (always the final approver)
     *   - on per-item-approver checklists, each item's own effective
     *     signatory, but only for items that are still unchecked
     * `overdue_notified_at` guards this to fire exactly once per assignment
     * — re-running the command (e.g. on the next scheduled tick) never
     * sends duplicate notifications. Resolution (marking these read once
     * the checklist is actually submitted/approved) happens separately in
     * `ApprovalController`, not here.
     */
    public function handle(): int
    {
        $emailTemplate = EmailTemplate::where('is_active', true)
            ->where('template_name', self::OVERDUE_TEMPLATE)
            ->latest('updated_at')
            ->first();

        if (! $emailTemplate) {
            $this->warn('No "' . self::OVERDUE_TEMPLATE . '" email template found — Department Heads will only get the in-app notification, not an email.');
        }

        $overdue = OffboardingRequestApprover::query()
            ->whereNotIn('status', ['approved', 'declined'])
            ->whereNotNull('due_at')
            ->where('due_at', '<', now())
            ->whereNull('overdue_notified_at')
            ->whereHas('offboardingRequest', fn ($q) => $q->whereIn('status', ['pending', 'in_progress']))
            ->with([
                'offboardingRequest.employee',
                'checklistTemplate.items',
                'employee.user',
                'itemProgress',
                'itemAssignments.assignedEmployee.user',
            ])
            ->get();

        foreach ($overdue as $assignment) {
            $this->notifyForAssignment($assignment, $emailTemplate);
        }

        $this->info("Notified {$overdue->count()} overdue checklist assignment(s).");

        return self::SUCCESS;
    }

    private function notifyForAssignment(OffboardingRequestApprover $assignment, ?EmailTemplate $emailTemplate): void
    {
        $recipients = collect();

        // The Department Head is always responsible, as the final approver.
        if ($assignment->employee?->user) {
            $recipients->push($assignment->employee->user);
        }

        // On a per-item-approver checklist, also notify each still-unchecked
        // item's own effective signatory — not everyone assigned to the
        // template, only those actually responsible for what's incomplete.
        if ($assignment->usesPerItemApprovers()) {
            $progress = $assignment->itemProgress->keyBy('checklist_item_id');

            foreach ($assignment->checklistTemplate->items as $item) {
                $isChecked = (bool) ($progress->get($item->id)?->is_checked ?? false);

                if ($isChecked) {
                    continue;
                }

                $signatory = $assignment->effectiveSignatoryFor($item);

                if ($signatory?->user) {
                    $recipients->push($signatory->user);
                }
            }
        }

        $recipients = $recipients
            ->merge(User::where('role', User::ROLE_ADMIN)->get())
            ->filter()
            ->unique('id');

        Notification::send($recipients, new ChecklistOverdueNotification($assignment));

        $this->emailDepartmentHead($assignment, $emailTemplate);

        $assignment->update(['overdue_notified_at' => now()]);
    }

    /**
     * Emails the Department Head (the assignment's primary approver) using
     * the overdue-specific template — separate from the in-app
     * `ChecklistOverdueNotification` above, which every recipient still
     * gets regardless of whether this email succeeds/exists. Silently
     * no-ops when no template is configured or the Department Head has no
     * valid email, same tolerant pattern as the rest of this command (a
     * missing email address never blocks the in-app notification).
     */
    private function emailDepartmentHead(OffboardingRequestApprover $assignment, ?EmailTemplate $emailTemplate): void
    {
        if (! $emailTemplate) {
            return;
        }

        $departmentHead = $assignment->employee;

        if (! $departmentHead || ! $departmentHead->email || ! filter_var($departmentHead->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        $offboardee = $assignment->offboardingRequest->employee;

        [$subject, $body] = $emailTemplate->render(
            approverName: $departmentHead->name,
            offboardeeName: $offboardee->name,
            employeeNumber: $offboardee->employee_code,
            checklistName: $assignment->checklistTemplate?->title,
            dueDate: $assignment->due_at?->format('M d, Y'),
            department: $offboardee->department,
            position: $offboardee->designation,
            daysOverdue: (string) $assignment->daysOverdue(),
            pendingItems: $assignment->itemsStatusTableHtml(),
            checklistStatus: $assignment->clearanceStatusLabel(),
        );

        try {
            Mail::to($departmentHead->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
        } catch (\Throwable $e) {
            Log::error('Failed to send overdue checklist email.', [
                'offboarding_request_approver_id' => $assignment->id,
                'recipient' => $departmentHead->email,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
