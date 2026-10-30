<?php

namespace App\Http\Controllers;

use App\Models\ChecklistSignatoryAssignment;
use App\Models\Employee;
use App\Models\OffboardingRequestApprover;
use App\Services\ClearanceSignatoryEscalationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * HR/Admin's Clearance Signatory Escalations panel on the Approvals page and
 * its "Assign New Clearance Signatory" action. Both routes require the
 * `checklist-signatories.reassign` permission (route middleware); the
 * reassignment itself — re-checks, row lock, history, notifications — lives
 * in `ClearanceSignatoryEscalationService::reassign()`.
 */
class ClearanceSignatoryEscalationController extends Controller
{
    public function __construct(private ClearanceSignatoryEscalationService $service) {}

    /**
     * Every open checklist whose Clearance Signatory has been escalated or
     * reassigned (or is awaiting HR/Admin), action-required first, plus the
     * eligible-employee list for the selector.
     */
    public function index(): JsonResponse
    {
        $approvers = OffboardingRequestApprover::query()
            ->whereIn('escalation_status', [
                OffboardingRequestApprover::ESCALATION_HR_ACTION_REQUIRED,
                OffboardingRequestApprover::ESCALATION_ESCALATED_TO_SECONDARY,
                OffboardingRequestApprover::ESCALATION_REASSIGNED_BY_HR,
            ])
            ->where('status', '!=', 'approved')
            ->whereHas('offboardingRequest', fn ($query) => $query->whereIn('status', ['pending', 'in_progress']))
            ->with(['offboardingRequest.employee', 'checklistTemplate', 'employee', 'primarySignatory', 'secondarySignatory', 'itemProgress', 'signatoryAssignments.performedBy'])
            ->get()
            ->sortBy(fn (OffboardingRequestApprover $approver) => [$approver->isAwaitingHrReassignment() ? 0 : 1, $approver->escalated_at?->timestamp ?? 0])
            ->values();

        return response()->json([
            'escalations' => $approvers->map(fn (OffboardingRequestApprover $approver) => $this->present($approver))->all(),
            'employees' => $this->service->eligibleEmployeeOptions(),
        ]);
    }

    public function reassign(Request $request, OffboardingRequestApprover $offboardingRequestApprover): JsonResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'integer', 'exists:employees,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'expected_employee_id' => ['nullable', 'integer'],
            'expected_escalation_status' => ['nullable', 'string', 'max:30'],
        ], [
            'employee_id.required' => 'Select the new Clearance Signatory.',
            'employee_id.exists' => 'The selected employee does not exist in Employee Master.',
            'reason.required' => 'Enter the reason for this reassignment.',
        ]);

        $result = $this->service->reassign(
            $offboardingRequestApprover,
            Employee::findOrFail($validated['employee_id']),
            $request->user(),
            trim($validated['reason']),
            isset($validated['expected_employee_id']) ? (int) $validated['expected_employee_id'] : null,
            $validated['expected_escalation_status'] ?? null,
        );

        if (! $result['ok']) {
            return response()->json([
                'message' => $result['message'],
                'errors' => ! empty($result['stale']) ? ['stale' => [$result['message']]] : ['employee_id' => [$result['message']]],
            ], ! empty($result['stale']) ? 409 : 422);
        }

        $approver = $result['approver']->fresh(['offboardingRequest.employee', 'checklistTemplate', 'employee', 'primarySignatory', 'secondarySignatory', 'itemProgress', 'signatoryAssignments.performedBy']);

        return response()->json([
            'message' => $result['message'],
            'escalation' => $this->present($approver),
        ]);
    }

    private function present(OffboardingRequestApprover $approver): array
    {
        $format = fn ($date) => $date?->format('M d, Y g:i A');
        $offboardee = $approver->offboardingRequest->employee;
        $initial = $approver->signatoryAssignments->firstWhere('event', ChecklistSignatoryAssignment::EVENT_INITIAL);
        $preview = $this->service->previewReassignmentDeadline($approver);
        $days = (int) $approver->escalation_days;

        return [
            'id' => $approver->id,
            'offboardeeName' => $offboardee->name,
            'offboardeeCode' => $offboardee->employee_code,
            'offboardeeEmployeeId' => $offboardee->id,
            'checklistTitle' => $approver->checklistTemplate?->title ?? 'Untitled Checklist',
            'state' => $approver->assignmentState(),
            'stateLabel' => $approver->assignmentStateLabel(),
            'awaitingHr' => $approver->isAwaitingHrReassignment(),
            'checklistStatus' => $approver->clearanceStatusLabel(),
            'currentSignatoryName' => $approver->employee?->name,
            'primarySignatoryId' => $approver->primary_signatory_employee_id,
            'primarySignatoryName' => $approver->primarySignatory?->name,
            'secondarySignatoryName' => $approver->secondarySignatory?->name,
            'originalAssignedAt' => $format($initial?->assigned_at ?? $approver->assigned_at),
            'originalDeadline' => $format($approver->primary_deadline_at),
            'secondaryAssignedAt' => $format($approver->secondary_assigned_at),
            'secondaryDeadline' => $format($approver->secondary_deadline_at),
            'escalatedAt' => $format($approver->escalated_at),
            'currentDeadline' => $format($approver->signatory_deadline_at),
            'reason' => $approver->escalation_note,
            'periodLabel' => $days ? $days.' '.($approver->escalation_day_type === 'working' ? 'working' : 'calendar').' day'.($days === 1 ? '' : 's') : null,
            'deadlinePreview' => $format($preview),
            'canReassign' => $this->service->reassignmentBlocker($approver) === null,
            'expectedEmployeeId' => $approver->employee_id,
            'expectedEscalationStatus' => $approver->escalation_status,
            'history' => $approver->signatoryAssignments->map(fn (ChecklistSignatoryAssignment $event) => [
                'label' => ChecklistSignatoryAssignment::EVENT_LABELS[$event->event] ?? $event->event,
                'occurredAt' => $format($event->occurred_at),
                'from' => $event->previous_employee_name,
                'to' => $event->new_employee_name,
                'deadline' => $format($event->deadline_at),
                'trigger' => $event->trigger,
                'by' => $event->initiatorLabel(),
                'reason' => $event->reason,
                'notificationStatus' => $event->notification_status,
            ])->values()->all(),
        ];
    }
}
