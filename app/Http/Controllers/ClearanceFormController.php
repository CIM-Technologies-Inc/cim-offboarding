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

        // The Immediate Head has no checklist/approval step of their own by
        // default, so ordinarily their signature (if they've uploaded one to
        // their account) shows unconditionally, with no date/remarks — see
        // the fallback branches in the three helpers below. BUT when this
        // same person is ALSO the assigned Checklist Clearance Signatory of
        // a regular department checklist on this request (matched purely by
        // employee — e.g. the Information Services department head also
        // happens to be this offboardee's Immediate Head), that department
        // row's real `OffboardingRequestApprover` state is the only
        // authoritative record of whether they've actually cleared this
        // request. Showing this row's signature unconditionally while that
        // department row still says "In Progress" would show two
        // conflicting accounts of the very same person's clearance — so in
        // that case this row is synchronized to mirror that real state
        // instead, both ways: since both rows are re-derived fresh from the
        // same `OffboardingRequestApprover` record(s) on every call, an
        // approval recorded via either the Immediate Head's or that
        // department's own combined Approvals-page card (they're already
        // merged into one Submit action there — see
        // `ApprovalController::groupIntoCombinedApprovals()`) is reflected
        // identically here the next time this form is generated.
        $immediateHeadSyncedApprovers = $immediateHead
            ? $offboardingRequest->approvers->filter(
                fn (OffboardingRequestApprover $approver) => $approver->employee_id === $immediateHead->id
                    && ! $approver->checklistTemplate?->is_immediate_head_checklist
            )
            : collect();

        $immediateHeadRow = $immediateHead ? [
            'designation' => 'Immediate Head',
            'signatory' => $immediateHead->name,
            'signatureDataUri' => $this->immediateHeadSignatureDataUri($immediateHead, $immediateHeadSyncedApprovers),
            'date' => $this->immediateHeadApprovedDate($immediateHeadSyncedApprovers),
            'remarks' => $this->immediateHeadRemarks($immediateHeadSyncedApprovers),
        ] : null;

        $rows = $offboardingRequest->checklistTemplates
            ->where('is_active', true)
            // The Immediate Head checklist is already shown once, above this
            // table, via `$immediateHeadRow` — it has no `department` of its
            // own, so leaving it in here would fall back to displaying the
            // signatory's own personal department (e.g. "IT"), producing a
            // confusing second row that looks like a duplicate of that
            // department's real row.
            ->reject(fn (ChecklistTemplate $template) => $template->is_immediate_head_checklist)
            ->map(function (ChecklistTemplate $template) use ($offboardingRequest) {
                $approver = $offboardingRequest->approvers->firstWhere('checklist_template_id', $template->id);
                $signatoryEmployee = $approver?->employee;
                $signatureUser = $signatoryEmployee?->user;
                $isApproved = $approver?->status === 'approved';

                return [
                    'department' => $signatoryEmployee?->department ?? $template->department ?? '—',
                    'signatory' => $signatoryEmployee?->name ?? '—',
                    'signatureDataUri' => $isApproved ? $this->signatureDataUri($signatureUser?->signature_path) : null,
                    'date' => $isApproved ? $approver?->approved_at?->format('M d, Y') : null,
                    'remarks' => $approver?->clearanceStatusLabel() ?? 'Not Assigned',
                ];
            })
            ->values();

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
     * so the two entries can never disagree in wording either.
     *
     * @param  Collection<int, OffboardingRequestApprover>  $syncedApprovers
     */
    private function immediateHeadRemarks(Collection $syncedApprovers): string
    {
        if ($syncedApprovers->isEmpty()) {
            return '';
        }

        return $syncedApprovers
            ->map(fn (OffboardingRequestApprover $approver) => $approver->clearanceStatusLabel())
            ->unique()
            ->implode(', ');
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
