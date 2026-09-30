<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItem;
use App\Models\ChecklistItemProgress;
use App\Models\ChecklistTemplate;
use App\Models\DepartmentHead;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\FinalApprover;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ClearanceFormController extends Controller
{
    /**
     * Streams the clearance form as a downloadable/viewable PDF — this is
     * the "Generate Clearance Form" action, producing the actual document.
     */
    public function pdf(OffboardingRequest $offboardingRequest): Response
    {
        $this->authorizeView($offboardingRequest);

        $data = $this->buildPdfData($offboardingRequest);

        $pdf = Pdf::loadView('clearance-form.pdf', $data)->setPaper('letter');

        return $pdf->stream('Clearance Form - '.$data['employeeName'].'.pdf');
    }

    /**
     * Raw PDF bytes for the Clearance Form — used by
     * `FinalApprovalController` to attach the document to the Final
     * Approval Request email (and as the source `screenshotPngFromBytes()`
     * rasterizes). Same data/view as `pdf()`, just returned as a string
     * instead of streamed as an HTTP response.
     */
    public function generatePdfBytes(OffboardingRequest $offboardingRequest): string
    {
        return Pdf::loadView('clearance-form.pdf', $this->buildPdfData($offboardingRequest))
            ->setPaper('letter')
            ->output();
    }

    /**
     * Rasterizes the Clearance Form's first (only) PDF page to a PNG —
     * the "screenshot" attached alongside the PDF on the Final Approval
     * Request email. Requires the `imagick` PHP extension with Ghostscript
     * installed and discoverable on PATH (ImageMagick shells out to it for
     * PDF decoding) — throws if either is unavailable; the caller decides
     * whether that should block the email entirely or just be sent without
     * this attachment.
     */
    public function screenshotPngFromBytes(string $pdfBytes): string
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'clearance_form_') . '.pdf';
        file_put_contents($tmpFile, $pdfBytes);

        try {
            $image = new \Imagick();
            $image->setResolution(150, 150);
            $image->readImage($tmpFile . '[0]');
            $image->setImageFormat('png');
            $image->setImageBackgroundColor(new \ImagickPixel('white'));
            $image = $image->flattenImages();

            return $image->getImagesBlob();
        } finally {
            @unlink($tmpFile);
        }
    }

    /**
     * The `$data` array shape shared by `pdf()` and `generatePdfBytes()` —
     * `buildData()` plus the header/footer images embedded as base64 data
     * URIs (required for DomPDF, which can't fetch external/relative
     * image URLs the way a browser can for the `print()` view below).
     */
    private function buildPdfData(OffboardingRequest $offboardingRequest): array
    {
        return $this->buildData($offboardingRequest) + [
            'headerImageSrc' => $this->localImageDataUri(public_path('images/clearance-form/header.png')),
            'footerImageSrc' => $this->localImageDataUri(public_path('images/clearance-form/footer.png')),
        ];
    }

    /**
     * A plain HTML page styled for print and set to auto-open the browser's
     * print dialog — the "Print Clearance Form" action. Kept separate from
     * the PDF route since browsers' built-in PDF viewers don't reliably
     * auto-trigger printing, while a real page's `window.print()` always does.
     */
    public function print(OffboardingRequest $offboardingRequest): View
    {
        $this->authorizeView($offboardingRequest);

        $data = $this->buildData($offboardingRequest) + [
            'headerImageSrc' => asset('images/clearance-form/header.png'),
            'footerImageSrc' => asset('images/clearance-form/footer.png'),
        ];

        return view('clearance-form.print', $data);
    }

    /**
     * Who may view THIS specific request's Clearance Form. Admin/HR
     * (`offboardees.view`) always can — the existing, unchanged rule this
     * route used to enforce via route middleware alone. Additionally, the
     * request's own active Final Approver can too: `ApprovalController::index()`'s
     * Final Approval card and the emailed confirmation page
     * (`FinalApprovalController::showEmailApproval()`) both hand them this
     * exact link as "Supporting documents" to review before approving —
     * without this, a Final Approver holding only the `approver` role (no
     * `offboardees.view`) hit a 403 the moment they clicked it, even though
     * reviewing it is the whole point of that link. Deliberately scoped to
     * THIS ONE request's own Final Approval row, never a blanket grant —
     * `finalApproval` reflects who actually signs THIS request, frozen once
     * approved (see that relation's own docblock), so this check stays
     * correct even after the active `FinalApprover` config later changes.
     */
    private function authorizeView(OffboardingRequest $offboardingRequest): void
    {
        $user = auth()->user();

        if ($user->isAdmin() || $user->can('offboardees.view')) {
            return;
        }

        $employeeId = $user->employee?->id;

        abort_unless(
            $employeeId !== null && $offboardingRequest->finalApproval?->employee_id === $employeeId,
            403
        );
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
        // `finalApproverEmployee` is deliberately NOT eager-loaded here — the
        // Final Approver shown on the Clearance Form is always resolved
        // live from the `FinalApprover` table further down, never from
        // this request's own frozen snapshot relation.
        $offboardingRequest->loadMissing([
            'employee', 'immediateHead.user', 'checklistTemplates', 'approvers.employee.user', 'approvers.itemProgress',
            'approvers.checklistTemplate.items', 'approvers.itemAssignments.assignedEmployee',
            'generalSignatories.clearanceSignatory.user', 'generalSignatoryApprovals.generalSignatory',
        ]);

        // The single source of truth every signature-display decision below
        // consults — see this method's own docblock for why gating per role
        // in isolation (Immediate Head against only their own checklists,
        // a Clearance Signatory against only their own template, a General
        // Signatory against only their own GS approval) is no longer
        // correct: an employee's signature must stay hidden anywhere it
        // appears on this form until EVERY checklist responsibility they
        // hold anywhere on this request — including a plain item-level Task
        // Assignee role on a checklist someone else owns, and including
        // General Signatory status — is complete.
        $employeeClearanceStatuses = $this->buildEmployeeClearanceStatuses($offboardingRequest);

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
        // Signatory of an ordinary department checklist on this request, a
        // Task Assignee on any other checklist (including the Final Pay
        // Checklist), or a General Signatory — matched purely by employee,
        // e.g. the Information Services department head also happens to be
        // this offboardee's Immediate Head — every one of those
        // responsibilities is folded in here too via
        // `$employeeClearanceStatuses` (see that method's own docblock), so
        // approving any one of them via their already-merged combined
        // Approvals-page card (see `ApprovalController::groupIntoCombinedApprovals()`)
        // is reflected identically here. A person responsible for more than
        // one thing here only reads as cleared once ALL of them are.
        //
        // Only when this employee holds NO responsibility at all anywhere on
        // this request (absent from `$employeeClearanceStatuses`) does this
        // fall back to an unconditional signature-if-uploaded with no
        // date/remarks — see the three helpers below.
        $immediateHeadStatus = $immediateHead ? ($employeeClearanceStatuses[$immediateHead->id] ?? null) : null;

        $immediateHeadRow = $immediateHead ? [
            'designation' => 'Immediate Head',
            'signatory' => $immediateHead->name,
            'signatureDataUri' => $this->immediateHeadSignatureDataUri($immediateHead, $immediateHeadStatus),
            'date' => $this->immediateHeadApprovedDate($immediateHeadStatus),
            'remarks' => $this->immediateHeadRemarks($immediateHeadStatus),
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
                    return $this->taskAssigneeSignatoryEntries($template, $approver, $offboardingRequest);
                }

                $signatoryEmployee = $approver?->employee;
                // A declined checklist is now placed On Hold, not completed
                // — it never counts as cleared and shows no signature until
                // the same signatory removes the hold and actually approves
                // it (see `ApprovalController::decline()`/`removeHold()`).
                // This is the defensive per-row fallback path only (used
                // when the employee is somehow absent from
                // `buildEmployeeClearanceStatuses()`'s own map below).
                $isApproved = $approver?->status === 'approved';

                return [[
                    'employeeId' => $signatoryEmployee?->id,
                    'department' => $signatoryEmployee?->department ?? $template->department ?? '—',
                    'signatory' => $signatoryEmployee?->name ?? '—',
                    'signatureUser' => $signatoryEmployee?->user,
                    'isApproved' => $isApproved,
                    'approvedAt' => $isApproved ? $approver?->approved_at : null,
                    'remarksLabel' => $approver?->clearanceStatusLabel(includeOverdue: false) ?? 'Not Assigned',
                ]];
            })
            ->values();

        $rows = $this->dedupeSignatoryEntries($signatoryEntries, $employeeClearanceStatuses);

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
            ->map(function (GeneralSignatory $generalSignatory) use ($generalSignatoryApprovalsById, $employeeClearanceStatuses) {
                $clearanceSignatory = $generalSignatory->clearanceSignatory;
                $approval = $generalSignatoryApprovalsById->get($generalSignatory->id);

                // A General Signatory's own GS approval is only ONE of
                // potentially several responsibilities this same employee
                // holds on this request — see `buildEmployeeClearanceStatuses()`.
                // Falls back to this row's own GS status alone only if the
                // employee is somehow absent from that map entirely (should
                // never happen, since this very approval is one of its
                // inputs — defensive only).
                $status = $clearanceSignatory ? ($employeeClearanceStatuses[$clearanceSignatory->id] ?? null) : null;
                // A declined General Signatory approval is now On Hold, not
                // completed — same reasoning as the checklist rows above;
                // see `buildEmployeeClearanceStatuses()`'s own gate.
                $isApproved = $status !== null ? $status['allCleared'] : $approval?->status === 'approved';

                return [
                    'department' => $clearanceSignatory?->department ?? '—',
                    'signatory' => $clearanceSignatory?->name ?? '—',
                    'signatureDataUri' => $isApproved ? $this->signatureDataUri($clearanceSignatory?->user?->signature_path) : null,
                    'date' => $isApproved
                        ? ($status['clearedAt'] ?? $approval?->approved_at)?->format('M d, Y')
                        : null,
                    'remarks' => $status['label'] ?? ($approval?->clearanceStatusLabel() ?? 'Pending'),
                ];
            })
            ->values();

        $rows = $rows->concat($generalSignatoryRows);

        // This request's own Final Approval process (see
        // `OffboardingRequestFinalApproval`), if one has ever been
        // initiated via the Offboardee page's "Final Approval" button.
        $finalApproval = $offboardingRequest->finalApproval()->with('employee.user')->first();
        $isFinalApproved = $finalApproval?->status === 'approved';

        // Once THIS request has actually been given Final Approval, the
        // signatory shown is permanently whoever really signed it
        // (`$finalApproval->employee`, frozen fact) — never re-resolved
        // against the live config again, the same "a real signature never
        // retroactively changes" principle every other row on this form
        // already follows. Only BEFORE that point does this fall back to
        // resolving the live active `FinalApprover` fresh on every render
        // (a product decision: an admin activating a different Final
        // Approver must be reflected immediately on any request that
        // hasn't been finally approved yet, no manual step needed).
        // `offboarding_request.final_approver_employee_id` is never read
        // here either way — see that column's own docblock. No hardcoded
        // person's name is ever shown; if there's neither a completed
        // approval nor any active `FinalApprover` configured, the line is
        // left blank. Upper-cased to match this block's own long-standing
        // all-caps styling, applied regardless of who the actual person is.
        if ($isFinalApproved) {
            $finalApproverEmployee = $finalApproval->employee;
        } else {
            $finalApproverEmployee = FinalApprover::with('employee')->where('is_active', true)->first()?->employee;
        }

        $finalApprover = [
            'name' => $finalApproverEmployee ? Str::upper($finalApproverEmployee->name) : '',
            'title' => $finalApproverEmployee?->designation ?: ($finalApproverEmployee ? 'President' : ''),
            'signatureDataUri' => $isFinalApproved ? $this->signatureDataUri($finalApproverEmployee->user?->signature_path) : null,
            'approvedAt' => $isFinalApproved ? $finalApproval->approved_at?->format('M d, Y g:i A') : null,
        ];

        // Deliberately NOT added as its own row in the main signatory table
        // above (a "Final Approval" row was previously injected at the top
        // of `$rows` here) — the Final Approver already has their own
        // dedicated "Approved for Payment by" block at the bottom of the
        // form (rendered from `$finalApprover` below), so listing them a
        // second time as a table row was redundant and has been removed.

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
            'finalApprover' => $finalApprover,
        ];
    }

    /**
     * The Immediate Head's signature on the Clearance Form — unconditional
     * (if uploaded) when they hold NO responsibility at all elsewhere on
     * this request (`$status === null`), otherwise gated on EVERY
     * responsibility they hold anywhere on it — every checklist they own,
     * every checklist they're merely a Task Assignee on, and any General
     * Signatory role — via `buildEmployeeClearanceStatuses()`'s unified
     * `allCleared` flag. Requiring every single one (not just their
     * Immediate Head checklist itself) matters whenever this person is ALSO
     * a department Clearance Signatory, a Task Assignee elsewhere
     * (including the Final Pay Checklist), or a General Signatory — showing
     * a "cleared" signature while any one of those is still outstanding
     * would be exactly the misleading record this unification exists to
     * prevent.
     *
     * @param  ?array{allCleared: bool, label: string, clearedAt: ?\Illuminate\Support\Carbon}  $status
     */
    private function immediateHeadSignatureDataUri(Employee $immediateHead, ?array $status): ?string
    {
        if ($status === null || $status['allCleared']) {
            return $this->signatureDataUri($immediateHead->user?->signature_path);
        }

        return null;
    }

    /**
     * @param  ?array{allCleared: bool, label: string, clearedAt: ?\Illuminate\Support\Carbon}  $status
     */
    private function immediateHeadApprovedDate(?array $status): ?string
    {
        if ($status === null || ! $status['allCleared']) {
            return null;
        }

        return $status['clearedAt']?->format('M d, Y');
    }

    /**
     * With no responsibility at all elsewhere on this request, the
     * Immediate Head row has no checklist status of its own to report
     * (matching today's original, always-blank remarks). With one or more,
     * its remarks mirror `buildEmployeeClearanceStatuses()`'s own combined
     * label for this employee — the same label every other row of theirs on
     * this form shows too, so no two entries for the same person can ever
     * disagree in wording. "Cleared" is only ever shown once EVERY one of
     * their responsibilities genuinely is, so an outstanding checklist's own
     * real status (Pending, Hold, Declined, In Progress — never "Overdue"
     * on this form specifically, see `clearanceStatusLabel()`'s own
     * docblock) is never masked by a sibling responsibility that happens to
     * already be cleared.
     *
     * @param  ?array{allCleared: bool, label: string, clearedAt: ?\Illuminate\Support\Carbon}  $status
     */
    private function immediateHeadRemarks(?array $status): string
    {
        return $status['label'] ?? '';
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
     * The row actually DISPLAYED for each raw Task Assignee is resolved via
     * `effectiveClearanceSignatoryFor()` — themselves, unchanged, if
     * already head-level, otherwise their own Immediate Head/Department
     * Head, since a rank-and-file Task Assignee's own name has no business
     * appearing as a formal Clearance Signatory. `isApproved`/`approvedAt`
     * still reflect the RAW assignee's own item completion regardless of
     * who the row is attributed to — see `buildEmployeeClearanceStatuses()`,
     * which attributes the same items to the same resolved employee so
     * both stay consistent. An entry that resolves to this SAME request's
     * own Immediate Head is dropped entirely — that person's signature is
     * already shown once via the dedicated Immediate Head row above this
     * table (see `buildData()`), so keeping it here too would duplicate it;
     * `buildEmployeeClearanceStatuses()` still attributes the underlying
     * unit to them either way, so the Immediate Head row's own gating
     * reflects this completion status correctly even with no row here.
     *
     * @return array<int, array{employeeId: ?int, department: string, signatory: string, signatureUser: mixed, isApproved: bool, approvedAt: mixed, remarksLabel: string}>
     */
    private function taskAssigneeSignatoryEntries(ChecklistTemplate $template, ?OffboardingRequestApprover $approver, OffboardingRequest $offboardingRequest): array
    {
        if (! $approver) {
            return [];
        }

        $itemsByEmployeeId = $template->items
            ->groupBy(fn ($item) => $approver->effectiveSignatoryFor($item)?->id)
            ->forget(null);

        // Only ever `is_checked` for anything but a "Use Task Assignee as
        // Clearance Signatory" checklist — same `isFullyApproved()` an item
        // must satisfy for the checklist's own `allItemsCompleted()`, so a
        // Task Assignee's row on this form is never shown "Cleared" while
        // their Department/Group Head's additional approval is still
        // pending. See `ChecklistItemProgress::isFullyApproved()`.
        $fullyApprovedProgress = $approver->itemProgress->filter->isFullyApproved();
        $fullyApprovedItemIds = $fullyApprovedProgress->pluck('checklist_item_id');

        return $itemsByEmployeeId->map(function ($items, $employeeId) use ($approver, $fullyApprovedItemIds, $fullyApprovedProgress, $offboardingRequest) {
            $assignee = Employee::find($employeeId);
            $employee = $assignee ? $this->effectiveClearanceSignatoryFor($assignee) : null;

            if ($employee && $employee->id === $offboardingRequest->immediate_head_id) {
                return null;
            }

            $isFullyCleared = $items->every(fn ($item) => $fullyApprovedItemIds->contains($item->id));

            // The moment full approval was actually reached per item — a
            // head-approved item's own approval time, not when it was
            // merely checked, since that's the point it genuinely became
            // "Cleared" on this form.
            $lastCheckedAt = $fullyApprovedProgress
                ->whereIn('checklist_item_id', $items->pluck('id'))
                ->max(fn ($progress) => $progress->head_approved_at ?? $progress->checked_at);

            return [
                'employeeId' => $employee?->id,
                'department' => $employee?->department ?? '—',
                'signatory' => $employee?->name ?? '—',
                'signatureUser' => $employee?->user,
                'isApproved' => $isFullyCleared,
                'approvedAt' => $isFullyCleared ? $lastCheckedAt : null,
                'remarksLabel' => $isFullyCleared ? 'Cleared' : 'Pending',
            ];
        })->filter()->values()->all();
    }

    /**
     * Whether `$employee` already holds a head-level role somewhere in the
     * system, independent of this specific offboarding request — someone
     * else's Immediate Head (`head_employee_id`), an EmployeeGroup's
     * registered Group Head, registered in the standalone `DepartmentHead`
     * registry, or the Clearance Signatory (`department_head_id`) of any
     * active checklist template. A "Use Task Assignee as Clearance
     * Signatory" checklist's Task Assignee who already holds one of these
     * roles is left exactly as today (their own name/signature) — only a
     * genuine rank-and-file Task Assignee with none of them gets escalated
     * to their own reporting head by `effectiveClearanceSignatoryFor()`.
     */
    private function isHeadLevelEmployee(Employee $employee): bool
    {
        return Employee::where('head_employee_id', $employee->id)->exists()
            || EmployeeGroup::where('group_head_employee_id', $employee->id)->exists()
            || DepartmentHead::where('employee_id', $employee->id)->exists()
            || ChecklistTemplate::where('department_head_id', $employee->id)->where('is_active', true)->exists();
    }

    /**
     * The employee a "Use Task Assignee as Clearance Signatory" checklist's
     * raw Task Assignee's responsibility should actually be attributed to
     * on the Clearance Form: themselves, unchanged, if already head-level
     * (`isHeadLevelEmployee()`) — otherwise their own Immediate Head
     * (`Employee::headEmployee()`, resolved from the Employee Master
     * import's `headID`/`head_employee_id`), falling back to their
     * Department/Group Head (`Employee::departmentHead()`) when no
     * Immediate Head is set — the exact same two-step lookup the rest of
     * the app already uses for this hierarchy. Falls back to the assignee
     * themselves if NEITHER resolves to anyone, rather than silently
     * dropping their responsibility from the form; never creates a new
     * head assignment, only reads the employee's existing configured one.
     */
    private function effectiveClearanceSignatoryFor(Employee $assignee): Employee
    {
        if ($this->isHeadLevelEmployee($assignee)) {
            return $assignee;
        }

        return $assignee->headEmployee ?? $assignee->departmentHead() ?? $assignee;
    }

    /**
     * `effectiveClearanceSignatoryFor()` by raw employee id instead of a
     * loaded `Employee` — the shape `buildEmployeeClearanceStatuses()`
     * needs when attributing a headless checklist's item-level gating unit
     * (both for an already-attached approver's items and for unit type 4's
     * not-yet-attached Final Pay/Secondary item defaults). Null only when
     * `$employeeId` itself is null or no longer resolves to a real
     * `Employee` — never for a genuinely resolvable one.
     */
    private function effectiveClearanceSignatoryIdFor(?int $employeeId): ?int
    {
        if ($employeeId === null) {
            return null;
        }

        $employee = Employee::find($employeeId);

        return $employee ? $this->effectiveClearanceSignatoryFor($employee)->id : null;
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
     * The show/hide decision itself is delegated to
     * `$employeeClearanceStatuses` (see `buildEmployeeClearanceStatuses()`)
     * — the SAME unified per-employee view `immediateHeadSignatureDataUri()`/
     * `immediateHeadApprovedDate()`/`immediateHeadRemarks()` and the General
     * Signatory row map also consult, so a signatory responsible for
     * several checklists — or ALSO the Immediate Head, or ALSO a General
     * Signatory — only shows as cleared once every single one of those is,
     * not just the ones this particular `$entries` batch happened to know
     * about. The `$group`-only computation is kept as a defensive fallback
     * for the "unassigned" grouping key (no real employee to look up) or if
     * an employee is somehow absent from the map.
     *
     * @param  Collection<int, array<string, mixed>>  $entries
     * @param  array<int, array{allCleared: bool, label: string, clearedAt: ?\Illuminate\Support\Carbon}>  $employeeClearanceStatuses
     * @return Collection<int, array{department: string, signatory: string, signatureDataUri: ?string, date: ?string, remarks: string}>
     */
    private function dedupeSignatoryEntries(Collection $entries, array $employeeClearanceStatuses): Collection
    {
        return $entries
            ->groupBy(fn (array $entry, int $key) => $entry['employeeId'] ?? 'unassigned-'.$key)
            ->map(function (Collection $group) use ($employeeClearanceStatuses) {
                $first = $group->first();
                $employeeId = $first['employeeId'] ?? null;
                $status = $employeeId !== null ? ($employeeClearanceStatuses[$employeeId] ?? null) : null;

                if ($status !== null) {
                    $allApproved = $status['allCleared'];
                    $remarks = $status['label'];
                    $clearedAt = $status['clearedAt'];
                } elseif ($employeeId === null) {
                    // No real employee resolved for this checklist at all
                    // (e.g. a "Use Task Assignee as Clearance Signatory"
                    // checklist with nobody currently assigned) — always a
                    // single-entry group (see the `groupBy` above), so
                    // there's nothing to consolidate: just that one
                    // checklist's own remarks, typically "Not Assigned".
                    $allApproved = $first['isApproved'];
                    $remarks = $first['remarksLabel'];
                    $clearedAt = $allApproved ? $first['approvedAt'] : null;
                } else {
                    // Defensive only — every real employee is expected to
                    // already have an entry in `$employeeClearanceStatuses`
                    // (it's built from these same assignments), so this
                    // path should never actually run. Kept consistent with
                    // that method's own single-consolidated-label rule
                    // regardless: "Cleared" only once EVERY checklist in the
                    // group is, "In Progress" once at least one is done but
                    // at least one other isn't, "Pending" while none are —
                    // never a comma-joined mix of each checklist's own
                    // state.
                    $allApproved = $group->every(fn (array $entry) => $entry['isApproved']);
                    $anyApproved = $group->contains(fn (array $entry) => $entry['isApproved']);
                    $remarks = match (true) {
                        $allApproved => 'Cleared',
                        $anyApproved => 'In Progress',
                        default => 'Pending',
                    };
                    $clearedAt = $allApproved
                        ? $group->pluck('approvedAt')->filter()->sortByDesc(fn ($approvedAt) => $approvedAt->timestamp)->first()
                        : null;
                }

                return [
                    'department' => $first['department'],
                    'signatory' => $first['signatory'],
                    'signatureDataUri' => $allApproved ? $this->signatureDataUri($first['signatureUser']?->signature_path) : null,
                    'date' => $allApproved ? $clearedAt?->format('M d, Y') : null,
                    'remarks' => $remarks,
                ];
            })
            ->values();
    }

    /**
     * The single source of truth every signature-display decision on this
     * form consults — one entry per employee who holds ANY responsibility
     * anywhere on this request, requiring ALL of them complete before that
     * employee's signature may show ANYWHERE it appears (their own
     * checklist row(s), an Immediate Head row, a General Signatory row).
     *
     * Collects one "unit" per distinct responsibility:
     *
     *   1. For every `OffboardingRequestApprover` where `employee_id` is
     *      set (a headed checklist, including the Final Pay Checklist and
     *      the Immediate Head's own checklist) — one unit: that row's own
     *      overall `status === 'approved'`, exactly the same signal already
     *      used today for that role alone. This never changes behavior for
     *      an employee who holds no OTHER responsibility.
     *   2. For every checklist item, grouped by `effectiveSignatoryFor()`
     *      (the same per-request-override-aware resolution used
     *      throughout this app) — one unit per employee OTHER than that
     *      checklist's own owner, covering whether ALL of THEIR OWN
     *      assigned items on that specific checklist are checked. This is
     *      what makes a plain item-level Task Assignee on a checklist they
     *      don't own — e.g. specific items on the Final Pay Checklist while
     *      someone else is its registered Clearance Signatory — count
     *      toward their OTHER signatures being withheld, the exact gap this
     *      method exists to close. Skipped for the checklist's own owner
     *      (`(int) $itemEmployeeId === (int) $approver->employee_id`) to
     *      avoid double-gating the very same checklist twice for the same
     *      person under two different rules; safe to compare as plain ints
     *      since employee ids are auto-increment from 1 and never 0/null.
     *      For a headless "Use Task Assignee as Clearance Signatory"
     *      checklist (`employee_id` is null), this is the ONLY unit any
     *      employee gets for it — identical to what `taskAssigneeSignatoryEntries()`
     *      already computes for display today, just now ALSO feeding this
     *      shared gating map.
     *   3. For every `OffboardingRequestGeneralSignatory` — one unit per
     *      row, attributed to `generalSignatory->clearance_signatory_id` —
     *      this is what folds General Signatory into the SAME unified
     *      gating instead of today's fully independent check.
     *   4. For every active, department-applicable `ChecklistTemplate` NOT
     *      YET attached to this request (no `checklist_assignments` pivot
     *      row) — one always-incomplete "Pending" unit per its default
     *      signatory(-ies): every item's own `signatory_id` for a "Use Task
     *      Assignee as Clearance Signatory" template, or its
     *      `department_head_id` otherwise. The Final Pay Checklist is
     *      NEVER attached until every regular checklist and General
     *      Signatory is approved (see `ChecklistCompletionService::attachFinalPayChecklistsIfReady()`),
     *      and a Sync request's Secondary tier isn't attached until Primary
     *      is (`checkPrimaryChecklistsCompletion()`) — but both are real,
     *      inevitable responsibilities on THIS SAME request, not merely
     *      hypothetical ones, since `applicableToDepartment()` here is the
     *      exact same scope that governs whether they'll actually attach.
     *      Without this, an employee who is, say, the HR Checklist's
     *      already-approved Clearance Signatory AND the Final Pay
     *      Checklist's future Task Assignee would read as fully cleared the
     *      moment the HR Checklist alone is done, since no
     *      `OffboardingRequestApprover` row exists yet to catch the rest —
     *      exactly the gap this unit closes.
     *   5. For every active `GeneralSignatory` NOT YET snapshotted onto this
     *      request (no matching `offboarding_request_general_signatories`
     *      row) — the same "inevitable future responsibility" reasoning as
     *      #4, for a Sync request's not-yet-notified Secondary tier or
     *      either mode's Final-Pay tier.
     *
     * @return array<int, array{allCleared: bool, label: string, clearedAt: ?\Illuminate\Support\Carbon}>
     */
    private function buildEmployeeClearanceStatuses(OffboardingRequest $offboardingRequest): array
    {
        /** @var array<int, Collection<int, array{done: bool, label: string, completedAt: mixed}>> $unitsByEmployee */
        $unitsByEmployee = [];

        $addUnit = function (?int $employeeId, bool $done, string $label, $completedAt) use (&$unitsByEmployee) {
            if ($employeeId === null) {
                return;
            }

            $unitsByEmployee[$employeeId] ??= collect();
            $unitsByEmployee[$employeeId]->push(['done' => $done, 'label' => $label, 'completedAt' => $completedAt]);
        };

        foreach ($offboardingRequest->approvers as $approver) {
            if ($approver->employee_id !== null) {
                // A declined checklist is On Hold, not done — see
                // `ChecklistCompletionService`'s matching `'approved'`-only
                // gates. `clearanceStatusLabel()` still surfaces "Declined"/
                // "On Hold" distinctly for the Remarks column even though
                // this unit isn't counted as cleared.
                $addUnit(
                    $approver->employee_id,
                    $approver->status === 'approved',
                    $approver->clearanceStatusLabel(includeOverdue: false),
                    $approver->approved_at,
                );
            }

            $itemsByEmployeeId = ($approver->checklistTemplate?->items ?? collect())
                ->groupBy(fn (ChecklistItem $item) => $approver->effectiveSignatoryFor($item)?->id)
                ->forget(null);

            // Same `isFullyApproved()` predicate `taskAssigneeSignatoryEntries()`
            // already uses — is_checked ALONE is never enough for a "Use Task
            // Assignee as Clearance Signatory" item: when the effective
            // signatory isn't already head-level, the item also needs its
            // Department/Group/Immediate Head's separate approval (see
            // `ChecklistItemProgress::resolveHeadApproval()`) before the unit
            // this attributes to that Head (via `effectiveClearanceSignatoryIdFor()`
            // below) can count as done. Using raw `is_checked` here let a
            // Final Approver's Clearance Form row (and signature) show
            // "Cleared" the instant their Task Assignee checked the item,
            // before the Final Approver had actually approved anything.
            $fullyApprovedProgress = $approver->itemProgress->filter->isFullyApproved();
            $fullyApprovedItemIds = $fullyApprovedProgress->pluck('checklist_item_id');

            foreach ($itemsByEmployeeId as $itemEmployeeId => $items) {
                if ((int) $itemEmployeeId === (int) $approver->employee_id) {
                    continue;
                }

                $isFullyCleared = $items->every(fn (ChecklistItem $item) => $fullyApprovedItemIds->contains($item->id));

                // The moment full approval was actually reached — a
                // head-approved item's own approval time, not merely when it
                // was checked, matching `taskAssigneeSignatoryEntries()`'s
                // own `$lastCheckedAt` derivation.
                $lastCheckedAt = $fullyApprovedProgress
                    ->whereIn('checklist_item_id', $items->pluck('id'))
                    ->max(fn (ChecklistItemProgress $progress) => $progress->head_approved_at ?? $progress->checked_at);

                // A "Use Task Assignee as Clearance Signatory" checklist's
                // raw Task Assignee has their completion attributed to
                // whoever `taskAssigneeSignatoryEntries()` actually displays
                // for them — themselves if already head-level, otherwise
                // their own resolved Immediate/Department Head — so the
                // gating map and the Clearance Form row agree on who this
                // responsibility belongs to. A normal checklist's per-item
                // override keeps going straight to the raw assignee, same
                // as before — they're an internal task doer there, never
                // escalated into their own Clearance Form row.
                $unitEmployeeId = $approver->employee_id === null
                    ? ($this->effectiveClearanceSignatoryIdFor((int) $itemEmployeeId) ?? (int) $itemEmployeeId)
                    : (int) $itemEmployeeId;

                $addUnit($unitEmployeeId, $isFullyCleared, $isFullyCleared ? 'Cleared' : 'Pending', $lastCheckedAt);
            }
        }

        foreach ($offboardingRequest->generalSignatoryApprovals as $gsApproval) {
            $clearanceSignatoryId = $gsApproval->generalSignatory?->clearance_signatory_id;

            $addUnit(
                $clearanceSignatoryId,
                $gsApproval->status === 'approved',
                $gsApproval->clearanceStatusLabel(),
                $gsApproval->approved_at,
            );
        }

        // Not-yet-attached checklists (Final Pay always; a Sync request's
        // Secondary tier until Primary clears) that WILL apply to this
        // request once the workflow reaches them — see this method's own
        // docblock, unit type 4.
        $attachedTemplateIds = $offboardingRequest->checklistTemplates->pluck('id');

        $pendingTemplates = ChecklistTemplate::where('is_active', true)
            ->applicableToDepartment($offboardingRequest->employee->department)
            ->whereNotIn('id', $attachedTemplateIds)
            ->with('items')
            ->get();

        foreach ($pendingTemplates as $template) {
            if ($template->use_task_assignee_as_signatory) {
                foreach ($template->items as $item) {
                    $addUnit($this->effectiveClearanceSignatoryIdFor($item->signatory_id) ?? $item->signatory_id, false, 'Pending', null);
                }

                continue;
            }

            $addUnit($template->department_head_id, false, 'Pending', null);
        }

        // Not-yet-snapshotted General Signatories (a Sync request's
        // Secondary tier, or either mode's Final Pay tier) — unit type 5.
        $attachedGeneralSignatoryIds = $offboardingRequest->generalSignatories->pluck('id');

        GeneralSignatory::where('is_active', true)
            ->whereNotIn('id', $attachedGeneralSignatoryIds)
            ->get()
            ->each(fn (GeneralSignatory $generalSignatory) => $addUnit($generalSignatory->clearance_signatory_id, false, 'Pending', null));

        return collect($unitsByEmployee)->map(function (Collection $units) {
            $allCleared = $units->every(fn (array $unit) => $unit['done']);
            $anyCleared = $units->contains(fn (array $unit) => $unit['done']);

            // Exactly one consolidated label per employee, never a
            // comma-joined mix of each individual checklist's own state
            // (e.g. "Pending, In Progress, Hold") — an employee assigned to
            // several checklists/tasks must read as a single Clearance
            // Signatory with a single status: "Cleared" only once every one
            // of them is done, "In Progress" once at least one is done but
            // at least one other isn't, "Pending" while none of them are —
            // regardless of how many individual checklists contribute units,
            // or how many different fine-grained states (Hold, Declined,
            // In Progress) those individual checklists are each otherwise
            // in.
            $label = match (true) {
                $allCleared => 'Cleared',
                $anyCleared => 'In Progress',
                default => 'Pending',
            };

            return [
                'allCleared' => $allCleared,
                'label' => $label,
                'clearedAt' => $allCleared
                    ? $units->pluck('completedAt')->filter()->sortByDesc(fn ($completedAt) => $completedAt->timestamp)->first()
                    : null,
            ];
        })->all();
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
