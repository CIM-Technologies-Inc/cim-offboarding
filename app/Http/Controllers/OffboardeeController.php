<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OffboardeeController extends Controller
{
    private const STATUSES = ['pending', 'in_progress', 'overdue', 'completed', 'cancelled'];

    public function index(Request $request): View
    {
        $statusFilter = in_array($request->query('status'), self::STATUSES, true)
            ? $request->query('status')
            : null;

        $departmentFilter = $request->query('department') ?: null;

        $openOffboardeeId = $request->query('offboardee') ? (int) $request->query('offboardee') : null;

        // `offboarding` (still in progress) and `offboarded` (fully
        // completed — see `ChecklistCompletionService::checkFinalPayCompletion()`)
        // both belong here: this page is the permanent historical record of
        // every offboarding case regardless of how far along or how long
        // finished it is. Only a genuinely `active` employee (never
        // started, or reverted after a cancelled/deleted request) is
        // excluded.
        $employees = Employee::where(function ($query) {
                $query->whereIn('status', ['offboarding', 'offboarded'])
                    ->orWhereHas('latestOffboardingRequest', fn ($q) => $q->where('status', 'cancelled'));
            })
            ->with(['latestOffboardingRequest.checklistTemplates', 'latestOffboardingRequest.approvers.checklistTemplate', 'latestOffboardingRequest.approvers.employee', 'latestOffboardingRequest.approvers.itemProgress', 'latestOffboardingRequest.generalSignatoryApprovals', 'latestOffboardingRequest.immediateHead', 'latestOffboardingRequest.finalApproval.employee'])
            ->orderBy('name')
            ->get();

        $departments = $employees->pluck('department')->unique()->sort()->values();

        if ($statusFilter) {
            $employees = $employees->filter(
                fn (Employee $employee) => ($employee->latestOffboardingRequest?->displayStatus() ?? 'pending') === $statusFilter
            );
        }

        if ($departmentFilter) {
            $employees = $employees->filter(
                fn (Employee $employee) => $employee->department === $departmentFilter
            );
        }

        $mapEmployee = fn (Employee $employee) => [
            'id' => $employee->id,
            'name' => $employee->name,
            'employeeCode' => $employee->employee_code,
            'department' => $employee->department,
            'designation' => $employee->designation,
            'status' => $employee->latestOffboardingRequest?->displayStatus() ?? 'pending',
            'lastWorkingDay' => $employee->latestOffboardingRequest?->last_working_day?->format('M d, Y'),
            'immediateHead' => $employee->latestOffboardingRequest?->immediateHead?->name,
            'checklistTemplates' => $employee->latestOffboardingRequest?->checklistTemplates->pluck('title')->all() ?? [],
            'timeline' => $employee->latestOffboardingRequest?->approverActivityTimeline() ?? [],
            // URLs are generated whenever a request exists, regardless of its
            // status — admins see the Clearance buttons unconditionally (see
            // the status-timeline-modal component), while everyone else stays
            // gated to a completed request by that same component's
            // `x-if`. `ClearanceFormController` still independently enforces
            // "completed only" server-side, so a non-admin can never actually
            // generate the document early even if this URL were exposed to them.
            'clearanceFormUrl' => $employee->latestOffboardingRequest
                ? route('clearance-form.pdf', $employee->latestOffboardingRequest)
                : null,
            'printClearanceFormUrl' => $employee->latestOffboardingRequest
                ? route('clearance-form.print', $employee->latestOffboardingRequest)
                : null,
            // Same "generate the URL unconditionally, gate the button in the
            // view" convention as the Clearance Form URLs above — the Reset
            // Offboarding button itself is only ever rendered for a user
            // with the `offboarding-requests.reset` permission (see the
            // card partial), and the route independently re-enforces that
            // same permission server-side, so exposing this URL to everyone
            // here is never itself a privilege escalation.
            'resetOffboardingUrl' => $employee->latestOffboardingRequest
                ? route('offboarding-requests.reset', $employee->latestOffboardingRequest)
                : null,
            // Gates the Reset Offboarding button's visibility (on top of the
            // `offboarding-requests.reset` permission check in the view) —
            // deliberately based on actual checklist/task/General Signatory
            // records via `hasApprovedOrCompletedProgress()`, never on the
            // request's overall display status, so a request nobody has
            // acted on yet never shows a destructive reset action with
            // nothing real to reset.
            'hasOffboardingProgress' => $employee->latestOffboardingRequest?->hasApprovedOrCompletedProgress() ?? false,
            // Final Approval — the button itself is only ever rendered for
            // a request whose real `status` column is 'completed' (see the
            // card partial), gated by the `final-approval.send` permission;
            // the route independently re-enforces both server-side.
            'finalApprovalUrl' => $employee->latestOffboardingRequest
                ? route('final-approval.send', $employee->latestOffboardingRequest)
                : null,
            // null (never sent) | 'pending' (sent, awaiting the Final
            // Signatory) | 'approved' — the card uses this to switch
            // between showing the button and a plain "Approved" badge.
            'finalApprovalStatus' => $employee->latestOffboardingRequest?->finalApproval?->status,
            'finalSignatoryName' => $employee->latestOffboardingRequest?->finalApproval?->employee?->name,
        ];

        $offboardees = $employees->map($mapEmployee)->values();

        // A declined request reverts the employee to "active", so they may no
        // longer be in the list above by the time a notification links here —
        // fetch them separately so the deep link still opens their timeline.
        $deepLinkOffboardee = null;

        if ($openOffboardeeId) {
            $deepLinkOffboardee = $offboardees->firstWhere('id', $openOffboardeeId);

            if (! $deepLinkOffboardee) {
                $targetEmployee = Employee::with(['latestOffboardingRequest.checklistTemplates', 'latestOffboardingRequest.approvers.checklistTemplate', 'latestOffboardingRequest.approvers.employee', 'latestOffboardingRequest.approvers.itemProgress', 'latestOffboardingRequest.generalSignatoryApprovals', 'latestOffboardingRequest.immediateHead', 'latestOffboardingRequest.finalApproval.employee'])->find($openOffboardeeId);
                $deepLinkOffboardee = $targetEmployee ? $mapEmployee($targetEmployee) : null;
            }
        }

        $employeesNotOffboarded = Employee::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'department', 'designation', 'sup_one']);

        // For the New Offboarding Request modal's per-request email
        // template overrides — every function's Select is populated from
        // this same active list, matching the `is_active` scope every
        // fixed-name lookup in `ChecklistApprovalNotifier` already uses.
        $activeEmailTemplates = EmailTemplate::where('is_active', true)
            ->orderBy('template_name')
            ->get(['id', 'template_name']);

        return view('pages.offboardees.index', [
            'title' => 'Offboardees',
            'offboardees' => $offboardees,
            'statusFilter' => $statusFilter,
            'departmentFilter' => $departmentFilter,
            'departments' => $departments,
            'deepLinkOffboardee' => $deepLinkOffboardee,
            'employeesNotOffboarded' => $employeesNotOffboarded,
            'activeEmailTemplates' => $activeEmailTemplates,
        ]);
    }
}
