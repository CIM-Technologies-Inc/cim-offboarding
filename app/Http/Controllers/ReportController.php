<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\OffboardingRequest;
use App\Models\SeparationType;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Read-only reporting over the app's own existing offboarding data — every
 * figure here is derived from `OffboardingRequest`/`OffboardingRequestApprover`'s
 * own real columns (`status`, `due_at`, `completed_at`, `created_at`,
 * `notice_date`, `last_working_day`) and the same `displayPending()`/
 * `displayInProgress()`/`displayOverdue()` scopes and `isOverdue()` the
 * Dashboard and Approvals pages already rely on — nothing here is a second,
 * parallel source of truth for offboarding status.
 */
class ReportController extends Controller
{
    private const TYPES = ['processing_time', 'pending_cases', 'completion_rates'];

    public function index(Request $request): View
    {
        $filters = $this->normalizeFilters($request);

        $summary = $this->buildSummary($this->filteredQuery($filters));
        $rows = $this->buildRows($filters['type'], $this->filteredQuery($filters));

        return view('pages.reports.index', [
            'title' => 'Reports',
            'filters' => $filters,
            'summary' => $summary,
            'rows' => $rows,
            'columns' => $this->columnsFor($filters['type']),
            'typeLabel' => $this->typeLabel($filters['type']),
            'departments' => Employee::whereNotNull('department')->where('department', '!=', '')
                ->distinct()->orderBy('department')->pluck('department'),
            'separationTypes' => SeparationType::orderBy('title')->pluck('title'),
        ]);
    }

    public function export(Request $request)
    {
        $filters = $this->normalizeFilters($request);
        $rows = $this->buildRows($filters['type'], $this->filteredQuery($filters));
        $columns = $this->columnsFor($filters['type']);
        $format = strtolower((string) $request->query('format', 'csv'));
        $filename = 'offboarding-report-'.$filters['type'].'-'.now()->format('Ymd_His');

        return match ($format) {
            'xlsx' => $this->exportExcel($rows, $columns, $filename),
            'pdf' => $this->exportPdf($rows, $columns, $filters, $filename),
            default => $this->exportCsv($rows, $columns, $filename),
        };
    }

    /**
     * @return array{type: string, date_from: ?string, date_to: ?string, separation_type: ?string, department: ?string, status: ?string}
     */
    private function normalizeFilters(Request $request): array
    {
        $type = $request->query('type');

        return [
            'type' => in_array($type, self::TYPES, true) ? $type : 'processing_time',
            'date_from' => $request->query('date_from') ?: null,
            'date_to' => $request->query('date_to') ?: null,
            'separation_type' => $request->query('separation_type') ?: null,
            'department' => $request->query('department') ?: null,
            // pending | in_progress | overdue | completed | cancelled — the
            // same vocabulary `OffboardingRequest::displayStatus()` already
            // uses, so this filter always means exactly what the rest of
            // the app already means by that word.
            'status' => $request->query('status') ?: null,
        ];
    }

