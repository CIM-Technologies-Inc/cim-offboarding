<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\OffboardingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class OffboardingRequestController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => ['required', 'exists:employees,id'],
            'notice_date' => ['required', 'date'],
            'last_working_day' => ['required', 'date', 'after_or_equal:notice_date'],
            'resignation_type' => ['required', 'string', 'max:255'],
            'reason' => ['required', 'in:resignation,termination,retirement,layoff,other'],
            'notice_period' => ['required', 'in:Immediate,15 Days,30 Days,60 Days,90 Days'],
            'approval_mode' => ['required', 'in:sync,async'],
        ]);

        OffboardingRequest::create($validated + ['status' => 'pending']);

        Employee::whereKey($validated['employee_id'])->update(['status' => 'offboarding']);

        return back()->with('success', 'Offboarding request submitted.');
    }
}
