<?php

namespace App\Http\Controllers;

use App\Models\OffboardingRequest;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function index(): View
    {
        $reasonLabels = [
            'resignation' => 'Resignation',
            'termination' => 'Termination',
            'retirement' => 'Retirement',
            'layoff' => 'Layoff',
            'other' => 'Other',
        ];

        $approvals = OffboardingRequest::where('status', 'pending')
            ->with(['employee', 'checklistTemplates.items'])
            ->latest()
            ->get()
            ->filter(fn (OffboardingRequest $request) => $request->employee)
            ->map(fn (OffboardingRequest $request) => [
                'id' => $request->id,
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
                'checklistTemplates' => $request->checklistTemplates->pluck('title')->all(),
                'checklistItems' => $request->checklistTemplates
                    ->flatMap(fn ($template) => $template->items->map(fn ($item) => [
                        'id' => $item->id,
                        'title' => $item->title,
                        'templateTitle' => $template->title,
                    ]))
                    ->values()
                    ->all(),
                'approveUrl' => route('approvals.approve', $request->id),
                'timeline' => $request->timeline(),
            ])
            ->values();

        return view('pages.approvals.index', [
            'title' => 'Approvals',
            'approvals' => $approvals,
        ]);
    }

    public function approve(OffboardingRequest $offboardingRequest): RedirectResponse
    {
        abort_unless($offboardingRequest->status === 'pending', 422, 'This request has already been actioned.');

        $offboardingRequest->update(['status' => 'in_progress']);

        return back()->with('success', $offboardingRequest->employee->name . '\'s offboarding request was approved.');
    }

    public function decline(OffboardingRequest $offboardingRequest): RedirectResponse
    {
        abort_unless($offboardingRequest->status === 'pending', 422, 'This request has already been actioned.');

        $offboardingRequest->update(['status' => 'cancelled']);
        $offboardingRequest->employee()->update(['status' => 'active']);

        return back()->with('success', $offboardingRequest->employee->name . '\'s offboarding request was declined.');
    }
}
