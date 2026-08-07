<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use Illuminate\View\View;

class CalendarController extends Controller
{
    public function index(): View
    {
        $offboardingEvents = Employee::whereHas('offboardingRequests')
            ->with(['latestOffboardingRequest.checklistTemplates'])
            ->get()
            ->filter(fn (Employee $employee) => $employee->latestOffboardingRequest?->last_working_day)
            ->map(fn (Employee $employee) => [
                'id' => 'offboarding-' . $employee->latestOffboardingRequest->id,
                'title' => $employee->name,
                'start' => $employee->latestOffboardingRequest->last_working_day->format('Y-m-d'),
                'allDay' => true,
                'extendedProps' => [
                    'calendar' => 'Offboarding',
                    'id' => $employee->id,
                    'name' => $employee->name,
                    'employeeCode' => $employee->employee_code,
                    'department' => $employee->department,
                    'designation' => $employee->designation,
                    'status' => $employee->latestOffboardingRequest->status,
                    'lastWorkingDay' => $employee->latestOffboardingRequest->last_working_day->format('M d, Y'),
                    'checklistTemplates' => $employee->latestOffboardingRequest->checklistTemplates->pluck('title')->all(),
                    'timeline' => $employee->latestOffboardingRequest->timeline(),
                ],
            ])
            ->values();

        return view('pages.calender', [
            'title' => 'Calendar',
            'offboardingEvents' => $offboardingEvents,
        ]);
    }
}
