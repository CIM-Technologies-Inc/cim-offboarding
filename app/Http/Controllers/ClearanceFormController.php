<?php

namespace App\Http\Controllers;

use App\Models\ChecklistItem;
use App\Models\ChecklistItemProgress;
use App\Models\ChecklistTemplate;
use App\Models\Employee;
use App\Models\FinalApprover;
use App\Models\GeneralSignatory;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use App\Support\OffboardingTimelineStepPresenter;
use Barryvdh\DomPDF\Facade\Pdf;
use Carbon\Carbon;
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
     * Raw PDF bytes for the COMBINED Clearance Form + Offboarding Timeline
     * document — used exclusively by `ChecklistCompletionService` to attach
     * to the "Final Pay Checklist Completed" notification email once a
     * request reaches `status === 'completed'`. Reuses `buildPdfData()`
     * (unchanged) for the Clearance Form section and
     * `OffboardingRequest::approverActivityTimeline()` (unchanged — the
     * exact same data source bound to the admin-facing Offboarding
     * Status/Timeline modal's own "Timeline" tab) for the new timeline
     * section, rendered as one dompdf document via
     * `clearance-form.pdf-with-timeline` (a `page-break-before` separates
     * the two sections within that one view — see its own comments).
     *
     * Deliberately distinct from `generatePdfBytes()` above, which must keep
     * producing ONLY the Clearance Form — that method backs the Final
     * Approval email's own attachment and the Offboardee page's "Generate
     * Clearance Form" button, neither of which should ever grow a second,
     * unexpected page of timeline content.
     */
    public function generateCompletionPdfBytes(OffboardingRequest $offboardingRequest): string
    {
        $data = $this->buildPdfData($offboardingRequest) + [
            'timelineSteps' => $this->decorateTimelineStepsForPdf($offboardingRequest->approverActivityTimeline()),
        ];

        return Pdf::loadView('clearance-form.pdf-with-timeline', $data)
            ->setPaper('letter')
            ->output();
    }

    /**
     * Item-level `action`s folded into `approverActivityTimeline()`'s plain
     * "delegation event" sub-steps (see that method's own
     * `$delegationEventsByAssignment` grouping) — dropped from the PDF
     * entirely, same as the per-item `checklistItems` breakdown below.
     * Both were too granular for a printed record: a checklist with a
     * handful of items can already produce one of these per item PLUS the
     * same information again in the per-item breakdown, which read as
     * repetitive clutter rather than a readable summary. The checklist's
     * own rich-step card (department/signatory, assigned/due/cleared
     * dates, hold history, due-date extensions) already covers the same
     * ground at the right level of detail for this document. The live
     * Offboarding Status/Timeline tabs are UNCHANGED — they still show
     * every one of these events and the full per-item breakdown; this
     * filtering only ever applies to this static PDF rendering.
     */
    private const PDF_TIMELINE_EXCLUDED_ACTIONS = [
        'checklist_assigned',
        'checklist_delegate_completed',
        'checklist_item_cleared_by_other',
        'checklist_item_held',
        'checklist_ready_for_approval',
    ];

    /**
     * Attaches the PDF-only presentation fields (`badgeLabel`/`badgeClass`
     * per rich step) that `approverActivityTimeline()` itself has no reason
     * to know about — that method stays the single, display-agnostic source
     * of truth both the live Alpine Timeline tab and this static PDF
     * rendering consume. Also drops the per-item-level noise described in
     * `PDF_TIMELINE_EXCLUDED_ACTIONS`'s own docblock above — a plain step
     * is dropped by its real `action` name (not fragile label-text
     * matching), and every rich step's own `checklistItems` breakdown is
     * cleared entirely (the Blade view no longer renders it either; this
     * just keeps the data consistent with that).
     *
     * Finally re-sorted into chronological Completed order (earliest
     * first), by `timelineStepCompletedAt()` below — `approverActivityTimeline()`
     * itself returns steps in workflow-STAGE order (every checklist's own
     * card, then milestones, then General Signatories, then Final
     * Approval), not by when each one was actually completed, which read
     * oddly for a document literally titled "Offboarding Timeline". The
     * live Offboarding Status/Timeline tabs keep their own original
     * workflow-stage order unchanged — this re-sort only ever applies here.
     */
    private function decorateTimelineStepsForPdf(array $rawSteps): array
    {
        $steps = array_values(array_filter(array_map(function (array $step) {
            if (empty($step['rich'])) {
                if (in_array($step['action'] ?? null, self::PDF_TIMELINE_EXCLUDED_ACTIONS, true)) {
                    return null;
                }

                return $step;
            }

            $step['badgeLabel'] = OffboardingTimelineStepPresenter::badgeLabel($step);
            $step['badgeClass'] = OffboardingTimelineStepPresenter::badgeClass($step);
            $step['checklistItems'] = [];

            return $step;
        }, $rawSteps)));

        usort($steps, fn (array $a, array $b) => $this->timelineStepCompletedAt($a) <=> $this->timelineStepCompletedAt($b));

        return $steps;
    }

    /**
     * The moment a timeline step was actually completed, for
     * `decorateTimelineStepsForPdf()`'s own chronological re-sort — a rich
     * step's `approvedAt` (its checklist/General Signatory/Final Approval
     * being approved), or a plain step's own `date` (already its own
     * "when this happened" timestamp, see `approverActivityTimeline()`).
     * Both are already formatted strings ('M d, Y g:i A'), not raw `Carbon`
     * instances, by the time they reach this decorator — re-parsed here
     * rather than threading a second, raw-timestamp field through the
     * shared method for a need that's specific to this one PDF sort.
     * Returns a far-future sentinel (sorts LAST, never first) for a step
     * with no parseable date — e.g. a still-pending checklist's rich step,
     * which has no `approvedAt` at all; never expected to actually occur
     * for a request whose Final Pay Checklist has already completed (every
     * other checklist is already done by then), but defensive regardless.
     */
    private function timelineStepCompletedAt(array $step): Carbon
    {
        $dateString = ! empty($step['rich']) ? ($step['approvedAt'] ?? null) : ($step['date'] ?? null);

        if ($dateString) {
            try {
                return Carbon::createFromFormat('M d, Y g:i A', $dateString);
            } catch (\Throwable) {
                // Falls through to the sentinel below.
            }
        }

        return Carbon::create(9999, 12, 31, 23, 59, 59);
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

        // A General Signatory IS a Checklist Clearance Signatory — its raw
        // entry is built in the EXACT SAME shape as a department checklist's
        // entry above (`employeeId`/`department`/`signatory`/`signatureUser`/
        // `isApproved`/`approvedAt`/`remarksLabel`) and merged into
        // `$signatoryEntries` BEFORE deduping, not appended as a second,
        // separate table section afterward. Appending it afterward used to
        // produce a genuine duplicate row: an employee who is both an
        // ordinary checklist's Clearance Signatory AND a General Signatory
        // (e.g. Rodelio Cabanlong San Juan — IT Checklist + General
        // Signatory) got one row from `dedupeSignatoryEntries()` for the
        // checklist and a SECOND, un-deduped row here for the General
        // Signatory role, both showing the same department/name. Merging
        // first lets `dedupeSignatoryEntries()`'s existing `groupBy(employeeId)`
        // fold them into the single row they actually are.
        //
        // Its signature/date/remarks are sourced from the same
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

        $generalSignatoryEntries = $offboardingRequest->generalSignatories
            ->map(function (GeneralSignatory $generalSignatory) use ($generalSignatoryApprovalsById) {
                $clearanceSignatory = $generalSignatory->clearanceSignatory;
                $approval = $generalSignatoryApprovalsById->get($generalSignatory->id);
                // A declined General Signatory approval is now On Hold, not
                // completed — same reasoning as the checklist rows above;
                // see `buildEmployeeClearanceStatuses()`'s own gate. This raw
                // `isApproved`/`approvedAt`/`remarksLabel` is only the
                // defensive single-entry-group fallback inside
                // `dedupeSignatoryEntries()` — the merged row's actual
                // displayed status always comes from `$employeeClearanceStatuses`
                // once a real employee is resolved, exactly like every other
                // raw entry.
                $isApproved = $approval?->status === 'approved';

                return [
                    'employeeId' => $clearanceSignatory?->id,
                    'department' => $clearanceSignatory?->department ?? '—',
                    'signatory' => $clearanceSignatory?->name ?? '—',
                    'signatureUser' => $clearanceSignatory?->user,
                    'isApproved' => $isApproved,
                    'approvedAt' => $isApproved ? $approval?->approved_at : null,
                    'remarksLabel' => $approval?->clearanceStatusLabel() ?? 'Pending',
                ];
            })
            ->values();

        $rows = $this->dedupeSignatoryEntries($signatoryEntries->concat($generalSignatoryEntries), $employeeClearanceStatuses);

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
     * (if uploaded) when they hold NO responsibility at all anywhere on this
     * request, including no Immediate Head checklist row of their own
     * (`$status === null`); otherwise gated SOLELY on their own Immediate
     * Head checklist's own approval (`$status['immediateHeadDone']`) — never
     * on an unrelated department checklist (e.g. "IT Checklist") or a
     * General Signatory role this same person also happens to hold. Those
     * other responsibilities are gated completely independently (see
     * `buildEmployeeClearanceStatuses()`'s own `allCleared` computation) —
     * approving any of them must never make this row show (or vice versa
     * make it withhold) a signature it hasn't actually earned, and
     * approving the Immediate Head checklist must never do the reverse.
     *
     * @param  ?array{allCleared: bool, label: string, clearedAt: ?\Illuminate\Support\Carbon, immediateHeadDone: bool, immediateHeadLabel: string, immediateHeadClearedAt: ?\Illuminate\Support\Carbon}  $status
     */
    private function immediateHeadSignatureDataUri(Employee $immediateHead, ?array $status): ?string
    {
        if ($status === null || $status['immediateHeadDone']) {
            return $this->signatureDataUri($immediateHead->user?->signature_path);
        }

        return null;
    }

    /**
     * @param  ?array{allCleared: bool, label: string, clearedAt: ?\Illuminate\Support\Carbon, immediateHeadDone: bool, immediateHeadLabel: string, immediateHeadClearedAt: ?\Illuminate\Support\Carbon}  $status
     */
    private function immediateHeadApprovedDate(?array $status): ?string
    {
        if ($status === null || ! $status['immediateHeadDone']) {
            return null;
        }

        return $status['immediateHeadClearedAt']?->format('M d, Y');
    }

    /**
     * With no Immediate Head checklist row of their own at all, the
     * Immediate Head row has no status to report (matching today's
     * original, always-blank remarks). Otherwise reflects that ONE
     * checklist's own real status (Pending, Hold, Declined, In Progress,
     * Cleared) — never a sibling department checklist's or General
     * Signatory role's own state, which could otherwise mask or
     * misrepresent whether the Immediate Head checklist itself has actually
     * been acted on.
     *
     * @param  ?array{allCleared: bool, label: string, clearedAt: ?\Illuminate\Support\Carbon, immediateHeadDone: bool, immediateHeadLabel: string, immediateHeadClearedAt: ?\Illuminate\Support\Carbon}  $status
     */
    private function immediateHeadRemarks(?array $status): string
    {
        return $status['immediateHeadLabel'] ?? '';
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
     * `Employee::effectiveClearanceSignatory()` — themselves, unchanged, if
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
            $employee = $assignee?->effectiveClearanceSignatory();

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
     * `Employee::effectiveClearanceSignatory()` by raw employee id instead
     * of a loaded `Employee` — the shape `buildEmployeeClearanceStatuses()`
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

        return $employee?->effectiveClearanceSignatory()->id;
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
     * anywhere on this request.
     *
     * The employee's OWN Immediate Head checklist (if they have one) is
     * tracked completely separately from everything else (`immediateHeadDone`/
     * `immediateHeadLabel`/`immediateHeadClearedAt` below) and gates ONLY
     * the dedicated Immediate Head row — never merged with their other
     * responsibilities in either direction. Approving the Immediate Head
     * checklist never satisfies an unrelated department checklist or
     * General Signatory role the same employee also holds, and approving
     * any of THOSE never satisfies the Immediate Head checklist either —
     * each role independently requires its own approval.
     *
     * Every OTHER responsibility (checklist ownership other than the
     * Immediate Head checklist, item-level Task Assignee work, General
     * Signatory approval) requires ALL of them complete before `allCleared`
     * is true — consulted identically by `dedupeSignatoryEntries()` and the
     * General Signatory row in `buildData()`.
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

        // The employee's OWN Immediate Head checklist unit, kept entirely
        // SEPARATE from `$unitsByEmployee` — at most one per employee per
        // request, so a plain keyed array (not a collection) is enough. The
        // dedicated Immediate Head row (see `immediateHeadSignatureDataUri()`
        // etc.) consults ONLY this, and `$unitsByEmployee` below never
        // consults it either — approving the Immediate Head checklist must
        // NEVER satisfy (or block) an unrelated IT Checklist/General
        // Signatory responsibility the same employee also holds, and vice
        // versa. Each role independently satisfies only its own required
        // condition(s); no role's approval is ever borrowed to unlock
        // another. (An earlier revision of this method let approving the
        // Immediate Head checklist early-unlock every other responsibility
        // the same employee held — confirmed wrong against real data: Rodelio
        // Cabanlong San Juan's IT Checklist + General Signatory row showed as
        // cleared the moment his separate Immediate Head checklist was
        // approved, despite the IT Checklist itself still being pending.)
        //
        // An earlier version of this method tried to express "Immediate Head
        // or Core/Primary checklist" as the unlock trigger, using
        // `ChecklistTemplate.sequence_type === SEQUENCE_TYPE_PRIMARY` as the
        // "Core/Primary" half — but that flag is an unrelated Sync-workflow
        // ordering tier that an ORDINARY department checklist (e.g. "IT
        // Checklist") can also carry, which incorrectly let approving THAT
        // checklist alone unlock a still-unapproved Immediate Head row too.
        // `is_immediate_head_checklist` alone is the only correct signal.
        /** @var array<int, array{done: bool, label: string, completedAt: mixed}> $immediateHeadUnitByEmployee */
        $immediateHeadUnitByEmployee = [];

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
                $done = $approver->status === 'approved';
                $label = $approver->clearanceStatusLabel(includeOverdue: false);

                if ($approver->checklistTemplate?->is_immediate_head_checklist) {
                    $immediateHeadUnitByEmployee[$approver->employee_id] = [
                        'done' => $done,
                        'label' => $label,
                        'completedAt' => $approver->approved_at,
                    ];
                } else {
                    $addUnit($approver->employee_id, $done, $label, $approver->approved_at);
                }
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

        // Union of employee ids from BOTH accumulators — an employee who is
        // PURELY an Immediate Head with no other responsibility at all on
        // this request would otherwise be entirely absent from this map
        // (they'd have no entry in `$unitsByEmployee`), which would wrongly
        // fall back to the "no responsibility anywhere" unconditional-
        // signature path in `immediateHeadSignatureDataUri()` instead of
        // actually gating on their own checklist's real status.
        $employeeIds = collect(array_keys($unitsByEmployee))
            ->merge(array_keys($immediateHeadUnitByEmployee))
            ->unique()
            ->values();

        return $employeeIds->mapWithKeys(function (int $employeeId) use ($unitsByEmployee, $immediateHeadUnitByEmployee) {
            $units = $unitsByEmployee[$employeeId] ?? collect();
            $immediateHeadUnit = $immediateHeadUnitByEmployee[$employeeId] ?? null;
            $immediateHeadDone = $immediateHeadUnit['done'] ?? false;

            // Every OTHER responsibility (a department checklist, a General
            // Signatory role) requires ALL of its own units done, completely
            // independent of the employee's separately-tracked Immediate
            // Head checklist (`$immediateHeadDone`, read only by the
            // dedicated Immediate Head row above) — approving one role must
            // never satisfy another.
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

            $clearedAt = $allCleared
                ? $units->pluck('completedAt')->filter()->sortByDesc(fn ($completedAt) => $completedAt->timestamp)->first()
                : null;

            return [$employeeId => [
                'allCleared' => $allCleared,
                'label' => $label,
                'clearedAt' => $clearedAt,
                // Consulted ONLY by the dedicated Immediate Head row's own
                // helpers below — never by `dedupeSignatoryEntries()` or the
                // General Signatory row, which keep reading the three keys
                // above exactly as they always have.
                'immediateHeadDone' => $immediateHeadDone,
                'immediateHeadLabel' => $immediateHeadUnit['label'] ?? 'Pending',
                'immediateHeadClearedAt' => $immediateHeadDone ? ($immediateHeadUnit['completedAt'] ?? null) : null,
            ]];
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
