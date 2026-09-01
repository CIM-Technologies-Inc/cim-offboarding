<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\View\View;

class ClearanceFormController extends Controller
{
    /**
     * Streams the clearance form as a downloadable/viewable PDF — this is
     * the "Generate Clearance Form" action, producing the actual document.
     */
    public function pdf(OffboardingRequest $offboardingRequest): Response
    {
        $data = $this->buildData($offboardingRequest) + [
            'headerImageSrc' => $this->localImageDataUri(public_path('images/clearance-form/header.png')),
            'footerImageSrc' => $this->localImageDataUri(public_path('images/clearance-form/footer.png')),
        ];

        $pdf = Pdf::loadView('clearance-form.pdf', $data)->setPaper('letter');

        return $pdf->stream('Clearance Form - '.$data['employeeName'].'.pdf');
    }

    /**
     * A plain HTML page styled for print and set to auto-open the browser's
     * print dialog — the "Print Clearance Form" action. Kept separate from
     * the PDF route since browsers' built-in PDF viewers don't reliably
     * auto-trigger printing, while a real page's `window.print()` always does.
     */
    public function print(OffboardingRequest $offboardingRequest): View
    {
        $data = $this->buildData($offboardingRequest) + [
            'headerImageSrc' => asset('images/clearance-form/header.png'),
            'footerImageSrc' => asset('images/clearance-form/footer.png'),
        ];

        return view('clearance-form.print', $data);
    }

