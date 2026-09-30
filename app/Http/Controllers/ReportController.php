<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Models\SeparationType;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
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

    /**
     * Valid `group_by` values for the Processing Time report only — meaningless
     * (ignored) for the other two report types. `none` is the original,
     * ungrouped, one-row-per-case view.
     */
    private const GROUP_BY_OPTIONS = ['none', 'offboardee', 'department', 'checklist', 'separation_type'];

    public function index(Request $request): View
    {
        $filters = $this->normalizeFilters($request);

        $summary = $this->buildSummary($this->filteredQuery($filters));
        $rows = $this->buildRows($filters, $this->filteredQuery($filters));
        $isGrouped = $filters['type'] === 'processing_time' && $filters['group_by'] !== 'none';

        return view('pages.reports.index', [
            'title' => 'Reports',
            'filters' => $filters,
            'summary' => $summary,
            'rows' => $rows,
            'columns' => $isGrouped ? $this->columnsForGrouped($filters['group_by']) : $this->columnsFor($filters['type']),
            'typeLabel' => $this->typeLabel($filters['type']),
            'departments' => Employee::whereNotNull('department')->where('department', '!=', '')
                ->distinct()->orderBy('department')->pluck('department'),
            'separationTypes' => SeparationType::orderBy('title')->pluck('title'),
            'checklistTitles' => ChecklistTemplate::orderBy('title')->pluck('title'),
            // Every employee who has ever been a checklist Clearance
            // Signatory/Department Head (`OffboardingRequestApprover.employee_id`)
            // OR a General Signatory's own Clearance Signatory
            // (`GeneralSignatory.clearance_signatory_id`) — the same two
            // sources `filteredQuery()`'s own `clearance_signatory` filter
            // below checks, deduped naturally through the `whereIn`.
            'clearanceSignatories' => Employee::whereIn('id', OffboardingRequestApprover::whereNotNull('employee_id')
                ->distinct()->pluck('employee_id')->merge(GeneralSignatory::distinct()->pluck('clearance_signatory_id')))
                ->orderBy('name')->get(['id', 'name']),
        ]);
    }

    public function export(Request $request)
    {
        $filters = $this->normalizeFilters($request);
        $rows = $this->buildRows($filters, $this->filteredQuery($filters));
        $isGrouped = $filters['type'] === 'processing_time' && $filters['group_by'] !== 'none';
        $columns = $isGrouped ? $this->columnsForGrouped($filters['group_by']) : $this->columnsFor($filters['type']);
        $format = strtolower((string) $request->query('format', 'csv'));
        $filename = 'offboarding-report-'.$filters['type'].'-'.now()->format('Ymd_His');

        return match ($format) {
            'xlsx' => $this->exportExcel($rows, $columns, $filename),
            'pdf' => $this->exportPdf($rows, $columns, $filters, $filename),
            default => $this->exportCsv($rows, $columns, $filename),
        };
    }

    /**
     * @return array{type: string, date_from: ?string, date_to: ?string, separation_type: ?string, department: ?string, status: ?string, checklist_title: ?string, clearance_signatory: ?string, offboardee: ?string, employee_number: ?string, group_by: string}
     */
    private function normalizeFilters(Request $request): array
    {
        $type = $request->query('type');
        $groupBy = $request->query('group_by');

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
            'checklist_title' => $request->query('checklist_title') ?: null,
            // The clearance signatory/approver's own Employee id — a select
            // populated from `$clearanceSignatories` below, never a free-text
            // name (which could match several people).
            'clearance_signatory' => $request->query('clearance_signatory') ?: null,
            'offboardee' => $request->query('offboardee') ?: null,
            'employee_number' => $request->query('employee_number') ?: null,
            'group_by' => in_array($groupBy, self::GROUP_BY_OPTIONS, true) ? $groupBy : 'none',
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
            ->with([
                'employee', 'approvers.checklistTemplate', 'approvers.employee', 'finalApproval',
                // Constrained to the one milestone this report actually
                // reads (`checklist_completion_date` below) — never pulls
                // every view/reminder/assignment activity row per request
                // just to find it.
                'activities' => fn ($q) => $q->where('action', 'all_checklists_approved'),
                'generalSignatoryApprovals.generalSignatory.clearanceSignatory',
            ]);

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

        if ($filters['checklist_title']) {
            $query->whereHas(
                'approvers.checklistTemplate',
                fn (Builder $q) => $q->where('title', $filters['checklist_title'])
            );
        }

        if ($filters['clearance_signatory']) {
            // Either kind of clearance signatory this app has — a checklist
            // Department Head/Clearance Signatory (`OffboardingRequestApprover.employee_id`)
            // or a General Signatory's own Clearance Signatory — same two
            // sources `index()`'s own `$clearanceSignatories` dropdown is
            // built from.
            $query->where(fn (Builder $q) => $q
                ->whereHas('approvers.employee', fn (Builder $q2) => $q2->where('employees.id', $filters['clearance_signatory']))
                ->orWhereHas(
                    'generalSignatoryApprovals.generalSignatory.clearanceSignatory',
                    fn (Builder $q2) => $q2->where('employees.id', $filters['clearance_signatory'])
                ));
        }

        if ($filters['offboardee']) {
            $query->whereHas('employee', fn (Builder $q) => $q->where('name', 'like', '%'.$filters['offboardee'].'%'));
        }

        if ($filters['employee_number']) {
            $query->whereHas(
                'employee',
                fn (Builder $q) => $q->where('employee_code', 'like', '%'.$filters['employee_number'].'%')
            );
        }

        return $query;
    }

    private function buildRows(array $filters, Builder $query): Collection
    {
        if ($filters['type'] === 'processing_time' && $filters['group_by'] !== 'none') {
            return $filters['group_by'] === 'checklist'
                ? $this->buildProcessingTimeByChecklist($query)
                : $this->buildProcessingTimeGroupedRows($query, $filters['group_by']);
        }

        return match ($filters['type']) {
            'pending_cases' => $this->buildPendingCasesRows($query),
            'completion_rates' => $this->buildCompletionRatesRows($query),
            default => $this->buildProcessingTimeRows($query),
        };
    }

    /**
     * Processing Time grouped by offboardee, department, or separation
     * type — each group still uses the same whole-request `created_at` ->
     * `completed_at` span `buildProcessingTimeRows()`/`computeMetrics()`
     * already use, just aggregated (count/avg/min/max) instead of shown per
     * case. "By offboardee" is a group of (usually) one, included for
     * completeness/consistency with the other three dimensions rather than
     * as a genuinely different code path.
     */
    private function buildProcessingTimeGroupedRows(Builder $query, string $groupBy): Collection
    {
        $requests = $query->get();

        $grouped = match ($groupBy) {
            'offboardee' => $requests->groupBy(fn (OffboardingRequest $r) => $r->employee->name.' ('.$r->employee->employee_code.')'),
            'department' => $requests->groupBy(fn (OffboardingRequest $r) => $r->employee->department ?: 'Unassigned'),
            default => $requests->groupBy('reason'),
        };

        return $grouped
            ->map(fn (Collection $group, string $label) => ['group_label' => $label] + $this->computeMetrics($group))
            ->values();
    }

    /**
     * Processing Time grouped by Checklist Title — a fundamentally
     * different metric from the other three grouping dimensions above: a
     * whole-REQUEST creation-to-completion span is meaningless once you're
     * asking "how long does THIS checklist take", since a request can carry
     * several checklists resolved at very different times. Sourced from
     * `OffboardingRequestApprover` directly (each row's own `assigned_at` ->
     * `approved_at` span), scoped to the same set of requests the current
     * filters already narrowed down to, and only rows that have actually
     * been approved (a still-open checklist has no completed span to
     * measure yet).
     */
    private function buildProcessingTimeByChecklist(Builder $query): Collection
    {
        $requestIds = $query->pluck('id');

        return OffboardingRequestApprover::whereIn('offboarding_request_id', $requestIds)
            ->whereNotNull('approved_at')
            ->with('checklistTemplate')
            ->get()
            ->groupBy(fn (OffboardingRequestApprover $a) => $a->checklistTemplate?->title ?? 'Untitled Checklist')
            ->map(function (Collection $group, string $title) {
                $hours = $group->map(fn (OffboardingRequestApprover $a) => (int) abs($a->assigned_at->diffInHours($a->approved_at)));

                return [
                    'checklist_title' => $title,
                    'count' => $group->count(),
                    'avg_processing_time' => $this->hoursLabel((int) round($hours->avg())),
                    'min_processing_time' => $this->hoursLabel($hours->min()),
                    'max_processing_time' => $this->hoursLabel($hours->max()),
                ];
            })
            ->values();
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
            $duration = $this->formatDuration($r->created_at, $completedAt ?? now());

            $outstanding = $r->approvers->reject(fn ($a) => $a->status === 'approved');
            $checklistDueDate = $outstanding->pluck('due_at')->filter()->sort()->first();

            return [
                'employee_name' => $r->employee->name,
                'employee_code' => $r->employee->employee_code,
                'department' => $r->employee->department,
                'separation_type' => $r->reason,
                // Labeled "Clearance Start Date" in `columnsFor()` — the
                // moment a request is created is also the moment checklists
                // get attached and the clearance process actually begins
                // (see `OffboardingRequestController::store()`), so this
                // single value serves both concepts; kept under its
                // original key since nothing outside this report reads it.
                'request_date' => $r->created_at->format('M d, Y'),
                'resignation_date' => $r->notice_date?->format('M d, Y') ?? '—',
                'last_working_day' => $r->last_working_day->format('M d, Y'),
                'checklist_due_date' => $checklistDueDate?->format('M d, Y') ?? '—',
                'checklist_completion_date' => $r->activities->firstWhere('action', 'all_checklists_approved')?->created_at?->format('M d, Y') ?? '—',
                'final_approval_date' => $r->finalApproval?->approved_at?->format('M d, Y') ?? '—',
                'completion_date' => $completedAt?->format('M d, Y') ?? '—',
                'processing_time_label' => $completedAt
                    ? $duration['label']
                    : $duration['label'].' so far',
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
                $outstanding = $r->approvers->reject(fn ($a) => $a->status === 'approved');

                // General Signatories are a separate, checklist-independent
                // approval track (see `GeneralSignatory`'s own docblock) —
                // folded in here additively so a request with one attached
                // still-pending shows up as "assigned" to them too, not just
                // to its checklist approvers. Degrades to exactly today's
                // checklist-only behavior for any request with none.
                $outstandingGs = $r->generalSignatoryApprovals->reject(fn ($g) => $g->status === 'approved');
                $gsNames = $outstandingGs->pluck('generalSignatory.clearanceSignatory.name')->filter()->unique();

                $pendingStage = $outstanding->pluck('checklistTemplate.title')->filter()->unique()->implode(', ');
                $assignedApprover = $outstanding->pluck('employee.name')->filter()->unique()->merge($gsNames)->unique()->implode(', ');
                $earliestDue = $outstanding->pluck('due_at')->filter()->sort()->first();

                $approvedCount = $r->approvers->where('status', 'approved')->count();
                $totalCount = $r->approvers->count();

                return [
                    'employee_name' => $r->employee->name,
                    'employee_code' => $r->employee->employee_code,
                    'department' => $r->employee->department,
                    'separation_type' => $r->reason,
                    'request_date' => $r->created_at->format('M d, Y'),
                    'last_working_day' => $r->last_working_day->format('M d, Y'),
                    'status' => $this->statusLabel($r->displayStatus()),
                    'pending_stage' => $pendingStage ?: '—',
                    'checklist_status' => "{$approvedCount}/{$totalCount} cleared",
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
     * Shared by the summary cards (over the whole filtered set),
     * `buildCompletionRatesRows()` (per separation-type group), and
     * `buildProcessingTimeGroupedRows()` (per offboardee/department/
     * separation-type group) so all three always agree on exactly what
     * counts as completed/overdue/pending and how processing time is
     * computed — average, minimum, AND maximum, all derived from the same
     * completed subset's `created_at` -> `completed_at` span.
     *
     * @param  Collection<int, OffboardingRequest>  $requests
     * @return array{total: int, completed: int, pending_in_progress: int, overdue: int, completion_rate: float, avg_processing_days: ?float, min_processing_hours: ?int, max_processing_hours: ?int, avg_processing_time: string, min_processing_time: string, max_processing_time: string}
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

        // abs() + (int): this Carbon version's diffInDays()/diffInHours()
        // return a SIGNED, fractional value (negative depending on
        // call-order in some cases) — always want a plain whole count here.
        $completedHours = $completed->map(fn (OffboardingRequest $r) => (int) abs($r->created_at->diffInHours($r->completed_at)));

        $avgProcessingDays = $completedCount > 0
            ? round($completed->avg(fn (OffboardingRequest $r) => (int) abs($r->created_at->diffInDays($r->completed_at))), 1)
            : null;
        $minHours = $completedCount > 0 ? $completedHours->min() : null;
        $maxHours = $completedCount > 0 ? $completedHours->max() : null;
        $avgHours = $completedCount > 0 ? (int) round($completedHours->avg()) : null;

        return [
            'total' => $total,
            'completed' => $completedCount,
            'pending_in_progress' => $pendingInProgressCount,
            'overdue' => $overdueCount,
            'completion_rate' => $total > 0 ? round($completedCount / $total * 100, 1) : 0.0,
            'avg_processing_days' => $avgProcessingDays,
            'min_processing_hours' => $minHours,
            'max_processing_hours' => $maxHours,
            'avg_processing_time' => $this->hoursLabel($avgHours),
            'min_processing_time' => $this->hoursLabel($minHours),
            'max_processing_time' => $this->hoursLabel($maxHours),
        ];
    }

    /**
     * The single day/hour-splitting formatter every processing-time figure
     * in this controller renders through — a plain integer hour count in,
     * an "Xd Yh" (or "—" when there's nothing to show yet) label out.
     */
    private function hoursLabel(?int $totalHours): string
    {
        if ($totalHours === null) {
            return '—';
        }

        $days = intdiv($totalHours, 24);
        $hours = $totalHours % 24;

        return "{$days}d {$hours}h";
    }

    /**
     * @return array{hours: int, label: string}
     */
    private function formatDuration(Carbon $from, Carbon $to): array
    {
        $totalHours = (int) abs($from->diffInHours($to));

        return ['hours' => $totalHours, 'label' => $this->hoursLabel($totalHours)];
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
                'checklist_status' => 'Checklist Status',
                'assigned_approver' => 'Assigned Signatory / Approver',
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
                // Same underlying value as when checklists get attached and
                // the clearance process actually begins — see this key's
                // own docblock in `buildProcessingTimeRows()`.
                'request_date' => 'Clearance Start Date',
                'resignation_date' => 'Resignation Date',
                'last_working_day' => 'Last Working Day',
                'checklist_due_date' => 'Checklist Due Date',
                'checklist_completion_date' => 'Checklist Completion Date',
                'final_approval_date' => 'Final Approval Date',
                'completion_date' => 'Offboarding Completion Date',
                'processing_time_label' => 'Total Processing Time',
                'status' => 'Current Status',
            ],
        };
    }

    /**
     * Column set for the Processing Time report's grouped views (`group_by`
     * != 'none') — a completely different shape from `columnsFor()`'s
     * per-case rows, since each row here is now an aggregate over several
     * cases. Checklist grouping gets its own shape (`checklist_title` +
     * `count`, no `total`/`completed` split — see
     * `buildProcessingTimeByChecklist()`'s own docblock for why it isn't
     * measuring "completion" the way a whole-request span does).
     */
    private function columnsForGrouped(string $groupBy): array
    {
        if ($groupBy === 'checklist') {
            return [
                'checklist_title' => 'Checklist Title',
                'count' => 'Approved Count',
                'avg_processing_time' => 'Avg. Processing Time',
                'min_processing_time' => 'Min. Processing Time',
                'max_processing_time' => 'Max. Processing Time',
            ];
        }

        return [
            'group_label' => match ($groupBy) {
                'department' => 'Department',
                'separation_type' => 'Separation Type',
                default => 'Offboardee',
            },
            'total' => 'Total Cases',
            'completed' => 'Completed',
            'avg_processing_time' => 'Avg. Processing Time',
            'min_processing_time' => 'Min. Processing Time',
            'max_processing_time' => 'Max. Processing Time',
        ];
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
