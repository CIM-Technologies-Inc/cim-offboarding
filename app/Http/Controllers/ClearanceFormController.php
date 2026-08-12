<?php

namespace App\Http\Controllers;

use App\Models\ChecklistTemplate;
use App\Models\OffboardingRequest;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Response;
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
        $data = $this->buildData($offboardingRequest);

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
        return view('clearance-form.print', $this->buildData($offboardingRequest));
    }

    /**
     * Gathers everything the clearance form template needs. Only reachable
     * once the offboarding process is genuinely, fully completed — which
     * already guarantees every checklist template attached to this request
     * has been approved by its assigned department head (see
     * `ApprovalController::checkRegularChecklistsCompletion()` /
     * `checkFinalPayCompletion()`), so every row below reflects a real
     * approval, never a fabricated one.
     */
    private function buildData(OffboardingRequest $offboardingRequest): array
    {
        abort_unless(
            $offboardingRequest->status === 'completed',
            403,
            'The clearance form is only available once the offboarding process is fully completed.'
        );

        $offboardingRequest->loadMissing(['employee', 'checklistTemplates', 'approvers.employee.user']);

        $employee = $offboardingRequest->employee;

        $rows = $offboardingRequest->checklistTemplates
            ->where('is_active', true)
            ->map(function (ChecklistTemplate $template) use ($offboardingRequest) {
                $approver = $offboardingRequest->approvers->firstWhere('checklist_template_id', $template->id);
                $signatoryEmployee = $approver?->employee;
                $signatureUser = $signatoryEmployee?->user;

                return [
                    'department' => $signatoryEmployee?->department ?? $template->department ?? '—',
                    'signatory' => $signatoryEmployee?->name ?? '—',
                    'signatureDataUri' => $this->signatureDataUri($signatureUser?->signature_path),
                    'date' => $approver?->approved_at?->format('M d, Y'),
                    'remarks' => $approver?->status === 'approved' ? 'Cleared' : '',
                ];
            })
            ->values();

        return [
            'offboardingRequest' => $offboardingRequest,
            'employeeName' => $employee->name,
            'employeeNumber' => $employee->employee_code,
            'position' => $employee->designation,
            'department' => $employee->department,
            'dateHired' => $employee->date_of_joining?->format('M d, Y') ?? '—',
            'separationDate' => $offboardingRequest->last_working_day->format('M d, Y'),
            'rows' => $rows,
        ];
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
}
