<?php

namespace App\Console\Commands;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\EmailTemplate;
use App\Models\OffboardingRequestApprover;
use App\Models\OffboardingRequestGeneralSignatory;
use App\Notifications\ClearanceSigningDueNotification;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Notifies a Clearance Signatory or General Signatory (email + in-app) once
 * their own Clearance Signing Due Date is reached — the Clearance Signing
 * Deadline feature's own scheduled job, deliberately independent of
 * `NotifyOverdueChecklists` (which fires off the separate, pre-existing
 * `due_at`/`overdue_notified_at` fields). Sent only to the assigned
 * signatory themselves, never admins — see `ClearanceSigningDueNotification`'s
 * own docblock for why.
 */
class NotifyClearanceSigningDue extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:notify-clearance-signing-due';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Notify Clearance Signatories and General Signatories when their Clearance Signing Due Date is reached.';

    private const EMAIL_TEMPLATE = 'Clearance Signing Due Reached';

    public function handle(): int
    {
        $emailTemplate = EmailTemplate::where('is_active', true)
            ->where('template_name', self::EMAIL_TEMPLATE)
            ->latest('updated_at')
            ->first();

        if (! $emailTemplate) {
            $this->warn('No "' . self::EMAIL_TEMPLATE . '" email template found — recipients will only get the in-app notification, not an email.');
        }

        $checklistCount = $this->notifyChecklistAssignments($emailTemplate);
        $generalSignatoryCount = $this->notifyGeneralSignatoryAssignments($emailTemplate);

        $this->info("Notified {$checklistCount} checklist assignment(s) and {$generalSignatoryCount} General Signatory assignment(s).");

        return self::SUCCESS;
    }

    /**
     * Every still-outstanding checklist assignment whose Clearance Signing
     * Due Date has passed and that hasn't been notified for it yet —
     * `clearance_signing_due_notified_at` guards this to fire exactly once
     * per due-date event, cleared again by
     * `OffboardingRequestApprover::recalculateClearanceSigningDueDate()`
     * whenever "Extend Due" grants a fresh one.
     */
    private function notifyChecklistAssignments(?EmailTemplate $emailTemplate): int
    {
        $due = OffboardingRequestApprover::query()
            ->whereNotIn('status', ['approved', 'declined'])
            ->whereNotNull('clearance_signing_due_at')
            ->where('clearance_signing_due_at', '<=', now())
            ->whereNull('clearance_signing_due_notified_at')
            ->whereHas('offboardingRequest', fn ($q) => $q->whereIn('status', ['pending', 'in_progress']))
            ->with(['offboardingRequest.employee', 'checklistTemplate', 'employee.user'])
            ->get();

        foreach ($due as $assignment) {
            $this->notifyChecklistSignatory($assignment, $emailTemplate);
        }

        return $due->count();
    }

    private function notifyChecklistSignatory(OffboardingRequestApprover $assignment, ?EmailTemplate $emailTemplate): void
    {
        $signatory = $assignment->employee;
        $offboardee = $assignment->offboardingRequest->employee;
        $checklistTitle = $assignment->checklistTemplate?->title ?? 'Checklist';

        if ($signatory?->user) {
            Notification::send($signatory->user, new ClearanceSigningDueNotification(
                offboardingRequestId: $assignment->offboarding_request_id,
                offboardingRequestApproverId: $assignment->id,
                offboardingRequestGeneralSignatoryId: null,
                offboardeeName: $offboardee->name,
                offboardeeEmployeeCode: $offboardee->employee_code,
                checklistTitle: $checklistTitle,
                dueAt: $assignment->clearance_signing_due_at?->format('M d, Y g:i A'),
            ));
        }

        if ($signatory && $emailTemplate) {
            $this->sendEmail($emailTemplate, $signatory, $offboardee, $checklistTitle, $assignment->clearance_signing_due_at?->format('M d, Y g:i A'), $assignment->id);
        }

        $assignment->update(['clearance_signing_due_notified_at' => now()]);
    }

    /**
     * Every still-outstanding General Signatory assignment whose own
     * Clearance Signing Due Date has passed — the General Signatory
     * equivalent of `notifyChecklistAssignments()` above, same
     * fire-once-per-event guard.
     */
    private function notifyGeneralSignatoryAssignments(?EmailTemplate $emailTemplate): int
    {
        $due = OffboardingRequestGeneralSignatory::query()
            ->whereNotIn('status', ['approved', 'declined'])
            ->whereNotNull('due_at')
            ->where('due_at', '<=', now())
            ->whereNull('clearance_signing_due_notified_at')
            ->whereHas('offboardingRequest', fn ($q) => $q->whereIn('status', ['pending', 'in_progress']))
            ->with(['offboardingRequest.employee', 'generalSignatory.clearanceSignatory.user'])
            ->get();

        foreach ($due as $assignment) {
            $this->notifyGeneralSignatory($assignment, $emailTemplate);
        }

        return $due->count();
    }

    private function notifyGeneralSignatory(OffboardingRequestGeneralSignatory $assignment, ?EmailTemplate $emailTemplate): void
    {
        $signatory = $assignment->generalSignatory?->clearanceSignatory;
        $offboardee = $assignment->offboardingRequest->employee;
        $checklistTitle = 'General Signatory Clearance';

        if ($signatory?->user) {
            Notification::send($signatory->user, new ClearanceSigningDueNotification(
                offboardingRequestId: $assignment->offboarding_request_id,
                offboardingRequestApproverId: null,
                offboardingRequestGeneralSignatoryId: $assignment->id,
                offboardeeName: $offboardee->name,
                offboardeeEmployeeCode: $offboardee->employee_code,
                checklistTitle: $checklistTitle,
                dueAt: $assignment->due_at?->format('M d, Y g:i A'),
            ));
        }

        if ($signatory && $emailTemplate) {
            $this->sendEmail($emailTemplate, $signatory, $offboardee, $checklistTitle, $assignment->due_at?->format('M d, Y g:i A'), $assignment->id);
        }

        $assignment->update(['clearance_signing_due_notified_at' => now()]);
    }

    /**
     * Shared email send for both assignment kinds — silently no-ops on a
     * missing/invalid address, same tolerant pattern
     * `NotifyOverdueChecklists::emailDepartmentHead()` already follows (a
     * missing email never blocks the in-app notification, which has
     * already been sent by the time this runs).
     */
    private function sendEmail(EmailTemplate $emailTemplate, $signatory, $offboardee, string $checklistTitle, ?string $dueAt, int $assignmentId): void
    {
        if (! $signatory->email || ! filter_var($signatory->email, FILTER_VALIDATE_EMAIL)) {
            return;
        }

        [$subject, $body] = $emailTemplate->render(
            approverName: $signatory->name,
            offboardeeName: $offboardee->name,
            employeeNumber: $offboardee->employee_code,
            checklistName: $checklistTitle,
            dueDate: $dueAt,
            department: $offboardee->department,
            position: $offboardee->designation,
        );

        try {
            Mail::to($signatory->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
        } catch (\Throwable $e) {
            Log::error('Failed to send Clearance Signing Due Reached email.', [
                'assignment_id' => $assignmentId,
                'recipient' => $signatory->email,
                'exception' => $e->getMessage(),
            ]);
        }
    }
}
