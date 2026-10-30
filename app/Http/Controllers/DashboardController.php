<?php

namespace App\Http\Controllers;

use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\SeparationType;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

        [$monthLabels, $initiatedSeries] = $this->buildMonthlySeries();

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
            ->get(['id', 'name', 'employee_code', 'department', 'designation', 'sup_one', 'head_employee_id']);

        // For the New Offboarding Request modal's per-request email
        // template overrides — every function's Select is populated from
        // this same active list, matching the `is_active` scope every
        // fixed-name lookup in `ChecklistApprovalNotifier` already uses.
        $activeEmailTemplates = EmailTemplate::where('is_active', true)
            ->orderBy('template_name')
            ->get(['id', 'template_name']);

        // For the New Offboarding Request modal's "Separation Type" picker
        // — see `SeparationTypeController`/`OffboardingRequestController::store()`.
        $separationTypes = SeparationType::orderBy('title')->get();

        $recentRequests = OffboardingRequest::with('employee', 'approvers', 'generalSignatoryApprovals')
            ->latest()
            ->take(6)
            ->get()
            ->map(fn (OffboardingRequest $request) => [
                'employee' => $request->employee->name,
                'department' => $request->employee->department,
                // Already a display-ready title, frozen at creation time —
                // see `OffboardingRequestController::store()`.
                'reason' => $request->reason,
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
            'monthLabels' => $monthLabels,
            'initiatedSeries' => $initiatedSeries,
            'departments' => $departments,
            'recentRequests' => $recentRequests,
            'employeesNotOffboarded' => $employeesNotOffboarded,
            'activeEmailTemplates' => $activeEmailTemplates,
            'separationTypes' => $separationTypes,
        ]);
    }

    /**
     * Dashboard Calendar card data: every offboarding request whose Last
     * Working Day falls inside the requested month's visible 6-week grid,
     * grouped by that date. Read live from `offboarding_requests` — no
     * separate calendar records — so an extended LWD simply moves to its new
     * date. Cancelled (incl. retracted) requests are excluded; completed
     * ones are kept but flagged, so the card never shows them as an active
     * schedule. Also returns the next few upcoming active LWDs from today.
     */
    public function calendar(Request $request): JsonResponse
    {
        $month = $request->query('month');
        $monthStart = is_string($month) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $month)
            ? Carbon::createFromFormat('Y-m-d', "{$month}-01")->startOfDay()
            : Carbon::today()->startOfMonth();

        // Same Sunday-first 6-week window the card renders.
        $gridStart = $monthStart->copy()->startOfWeek(Carbon::SUNDAY);
        $gridEnd = $gridStart->copy()->addDays(41);

        $toEvent = fn (OffboardingRequest $offboarding) => [
            'id' => $offboarding->id,
            'name' => $offboarding->employee?->name,
            'employeeCode' => $offboarding->employee?->employee_code,
            'department' => $offboarding->employee?->department,
            'position' => $offboarding->employee?->designation,
            'separationType' => $offboarding->separationType?->title ?? $offboarding->reason,
            'lastWorkingDay' => $offboarding->last_working_day->toDateString(),
            'lastWorkingDayLabel' => $offboarding->last_working_day->format('D, M d, Y'),
            'status' => $status = $offboarding->displayStatus(),
            'isActive' => $status !== 'completed',
        ];

        $baseQuery = fn () => OffboardingRequest::query()
            ->where('status', '!=', 'cancelled')
            ->whereHas('employee')
            ->with('employee', 'separationType', 'approvers', 'generalSignatoryApprovals');

        $days = $baseQuery()
            ->whereBetween('last_working_day', [$gridStart->toDateString(), $gridEnd->toDateString()])
            ->orderBy('last_working_day')
            ->get()
            ->map($toEvent)
            ->sortBy([['isActive', 'desc'], ['name', 'asc']])
            ->groupBy('lastWorkingDay')
            ->map(fn ($events) => $events->values());

        $upcoming = $baseQuery()
            ->where('status', '!=', 'completed')
            ->whereDate('last_working_day', '>=', Carbon::today())
            ->orderBy('last_working_day')
            ->take(5)
            ->get()
            ->map($toEvent)
            ->values();

        return response()->json([
            'month' => $monthStart->format('Y-m'),
            'monthLabel' => $monthStart->format('F Y'),
            'today' => Carbon::today()->toDateString(),
            'gridStart' => $gridStart->toDateString(),
            'days' => $days,
            'upcoming' => $upcoming,
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
