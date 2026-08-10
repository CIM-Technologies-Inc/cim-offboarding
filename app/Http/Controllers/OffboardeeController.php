<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\Http\Request;
use Illuminate\View\View;

class OffboardeeController extends Controller
{
    private const STATUSES = ['pending', 'in_progress', 'completed', 'cancelled'];

    public function index(Request $request): View
    {
        $statusFilter = in_array($request->query('status'), self::STATUSES, true)
            ? $request->query('status')
            : null;

        $departmentFilter = $request->query('department') ?: null;

        $openOffboardeeId = $request->query('offboardee') ? (int) $request->query('offboardee') : null;

        $employees = Employee::where(function ($query) {
                $query->where('status', 'offboarding')
                    ->orWhereHas('latestOffboardingRequest', fn ($q) => $q->where('status', 'cancelled'));
            })
            ->with(['latestOffboardingRequest.checklistTemplates', 'latestOffboardingRequest.approvers.checklistTemplate', 'latestOffboardingRequest.approvers.employee'])
            ->orderBy('name')
            ->get();

        $departments = $employees->pluck('department')->unique()->sort()->values();

        if ($statusFilter) {
            $employees = $employees->filter(
                fn (Employee $employee) => ($employee->latestOffboardingRequest?->status ?? 'pending') === $statusFilter
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
            'status' => $employee->latestOffboardingRequest?->status ?? 'pending',
            'lastWorkingDay' => $employee->latestOffboardingRequest?->last_working_day?->format('M d, Y'),
            'checklistTemplates' => $employee->latestOffboardingRequest?->checklistTemplates->pluck('title')->all() ?? [],
            'timeline' => $employee->latestOffboardingRequest?->approverActivityTimeline() ?? [],
        ];

        $offboardees = $employees->map($mapEmployee)->values();

        // A declined request reverts the employee to "active", so they may no
        // longer be in the list above by the time a notification links here —
        // fetch them separately so the deep link still opens their timeline.
        $deepLinkOffboardee = null;

        if ($openOffboardeeId) {
            $deepLinkOffboardee = $offboardees->firstWhere('id', $openOffboardeeId);

            if (! $deepLinkOffboardee) {
                $targetEmployee = Employee::with(['latestOffboardingRequest.checklistTemplates', 'latestOffboardingRequest.approvers.checklistTemplate', 'latestOffboardingRequest.approvers.employee'])->find($openOffboardeeId);
                $deepLinkOffboardee = $targetEmployee ? $mapEmployee($targetEmployee) : null;
            }
        }

        return view('pages.offboardees.index', [
            'title' => 'Offboardees',
            'offboardees' => $offboardees,
            'statusFilter' => $statusFilter,
            'departmentFilter' => $departmentFilter,
            'departments' => $departments,
            'deepLinkOffboardee' => $deepLinkOffboardee,
        ]);
    }
}