    /**
     * Gathers everything the clearance form template needs. Available at
     * any point in the offboarding process — pending, in progress, on hold,
     * overdue, or completed — since the form exists precisely so Admin/HR
     * can track live progress, not only inspect a finished result. Each
     * row's signature/date/remarks reflect that checklist's own real,
     * current state (`OffboardingRequestApprover::clearanceStatusLabel()`),
     * never a fabricated "cleared" — a signature only ever appears once
     * that department head has genuinely approved.
     */
    private function buildData(OffboardingRequest $offboardingRequest): array
    {
        $offboardingRequest->loadMissing(['employee', 'immediateHead.user', 'checklistTemplates', 'approvers.employee.user', 'approvers.itemProgress', 'approvers.checklistTemplate', 'generalSignatories.clearanceSignatory.user', 'generalSignatoryApprovals']);

        // Logged once (guarded below) so the employee's own timeline can
        // show when their clearance form was first generated — every later
        // regeneration (viewing again, re-printing) is a no-op here.
        if (! $offboardingRequest->activities()->where('action', 'clearance_generated')->exists()) {
            $offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'action' => 'clearance_generated',
                'status' => $offboardingRequest->status,
            ]);
        }

        $employee = $offboardingRequest->employee;
        $immediateHead = $offboardingRequest->immediateHead;

        // The Immediate Head's own approval state is sourced from EVERY
        // `OffboardingRequestApprover` row on this request assigned to
        // them — their own "Immediate Head checklist" row included. That
        // row (e.g. the "Department Head" template, `is_immediate_head_checklist
        // = true`) is the real, authoritative record of whether they've
        // actually cleared THIS request; it must never be filtered out
        // just because it's the same checklist kind this row is standing
        // in for — doing so previously left this row permanently showing
        // "no checklist of their own" (unconditional signature, no
        // date/remarks) even once they'd genuinely approved it, since
        // nothing else in `$rows` below ever surfaces that same row either
        // (it's deliberately excluded from the main table there too, to
        // avoid a second, redundant entry).
        //
        // When this SAME person is ALSO the assigned Checklist Clearance
        // Signatory of an ordinary department checklist on this request
        // (matched purely by employee — e.g. the Information Services
        // department head also happens to be this offboardee's Immediate
        // Head), that row is included here too, so approving either one
        // via their already-merged combined Approvals-page card (see
        // `ApprovalController::groupIntoCombinedApprovals()`) is reflected
        // identically here. Every row is required to be approved before
        // this row shows as cleared — see the three helpers below — so a
        // person responsible for more than one checklist here only reads
        // as cleared once ALL of them are.
        //
        // Only when NO row at all is assigned to this employee (they hold
        // no checklist/approval step here whatsoever) does this stay
        // empty, and the row below falls back to an unconditional
        // signature-if-uploaded with no date/remarks.
        $immediateHeadSyncedApprovers = $immediateHead
            ? $offboardingRequest->approvers->filter(
                fn (OffboardingRequestApprover $approver) => $approver->employee_id === $immediateHead->id
            )
            : collect();

        $immediateHeadRow = $immediateHead ? [
            'designation' => 'Immediate Head',
            'signatory' => $immediateHead->name,
            'signatureDataUri' => $this->immediateHeadSignatureDataUri($immediateHead, $immediateHeadSyncedApprovers),
            'date' => $this->immediateHeadApprovedDate($immediateHeadSyncedApprovers),
            'remarks' => $this->immediateHeadRemarks($immediateHeadSyncedApprovers),
        ] : null;

        // Built as "raw" entries first (one per checklist a signatory is
        // responsible for) rather than final formatted rows, so a signatory
        // assigned to SEVERAL checklists on this request (e.g. the same
        // Clearance Signatory heads both the HR and Admin checklists) can be
        // merged into exactly one row below, instead of showing once per
        // checklist. See `dedupeSignatoryEntries()`.
        $signatoryEntries = $offboardingRequest->checklistTemplates
            ->where('is_active', true)
            // The Immediate Head checklist is already shown once, above this
            // table, via `$immediateHeadRow` — it has no `department` of its
            // own, so leaving it in here would fall back to displaying the
            // signatory's own personal department (e.g. "IT"), producing a
            // confusing second row that looks like a duplicate of that
            // department's real row.
            ->reject(fn (ChecklistTemplate $template) => $template->is_immediate_head_checklist)
            ->flatMap(function (ChecklistTemplate $template) use ($offboardingRequest) {
                $approver = $offboardingRequest->approvers->firstWhere('checklist_template_id', $template->id);

                // "Use Task Assignee as Clearance Signatory": this checklist
                // has no single owner at all — every distinct employee
                // actually assigned to at least one item IS a signatory,
                // each contributing their own entry below, responsible only
                // for their own item(s).
                if ($template->use_task_assignee_as_signatory) {
                    return $this->taskAssigneeSignatoryEntries($template, $approver);
                }

                $signatoryEmployee = $approver?->employee;
                $isApproved = $approver?->status === 'approved';

                return [[
                    'employeeId' => $signatoryEmployee?->id,
                    'department' => $signatoryEmployee?->department ?? $template->department ?? '—',
                    'signatory' => $signatoryEmployee?->name ?? '—',
                    'signatureUser' => $signatoryEmployee?->user,
                    'isApproved' => $isApproved,
                    'approvedAt' => $isApproved ? $approver?->approved_at : null,
                    'remarksLabel' => $approver?->clearanceStatusLabel() ?? 'Not Assigned',
                ]];
            })
            ->values();

        $rows = $this->dedupeSignatoryEntries($signatoryEntries);

        // A General Signatory IS a Checklist Clearance Signatory — its
        // signature/date/remarks are sourced from the same
        // `OffboardingRequestGeneralSignatory` approval record the
        // Approvals page's Submit action and the Offboarding
        // Status/Timeline both read/write (`generalSignatoryApprovals`,
        // matched here by `general_signatory_id`), never fabricated or left
        // permanently blank — the same "signature only appears once
        // genuinely approved" rule the department rows above follow.
        //
        // Deliberately NOT re-filtered on `GeneralSignatory.is_active` here:
        // whether a General Signatory was active is only relevant at the
        // moment `ChecklistApprovalNotifier::notifyGeneralSignatories()`
        // decided who to attach to this specific request (a one-time,
        // creation-time snapshot into `offboarding_request_general_signatories`
        // — see that method and `OffboardingRequest::generalSignatories()`'s
        // own docblock). Re-checking the LIVE `is_active` value here would
        // let toggling a General Signatory's status on/off retroactively
        // add or remove them from an already-created request's Clearance
        // Form, which is exactly the frozen-snapshot guarantee every other
        // part of this feature (the Approvals page, the approval record
        // itself) already relies on.
        $generalSignatoryApprovalsById = $offboardingRequest->generalSignatoryApprovals->keyBy('general_signatory_id');

        $generalSignatoryRows = $offboardingRequest->generalSignatories
            ->map(function (GeneralSignatory $generalSignatory) use ($generalSignatoryApprovalsById) {
                $clearanceSignatory = $generalSignatory->clearanceSignatory;
                $approval = $generalSignatoryApprovalsById->get($generalSignatory->id);
                $isApproved = $approval?->status === 'approved';

                return [
                    'department' => $clearanceSignatory?->department ?? '—',
                    'signatory' => $clearanceSignatory?->name ?? '—',
                    'signatureDataUri' => $isApproved ? $this->signatureDataUri($clearanceSignatory?->user?->signature_path) : null,
                    'date' => $isApproved ? $approval?->approved_at?->format('M d, Y') : null,
                    'remarks' => $approval?->clearanceStatusLabel() ?? 'Pending',
                ];
            })
            ->values();

        $rows = $rows->concat($generalSignatoryRows);

        return [
            'offboardingRequest' => $offboardingRequest,
            'employeeName' => $employee->name,
            'employeeNumber' => $employee->employee_code,
            'position' => $employee->designation,
            'department' => $employee->department,
            'dateHired' => $employee->date_of_joining?->format('M d, Y') ?? '—',
            'separationDate' => $offboardingRequest->last_working_day->format('M d, Y'),
            'immediateHeadRow' => $immediateHeadRow,
            'rows' => $rows,
        ];
    }

    /**
     * The Immediate Head's signature on the Clearance Form — unconditional
     * (if uploaded) when they hold no other synced Checklist Clearance
     * Signatory role on this request, otherwise gated on every synced
     * department checklist actually being approved, exactly like a normal
     * checklist row's own signature is gated on `$approver->status ===
     * 'approved'`. Requiring EVERY synced row to be approved (not just one)
     * matters only if this person happens to be the signatory of more than
     * one department checklist here — an edge case, but one where showing a
     * "cleared" signature while a second department is still pending would
     * be exactly the same kind of conflicting/misleading record this
     * synchronization exists to prevent.
     *
     * @param  Collection<int, OffboardingRequestApprover>  $syncedApprovers
     */
    private function immediateHeadSignatureDataUri(Employee $immediateHead, Collection $syncedApprovers): ?string
    {
        if ($syncedApprovers->isEmpty()) {
            return $this->signatureDataUri($immediateHead->user?->signature_path);
        }

        if (! $syncedApprovers->every(fn (OffboardingRequestApprover $approver) => $approver->status === 'approved')) {
            return null;
        }

        return $this->signatureDataUri($immediateHead->user?->signature_path);
    }

    /**
     * @param  Collection<int, OffboardingRequestApprover>  $syncedApprovers
     */
    private function immediateHeadApprovedDate(Collection $syncedApprovers): ?string
    {
        if ($syncedApprovers->isEmpty() || ! $syncedApprovers->every(fn (OffboardingRequestApprover $approver) => $approver->status === 'approved')) {
            return null;
        }

        $latestApprovedAt = $syncedApprovers
            ->pluck('approved_at')
            ->filter()
            ->sortByDesc(fn ($approvedAt) => $approvedAt->timestamp)
            ->first();

        return $latestApprovedAt?->format('M d, Y');
    }

    /**
     * With no synced department role, the Immediate Head row has no
     * checklist status of its own to report (matching today's original,
     * always-blank remarks). With one, its remarks mirror that department's
     * own `clearanceStatusLabel()` — the same label that row itself shows —
     * so the two entries can never disagree in wording either. With MORE
     * than one (this person heads several checklists here), "Cleared" is
     * only ever shown once every single one of them genuinely is — same
     * rule `dedupeSignatoryEntries()` applies to a merged department row,
     * so an outstanding checklist's own real status (Pending, Hold,
     * Overdue, Declined, In Progress) is never masked by a sibling
     * checklist that happens to already be cleared.
     *
     * @param  Collection<int, OffboardingRequestApprover>  $syncedApprovers
     */
    private function immediateHeadRemarks(Collection $syncedApprovers): string
    {
        if ($syncedApprovers->isEmpty()) {
            return '';
        }

        $labels = $syncedApprovers->map(fn (OffboardingRequestApprover $approver) => $approver->clearanceStatusLabel());

        if ($labels->every(fn (string $label) => $label === 'Cleared')) {
            return 'Cleared';
        }

        return $labels->reject(fn (string $label) => $label === 'Cleared')->unique()->implode(', ');
    }

    /**
     * One RAW entry per DISTINCT employee actually assigned to at least one
     * item on a "Use Task Assignee as Clearance Signatory" checklist —
     * never one entry per item, and never one for the whole checklist
     * (there is no single owner to report on; `$approver?->employee` is
     * always null for this checklist kind). Grouped by
     * `effectiveSignatoryFor()` — the same per-request-override-aware
     * resolution the rest of the app already uses (Approvals page,
     * notifications) — so a Department Head's live item reassignment is
     * reflected here identically. Items nobody was ever assigned
     * (`effectiveSignatoryFor()` null) contribute no entry.
     *
     * Shaped identically to the regular-checklist entries built in
     * `buildData()` (same keys), so `dedupeSignatoryEntries()` can merge a
     * Task Assignee who ALSO happens to be a regular Clearance Signatory —
     * or a Task Assignee on more than one headless checklist — into a
     * single row exactly the same way. Each employee's own `isApproved`
     * here reflects only THEIR OWN item(s) on this one template: cleared
     * once every one of them is checked, pending otherwise — never the
     * whole checklist's aggregate state, since each Task Assignee is only
     * responsible for their own work.
     *
     * @return array<int, array{employeeId: ?int, department: string, signatory: string, signatureUser: mixed, isApproved: bool, approvedAt: mixed, remarksLabel: string}>
     */
    private function taskAssigneeSignatoryEntries(ChecklistTemplate $template, ?OffboardingRequestApprover $approver): array
    {
        if (! $approver) {
            return [];
        }

        $itemsByEmployeeId = $template->items
            ->groupBy(fn ($item) => $approver->effectiveSignatoryFor($item)?->id)
            ->forget(null);

        $checkedItemIds = $approver->itemProgress->where('is_checked', true)->pluck('checklist_item_id');

        return $itemsByEmployeeId->map(function ($items, $employeeId) use ($approver, $checkedItemIds) {
            $employee = Employee::find($employeeId);
            $isFullyCleared = $items->every(fn ($item) => $checkedItemIds->contains($item->id));

            $lastCheckedAt = $approver->itemProgress
                ->whereIn('checklist_item_id', $items->pluck('id'))
                ->where('is_checked', true)
                ->max('checked_at');

            return [
                'employeeId' => $employee?->id,
                'department' => $employee?->department ?? '—',
                'signatory' => $employee?->name ?? '—',
                'signatureUser' => $employee?->user,
                'isApproved' => $isFullyCleared,
                'approvedAt' => $isFullyCleared ? $lastCheckedAt : null,
                'remarksLabel' => $isFullyCleared ? 'Cleared' : 'Pending',
            ];
        })->values()->all();
    }

    /**
     * Merges every raw signatory entry (one per checklist a signatory is
     * responsible for — either a regular Clearance Signatory or a Task
     * Assignee-as-signatory) into exactly ONE row per distinct employee,
     * regardless of how many checklists assign them — grouped by employee
     * id, NEVER by name, so two different people who happen to share a name
     * are never accidentally merged. An entry with no resolvable employee
     * (`employeeId` null — a checklist with nobody currently assigned)
     * keeps its own row and is never merged with another unassigned entry
     * either; each is genuinely a distinct "Not Assigned" checklist.
     *
     * Generalizes the exact same "every synced role must be approved before
     * a signature shows" rule `immediateHeadSignatureDataUri()`/
     * `immediateHeadApprovedDate()`/`immediateHeadRemarks()` already apply
     * to the Immediate Head's own (separately-built) row: a signatory
     * responsible for two checklists only shows as cleared once BOTH are
     * approved, their combined Remarks column lists every distinct status
     * they currently hold (e.g. "Cleared, Pending" while only one of their
     * two checklists is done), and the shown date is the latest of the
     * merged approvals.
     *
     * Deliberately only ever called on checklist-clearance-signatory
     * entries — General Signatory rows are concatenated in by the caller
     * AFTER this runs, so a General Signatory always keeps its own row even
     * if the same person is also a Clearance Signatory elsewhere on this
     * request, per that feature's own independent workflow.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @return Collection<int, array{department: string, signatory: string, signatureDataUri: ?string, date: ?string, remarks: string}>
     */
    private function dedupeSignatoryEntries(Collection $entries): Collection
    {
        return $entries
            ->groupBy(fn (array $entry, int $key) => $entry['employeeId'] ?? 'unassigned-'.$key)
            ->map(function (Collection $group) {
                $first = $group->first();
                $allApproved = $group->every(fn (array $entry) => $entry['isApproved']);

                // Never claim "Cleared" credit for a merged row unless
                // EVERY checklist in the group actually is — a signatory
                // still outstanding on even one of their checklists must
                // never show "Cleared" anywhere in their combined Remarks,
                // even alongside another checklist of theirs that genuinely
                // is done (e.g. "Cleared, Pending" reads as if they were
                // already cleared). Each checklist's own real status is
                // still evaluated independently (via `remarksLabel` above,
                // never inherited from a sibling checklist); once not
                // every one is approved, only the non-"Cleared" label(s)
                // are shown, so an outstanding checklist's genuine status
                // (Pending, Hold, Overdue, Declined, In Progress) is never
                // masked by a sibling that happens to already be cleared.
                $remarks = $allApproved
                    ? 'Cleared'
                    : $group->pluck('remarksLabel')->reject(fn (string $label) => $label === 'Cleared')->unique()->implode(', ');

                return [
                    'department' => $first['department'],
                    'signatory' => $first['signatory'],
                    'signatureDataUri' => $allApproved ? $this->signatureDataUri($first['signatureUser']?->signature_path) : null,
                    'date' => $allApproved
                        ? $group->pluck('approvedAt')->filter()->sortByDesc(fn ($approvedAt) => $approvedAt->timestamp)->first()?->format('M d, Y')
                        : null,
                    'remarks' => $remarks,
                ];
            })
            ->values();
    }

    /**
     * Base64-embeds the signature image directly into the document instead
     * of referencing it by URL — dompdf can't reliably fetch remote/local
     * URLs, and this also sidesteps any host/port mismatch between the
     * app's configured URL and however it's actually being served.
     */
    private function signatureDataUri(?string $signaturePath): ?string
    {
        if (! $signaturePath || ! Storage::disk('public')->exists($signaturePath)) {
            return null;
        }

        $mime = Storage::disk('public')->mimeType($signaturePath);
        $contents = Storage::disk('public')->get($signaturePath);

        return 'data:'.$mime.';base64,'.base64_encode($contents);
    }

    /**
     * Same base64-embedding approach as `signatureDataUri()`, for the static
     * header/footer banner images bundled with the app (not user uploads).
     */
    private function localImageDataUri(string $absolutePath): ?string
    {
        if (! is_file($absolutePath)) {
            return null;
        }

        $mime = mime_content_type($absolutePath) ?: 'image/png';

        return 'data:'.$mime.';base64,'.base64_encode(file_get_contents($absolutePath));
    }
}
