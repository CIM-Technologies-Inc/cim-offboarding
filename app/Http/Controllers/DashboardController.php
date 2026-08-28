<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use Illuminate\Support\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        // Excludes only employees who have fully completed offboarding
        // (`status = 'offboarded'`, set once the whole process finishes —
        // see `ChecklistCompletionService::checkFinalPayCompletion()`).
        // An employee still mid-offboarding (`status = 'offboarding'`)
        // keeps counting here — they're still on the books until the
        // process is genuinely done, not merely started.
        $totalEmployees = Employee::where('status', '!=', 'offboarded')->count();
        $activeEmployees = Employee::where('status', 'active')->count();

        $pendingCount = OffboardingRequest::displayPending()->count();
        $inProgressCount = OffboardingRequest::displayInProgress()->count();
        $overdueCount = OffboardingRequest::displayOverdue()->count();
        $completedCount = OffboardingRequest::where('status', 'completed')->count();

        $startOfMonth = Carbon::now()->startOfMonth();

        $completedThisMonth = OffboardingRequest::where('status', 'completed')
            ->whereBetween('completed_at', [$startOfMonth, Carbon::now()])
            ->count();

        $dueThisMonth = OffboardingRequest::whereBetween('created_at', [$startOfMonth, Carbon::now()])->count();

        $completionRate = $dueThisMonth > 0 ? (int) round($completedThisMonth / $dueThisMonth * 100) : 0;

        [$monthLabels, $initiatedSeries, $completedSeries] = $this->buildMonthlySeries();

        $departmentBreakdown = Employee::whereHas('offboardingRequests')
            ->selectRaw('department, count(*) as total')
            ->groupBy('department')
            ->orderByDesc('total')
            ->get();

        $departmentTotal = $departmentBreakdown->sum('total');

        $departments = $departmentBreakdown->map(fn ($row) => [
            'name' => $row->department,
            'count' => $row->total,
            'percentage' => $departmentTotal > 0 ? (int) round($row->total / $departmentTotal * 100) : 0,
        ])->values()->all();

        $employeesNotOffboarded = Employee::where('status', 'active')
            ->orderBy('name')
            ->get(['id', 'name', 'employee_code', 'department', 'sup_one']);

        // For the New Offboarding Request modal's per-request email
        // template overrides — every function's Select is populated from
        // this same active list, matching the `is_active` scope every
        // fixed-name lookup in `ChecklistApprovalNotifier` already uses.
        $activeEmailTemplates = EmailTemplate::where('is_active', true)
            ->orderBy('template_name')
            ->get(['id', 'template_name']);

        $recentRequests = OffboardingRequest::with('employee', 'approvers')
            ->latest()
            ->take(6)
            ->get()
            ->map(fn (OffboardingRequest $request) => [
                'employee' => $request->employee->name,
                'department' => $request->employee->department,
                'reason' => ucfirst(str_replace('_', ' ', $request->reason)),
                'last_working_day' => $request->last_working_day->format('M d, Y'),
                'status' => $request->displayStatus(),
            ])->all();

        return view('pages.dashboard.offboarding', [
            'title' => 'Offboarding Dashboard',
            'totalEmployees' => $totalEmployees,
            'activeEmployees' => $activeEmployees,
            'pendingCount' => $pendingCount,
            'inProgressCount' => $inProgressCount,
            'overdueCount' => $overdueCount,
            'completedCount' => $completedCount,
            'completionRate' => $completionRate,
            'completedThisMonth' => $completedThisMonth,
            'dueThisMonth' => $dueThisMonth,
            'monthLabels' => $monthLabels,
            'initiatedSeries' => $initiatedSeries,
            'completedSeries' => $completedSeries,
            'departments' => $departments,
            'recentRequests' => $recentRequests,
            'employeesNotOffboarded' => $employeesNotOffboarded,
            'activeEmailTemplates' => $activeEmailTemplates,
        ]);
    }

    private function buildMonthlySeries(): array
    {
        $rangeStart = Carbon::now()->subMonths(11)->startOfMonth();

        $initiatedByMonth = OffboardingRequest::selectRaw("DATE_FORMAT(created_at, '%Y-%m') as ym, count(*) as total")
            ->where('created_at', '>=', $rangeStart)
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $completedByMonth = OffboardingRequest::selectRaw("DATE_FORMAT(completed_at, '%Y-%m') as ym, count(*) as total")
            ->whereNotNull('completed_at')
            ->where('completed_at', '>=', $rangeStart)
            ->groupBy('ym')
            ->pluck('total', 'ym');

        $monthLabels = [];
        $initiatedSeries = [];
        $completedSeries = [];

        for ($i = 11; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i)->startOfMonth();
            $key = $month->format('Y-m');

            $monthLabels[] = $month->format('M');
            $initiatedSeries[] = (int) ($initiatedByMonth[$key] ?? 0);
            $completedSeries[] = (int) ($completedByMonth[$key] ?? 0);
        }

        return [$monthLabels, $initiatedSeries, $completedSeries];
    }
}