    /**
     * The shared base query every report type and the summary cards start
     * from — every filter here narrows the same underlying
     * `OffboardingRequest` rows the Offboardee/Approvals/Dashboard pages
     * already read, never a separate reporting table.
     */
    private function filteredQuery(array $filters): Builder
    {
        $query = OffboardingRequest::query()
            ->whereHas('employee')
            ->with(['employee', 'approvers.checklistTemplate', 'approvers.employee']);

        if ($filters['date_from']) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if ($filters['date_to']) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if ($filters['separation_type']) {
            // `reason` — the Separation Type's title, frozen onto the
            // request at creation time (see `SeparationType`'s own
            // docblock), so this still matches historical requests even if
            // that Separation Type config row is later renamed or deleted.
            $query->where('reason', $filters['separation_type']);
        }

        if ($filters['department']) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('department', $filters['department']));
        }

        match ($filters['status']) {
            'pending' => $query->displayPending(),
            'in_progress' => $query->displayInProgress(),
            'overdue' => $query->displayOverdue(),
            'completed' => $query->where('status', 'completed'),
            'cancelled' => $query->where('status', 'cancelled'),
            default => null,
        };

        return $query;
    }

    private function buildRows(string $type, Builder $query): Collection
    {
        return match ($type) {
            'pending_cases' => $this->buildPendingCasesRows($query),
            'completion_rates' => $this->buildCompletionRatesRows($query),
            default => $this->buildProcessingTimeRows($query),
        };
    }

    /**
     * One row per offboarding case, showing how long it has taken (or is
     * currently taking) to complete — processing time is always measured
     * from the request's own `created_at` (when it was submitted) to
     * `completed_at` (set the instant `ChecklistCompletionService` marks it
     * `completed` — see that service's own docblocks), never from the
     * Resignation Date or Last Working Day, which are shown as their own
     * separate columns instead. A still-open case shows its running total
     * so far (measured against "now"), clearly labeled as such rather than
     * presented as a final figure.
     */
    private function buildProcessingTimeRows(Builder $query): Collection
    {
        return $query->get()->map(function (OffboardingRequest $r) {
            $completedAt = $r->completed_at;
            // abs() + (int): this Carbon version's diffInDays() returns a
            // SIGNED, fractional-day float (negative when the earlier date
            // calls diffInDays() on a later one in some argument orders) —
            // always want a plain whole-day count here, regardless of call
            // order.
            $processingDays = (int) abs($r->created_at->diffInDays($completedAt ?? now()));

            return [
                'employee_name' => $r->employee->name,
                'employee_code' => $r->employee->employee_code,
                'department' => $r->employee->department,
                'separation_type' => $r->reason,
                'request_date' => $r->created_at->format('M d, Y'),
                'resignation_date' => $r->notice_date?->format('M d, Y') ?? '—',
                'last_working_day' => $r->last_working_day->format('M d, Y'),
                'completion_date' => $completedAt?->format('M d, Y') ?? '—',
                'processing_time_label' => $completedAt
                    ? $processingDays.' day(s)'
                    : $processingDays.' day(s) so far',
                'status' => $this->statusLabel($r->displayStatus()),
            ];
        });
    }

    /**
     * One row per still-open offboarding case — "pending stage" and
     * "assigned approver" are comma-joined across every checklist STILL
     * outstanding on that request (a case can have several running at
     * once), and "Due Date" is the earliest due date among them, i.e. the
     * next thing actually blocking this case. "Days Pending" counts from
     * the request's own creation, and "Overdue" reuses
     * `OffboardingRequest::isOverdue()` verbatim rather than re-deriving it.
     */
    private function buildPendingCasesRows(Builder $query): Collection
    {
        return $query->whereIn('status', ['pending', 'in_progress'])
            ->get()
            ->map(function (OffboardingRequest $r) {
                $outstanding = $r->approvers->reject(fn ($a) => in_array($a->status, ['approved', 'declined'], true));

                $pendingStage = $outstanding->pluck('checklistTemplate.title')->filter()->unique()->implode(', ');
                $assignedApprover = $outstanding->pluck('employee.name')->filter()->unique()->implode(', ');
                $earliestDue = $outstanding->pluck('due_at')->filter()->sort()->first();

                return [
                    'employee_name' => $r->employee->name,
                    'employee_code' => $r->employee->employee_code,
                    'department' => $r->employee->department,
                    'separation_type' => $r->reason,
                    'request_date' => $r->created_at->format('M d, Y'),
                    'last_working_day' => $r->last_working_day->format('M d, Y'),
                    'status' => $this->statusLabel($r->displayStatus()),
                    'pending_stage' => $pendingStage ?: '—',
                    'assigned_approver' => $assignedApprover ?: '—',
                    'due_date' => $earliestDue?->format('M d, Y') ?? '—',
                    // abs() because Carbon's diffInDays() is signed here
                    // (now()->diffInDays($pastDate) returns a NEGATIVE
                    // number in this Carbon version) — always a plain count
                    // of days elapsed, regardless of call-order sign.
                    'days_pending' => (int) abs(now()->diffInDays($r->created_at)),
                    'is_overdue' => $r->isOverdue() ? 'Yes' : 'No',
                ];
            });
    }

    /**
     * One row per Separation Type (the frozen `reason` string, not the
     * live/possibly-deleted `SeparationType` row), grouping every request
     * matching the current filters. Cancelled/declined requests are
     * excluded entirely — a cancelled case was never really "in" or
     * "completed by" the offboarding process this report tracks. The three
     * count columns are mutually exclusive and sum to the total, same as
     * the Dashboard's own pending/in_progress/overdue/completed split.
     */
    private function buildCompletionRatesRows(Builder $query): Collection
    {
        $requests = $query->where('status', '!=', 'cancelled')->get();

        return $requests->groupBy('reason')
            ->map(fn (Collection $group, string $reason) => ['separation_type' => $reason] + $this->computeMetrics($group))
            ->values();
    }

    /**
     * Shared by the summary cards (over the whole filtered set) and
     * `buildCompletionRatesRows()` (per separation-type group) so both
     * always agree on exactly what counts as completed/overdue/pending and
     * how average processing time is computed.
     *
     * @param  Collection<int, OffboardingRequest>  $requests
     * @return array{total: int, completed: int, pending_in_progress: int, overdue: int, completion_rate: float, avg_processing_days: ?float}
     */
    private function computeMetrics(Collection $requests): array
    {
        $completed = $requests->where('status', 'completed');
        $completedCount = $completed->count();
        $overdueCount = $requests->filter(fn (OffboardingRequest $r) => $r->isOverdue())->count();
        $pendingInProgressCount = $requests->filter(
            fn (OffboardingRequest $r) => in_array($r->status, ['pending', 'in_progress'], true) && ! $r->isOverdue()
        )->count();
        $total = $completedCount + $overdueCount + $pendingInProgressCount;

        $avgProcessingDays = $completedCount > 0
            ? round($completed->avg(fn (OffboardingRequest $r) => (int) abs($r->created_at->diffInDays($r->completed_at))), 1)
            : null;

        return [
            'total' => $total,
            'completed' => $completedCount,
            'pending_in_progress' => $pendingInProgressCount,
            'overdue' => $overdueCount,
            'completion_rate' => $total > 0 ? round($completedCount / $total * 100, 1) : 0.0,
            'avg_processing_days' => $avgProcessingDays,
        ];
    }

    private function buildSummary(Builder $query): array
    {
        return $this->computeMetrics($query->where('status', '!=', 'cancelled')->get());
    }

    /**
     * @return array<string, string> column key => display header, in
     *                                display/export order — the single
     *                                source of truth for the on-screen
     *                                table AND every export format, so all
     *                                three can never drift out of sync.
     */
    private function columnsFor(string $type): array
    {
        return match ($type) {
            'pending_cases' => [
                'employee_name' => 'Employee Name',
                'employee_code' => 'Employee No.',
                'department' => 'Department',
                'separation_type' => 'Separation Type',
                'request_date' => 'Request Date',
                'last_working_day' => 'Last Working Day',
                'status' => 'Current Status',
                'pending_stage' => 'Pending Stage / Checklist',
                'assigned_approver' => 'Assigned Approver',
                'due_date' => 'Due Date',
                'days_pending' => 'Days Pending',
                'is_overdue' => 'Overdue',
            ],
            'completion_rates' => [
                'separation_type' => 'Separation Type',
                'total' => 'Total Cases',
                'completed' => 'Completed',
                'pending_in_progress' => 'Pending / In Progress',
                'overdue' => 'Overdue',
                'completion_rate' => 'Completion Rate (%)',
                'avg_processing_days' => 'Avg. Processing Time (days)',
            ],
            default => [
                'employee_name' => 'Employee Name',
                'employee_code' => 'Employee No.',
                'department' => 'Department',
                'separation_type' => 'Separation Type',
                'request_date' => 'Request Date',
                'resignation_date' => 'Resignation Date',
                'last_working_day' => 'Last Working Day',
                'completion_date' => 'Completion Date',
                'processing_time_label' => 'Total Processing Time',
                'status' => 'Current Status',
            ],
        };
    }

    private function typeLabel(string $type): string
    {
        return match ($type) {
            'pending_cases' => 'Pending Offboarding Cases',
            'completion_rates' => 'Completion Rates by Separation Type',
            default => 'Offboarding Processing Time',
        };
    }

    private function statusLabel(string $status): string
    {
        return ucwords(str_replace('_', ' ', $status));
    }

    private function exportCsv(Collection $rows, array $columns, string $filename)
    {
        return response()->streamDownload(function () use ($rows, $columns) {
            $out = fopen('php://output', 'w');
            fputcsv($out, array_values($columns));

            foreach ($rows as $row) {
                fputcsv($out, array_map(fn ($key) => $row[$key] ?? '', array_keys($columns)));
            }

            fclose($out);
        }, $filename.'.csv', ['Content-Type' => 'text/csv']);
    }

    private function exportExcel(Collection $rows, array $columns, string $filename)
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();

        $sheet->fromArray(array_values($columns), null, 'A1');
        $sheet->getStyle('A1:'.$sheet->getHighestColumn().'1')->getFont()->setBold(true);

        $rowIndex = 2;
        foreach ($rows as $row) {
            $sheet->fromArray(array_map(fn ($key) => $row[$key] ?? '', array_keys($columns)), null, 'A'.$rowIndex);
            $rowIndex++;
        }

        foreach (range('A', $sheet->getHighestColumn()) as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        return response()->streamDownload(function () use ($spreadsheet) {
            (new Xlsx($spreadsheet))->save('php://output');
        }, $filename.'.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    private function exportPdf(Collection $rows, array $columns, array $filters, string $filename)
    {
        $pdf = Pdf::loadView('pages.reports.pdf', [
            'rows' => $rows,
            'columns' => $columns,
            'title' => $this->typeLabel($filters['type']),
            'generatedAt' => now()->format('M d, Y g:i A'),
        ])->setPaper('a4', 'landscape');

        return $pdf->stream($filename.'.pdf');
    }
}
