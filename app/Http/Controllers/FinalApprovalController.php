<?php

namespace App\Http\Controllers;

use App\Mail\FinalApprovalCompletedMail;
use App\Mail\FinalApprovalRequestMail;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\FinalApprovalToken;
use App\Models\FinalApprover;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestFinalApproval;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

/**
 * The Final Approval workflow — a completed offboarding request's one-time
 * sign-off by the active Final Signatory, triggered from the Offboardee
 * page's "Final Approval" button. Deliberately its own controller
 * (independent of `ApprovalController`/`GeneralSignatoryApprovalController`)
 * even though the email-approval shape mirrors them closely, since this
 * feature's data (`OffboardingRequestFinalApproval`/`FinalApprovalToken`)
 * and its "only one process, ever, per request" invariant have no
 * equivalent in either of those.
 */
class FinalApprovalController extends Controller
{
    private const EMAIL_TEMPLATE_NAME = 'Final Approval Request';

    private const APPROVAL_TOKEN_LIFETIME_DAYS = 30;

    private const MISSING_SIGNATURE_MESSAGE = 'Please upload your e-signature before giving Final Approval.';

    private const EMAIL_APPROVAL_MESSAGES = [
        'invalid' => 'This approval link is invalid or has expired.',
        'already_approved' => 'This offboarding request has already received Final Approval.',
        'not_actionable' => 'The configured Final Signatory has changed since this request was sent. Please ask an admin to resend the Final Approval request.',
        'no_signature' => self::MISSING_SIGNATURE_MESSAGE . ' Log in to your account, upload it from your Profile page, then use this link again.',
        'confirm' => 'Please confirm to give Final Approval for this offboarding request.',
        'approved' => 'Final Approval was successfully recorded.',
    ];

    /**
     * "Final Approval" button on the Offboardee page — HR/Admin-only
     * (explicit check here, same defense-in-depth convention as
     * `ApprovalController::remind()`/`GeneralSignatoryApprovalController::remind()`,
     * since the route's own `permission:final-approval.send` middleware
     * alone could in principle be granted to a broader role later). Only
     * ever creates/updates the ONE `OffboardingRequestFinalApproval` row
     * this request can ever have (unique constraint) — a repeat click
     * while still pending re-sends against that same row (refreshing which
     * Final Signatory it targets, in case the active one changed since);
     * once approved, further sends are refused outright.
     */
    public function send(OffboardingRequest $offboardingRequest): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        abort_unless(
            $offboardingRequest->status === 'completed',
            422,
            'Final Approval can only be initiated once the offboarding request is fully completed.'
        );

        $activeFinalApprover = FinalApprover::with('employee')->where('is_active', true)->first();

        if (! $activeFinalApprover) {
            return back()->with('error', 'No active Final Signatory is configured. Please set one on the Offboarding Checklist page first.');
        }

        $existing = $offboardingRequest->finalApproval;

        if ($existing?->status === 'approved') {
            return back()->with('error', 'This offboarding request has already received Final Approval.');
        }

        $admin = auth()->user();

        $finalApproval = DB::transaction(function () use ($offboardingRequest, $activeFinalApprover, $admin) {
            $finalApproval = OffboardingRequestFinalApproval::firstOrCreate(
                ['offboarding_request_id' => $offboardingRequest->id],
                ['employee_id' => $activeFinalApprover->employee_id, 'status' => 'pending']
            );

            // Re-targets the SAME row at whoever is active right now — a
            // resend after the config changed must reach the current Final
            // Signatory, not whoever it was originally sent to.
            $finalApproval->update([
                'employee_id' => $activeFinalApprover->employee_id,
                'initiated_by' => $admin->id,
                'initiated_at' => now(),
            ]);

            return $finalApproval;
        });

        $signatoryEmployee = $activeFinalApprover->employee;

        if (! $this->sendEmail($finalApproval, $signatoryEmployee)) {
            return back()->with('error', 'The Final Signatory (' . $signatoryEmployee->name . ') has no valid email address on file.');
        }

        $offboardingRequest->activities()->create([
            'user_id' => $admin->id,
            'offboarding_request_final_approval_id' => $finalApproval->id,
            'action' => 'final_approval_sent',
            'status' => $offboardingRequest->status,
            'comment' => 'Sent to: ' . $signatoryEmployee->name . ' (using template: ' . self::EMAIL_TEMPLATE_NAME . ')',
        ]);

        return redirect()
            ->route('offboardees.index', ['offboardee' => $offboardingRequest->employee_id])
            ->with('success', 'Final Approval request sent to ' . $signatoryEmployee->name . '.');
    }

    /**
     * Public confirmation page for the "Approve" link embedded in the
     * Final Approval Request email — reachable while logged out, same
     * convention as `GeneralSignatoryApprovalController::showEmailApproval()`.
     * Only validates/displays state; nothing is approved until the page's
     * own confirmation triggers `confirmEmailApproval()`. Also where a
     * first-ever visit gets logged as `final_approval_viewed` — the ONLY
     * place in this flow that can observe "opened but not yet acted on".
     */
    public function showEmailApproval(int $id, string $token): View
    {
        [$state, $finalApproval] = $this->resolveEmailApprovalState($id, $token);

        if ($state !== 'invalid' && $finalApproval && ! $finalApproval->first_viewed_at) {
            $finalApproval->update(['first_viewed_at' => now()]);

            $viewerUser = User::firstWhere('username', $finalApproval->employee->employee_code_digits);

            $finalApproval->offboardingRequest->activities()->create([
                'user_id' => $viewerUser?->id,
                'offboarding_request_final_approval_id' => $finalApproval->id,
                'action' => 'final_approval_viewed',
                'status' => $finalApproval->offboardingRequest->status,
            ]);
        }

        $offboardingRequest = $finalApproval?->offboardingRequest;

        return view('pages.final-approval.email-confirm', [
            'title' => 'Final Approval',
            'state' => $state,
            'message' => self::EMAIL_APPROVAL_MESSAGES[$state],
            'confirmUrl' => route('final-approval.confirm', ['id' => $id, 'token' => $token]),
            'offboardeeName' => $offboardingRequest?->employee->name,
            'offboardeeEmployeeCode' => $offboardingRequest?->employee->employee_code,
            'finalSignatoryName' => $finalApproval?->employee->name,
            'clearanceFormUrl' => $offboardingRequest ? route('clearance-form.pdf', $offboardingRequest) : null,
        ]);
    }

    /**
     * The actual approval, triggered only once the Final Signatory
     * confirms on the page above. Re-validates and re-locks the row from
     * scratch (never trusting whatever `showEmailApproval()` rendered
     * moments earlier), so a double-click or two tabs on the same link can
     * never approve twice — the same guard every other email-approval
     * endpoint in this app already applies.
     */
    public function confirmEmailApproval(Request $request, int $id, string $token): JsonResponse
    {
        [$state, $finalApproval] = $this->resolveEmailApprovalState($id, $token);

        // Optional — the Final Signatory's own remark/comment left on the
        // confirmation dialog, same "if applicable" convention as a decline
        // reason elsewhere in this app: most approvals will have none.
        $remarks = $request->string('remarks')->trim()->value() ?: null;

        if ($state === 'confirm' && $finalApproval) {
            $signatoryEmployee = $finalApproval->employee;

            // The Final Signatory might never have logged in before — same
            // convention as every other emailed-approval flow in this app.
            $actor = User::findOrCreateApprover($signatoryEmployee);

            $locked = null;

            $state = DB::transaction(function () use ($finalApproval, $actor, $remarks, &$locked) {
                $locked = OffboardingRequestFinalApproval::whereKey($finalApproval->id)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return 'already_approved';
                }

                $locked->update([
                    'status' => 'approved',
                    'approved_at' => now(),
                    'approved_by' => $actor->id,
                    'remarks' => $remarks,
                ]);

                $offboardingRequest = $locked->offboardingRequest;

                $offboardingRequest->activities()->create([
                    'user_id' => $actor->id,
                    'offboarding_request_final_approval_id' => $locked->id,
                    'action' => 'final_approval_approved',
                    'status' => $offboardingRequest->status,
                    'comment' => 'Final Approval given by: ' . $actor->name . ' (Via Email)'
                        . ($remarks ? ' — Remarks: ' . $remarks : ''),
                ]);

                return 'approved';
            });

            // Sent outside the transaction, same convention as every other
            // notification in this app — a slow/failed mail send must never
            // roll back an otherwise-successful approval. Every step in the
            // request's lifecycle (checklists, General Signatories, Final
            // Approval) is now genuinely done, so this is the one
            // "everything is finished" notice the creator gets.
            if ($state === 'approved' && $locked) {
                $this->notifyRequestCreator($locked->fresh(['offboardingRequest.employee', 'employee']));
            }
        }

        return response()->json([
            'state' => $state,
            'message' => self::EMAIL_APPROVAL_MESSAGES[$state],
        ]);
    }

    /**
     * Emails the offboarding request's original creator (`created_by`) once
     * Final Approval has actually been given — the definitive "every stage
     * is now complete" notice. Mirrors
     * `ChecklistApprovalNotifier::notifyRequestCreatorOfGroupApproval()`'s
     * exact defensive shape (missing/invalid creator email logs a warning
     * rather than throwing) since Final Approval is the very last such
     * notice a request can ever trigger.
     */
    private function notifyRequestCreator(OffboardingRequestFinalApproval $finalApproval): void
    {
        $offboardingRequest = $finalApproval->offboardingRequest;
        $creator = $offboardingRequest->creator;

        if (! $creator || ! $creator->email || ! filter_var($creator->email, FILTER_VALIDATE_EMAIL)) {
            Log::warning('Could not notify offboarding request creator of Final Approval — no valid creator email on file.', [
                'offboarding_request_id' => $offboardingRequest->id,
            ]);

            return;
        }

        $offboardee = $offboardingRequest->employee;
        $finalApprover = $finalApproval->employee;

        try {
            Mail::to($creator->email)->send(new FinalApprovalCompletedMail(
                creatorName: $creator->name,
                offboardeeName: $offboardee->name,
                offboardeeEmployeeCode: $offboardee->employee_code,
                finalApproverName: $finalApprover->name,
                finalApproverEmployeeCode: $finalApprover->employee_code,
                approvedAt: $finalApproval->approved_at->format('M d, Y g:i A'),
                remarks: $finalApproval->remarks,
                viewUrl: route('offboardees.index', ['offboardee' => $offboardingRequest->employee_id]),
            ));
        } catch (\Throwable $e) {
            Log::error('Failed to send Final Approval completion email to the request creator.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'recipient' => $creator->email,
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Validates the emailed link (hash-verified against `$token`, not
     * expired — same convention as every other approval token in this
     * app) and resolves it to the CURRENT state of the
     * `OffboardingRequestFinalApproval` row it points at. Shared by both
     * the GET confirmation page and the POST that actually approves, so
     * they always agree — including the "only the currently active Final
     * Signatory may approve" re-check, which runs here rather than only at
     * `send()` time, so a config change between sending and clicking can
     * never let a now-superseded signatory approve.
     *
     * @return array{0: string, 1: ?OffboardingRequestFinalApproval}
     */
    private function resolveEmailApprovalState(int $id, string $token): array
    {
        $approvalToken = FinalApprovalToken::find($id);

        if (! $approvalToken || ! $approvalToken->isValid() || ! Hash::check($token, $approvalToken->token)) {
            return ['invalid', null];
        }

        $finalApproval = $approvalToken->finalApproval;

        if (! $finalApproval) {
            return ['invalid', null];
        }

        $finalApproval->load('offboardingRequest.employee', 'employee');

        if ($finalApproval->status === 'approved') {
            return ['already_approved', $finalApproval];
        }

        // Only the CURRENTLY active Final Approver may complete this —
        // re-checked live, not just trusted from the row's own snapshot.
        $activeFinalApprover = FinalApprover::where('is_active', true)->first();

        if (! $activeFinalApprover || $activeFinalApprover->employee_id !== $finalApproval->employee_id) {
            return ['not_actionable', $finalApproval];
        }

        // Same e-signature gate every other approval action in this app
        // enforces — looked up by username rather than
        // `User::findOrCreateApprover()` so merely viewing this link never
        // creates an account (see `ApprovalController::resolveEmailApprovalState()`'s
        // matching docblock for why).
        $hasSignature = User::firstWhere('username', $finalApproval->employee->employee_code_digits)?->hasUsableSignature() ?? false;

        if (! $hasSignature) {
            return ['no_signature', $finalApproval];
        }

        return ['confirm', $finalApproval];
    }

    /**
     * Builds and sends the Final Approval Request email — the Clearance
     * Form PDF is always attached; a rasterized PNG "screenshot" of it is
     * attached too when Imagick/Ghostscript are available (see
     * `ClearanceFormController::screenshotPngFromBytes()`), degrading
     * gracefully to PDF-only otherwise rather than blocking the send
     * entirely over a missing image-rendering dependency.
     *
     * @return bool whether the email was actually sent (false = the
     *   signatory has no valid email address on file).
     */
    private function sendEmail(OffboardingRequestFinalApproval $finalApproval, Employee $signatoryEmployee): bool
    {
        if (! $signatoryEmployee->email || ! filter_var($signatoryEmployee->email, FILTER_VALIDATE_EMAIL)) {
            return false;
        }

        $offboardingRequest = $finalApproval->offboardingRequest;
        $offboardee = $offboardingRequest->employee;
        $approveUrl = $this->createApprovalUrl($finalApproval);

        $emailTemplate = EmailTemplate::where('is_active', true)
            ->where('template_name', self::EMAIL_TEMPLATE_NAME)
            ->latest('updated_at')
            ->first();

        if ($emailTemplate) {
            [$subject, $body] = $emailTemplate->render(
                approverName: $signatoryEmployee->name,
                offboardeeName: $offboardee->name,
                employeeNumber: $offboardee->employee_code,
                department: $offboardee->department,
                position: $offboardee->designation,
                separationDate: $offboardingRequest->last_working_day?->format('M d, Y'),
                approveButton: $this->buildApproveButtonHtml($approveUrl),
            );
        } else {
            Log::warning('No "' . self::EMAIL_TEMPLATE_NAME . '" email template found — using built-in fallback content.', [
                'offboarding_request_id' => $offboardingRequest->id,
            ]);

            $subject = 'Final Approval Required — ' . $offboardee->name . ' (' . $offboardee->employee_code . ')';
            $body = '<p>Dear ' . e($signatoryEmployee->name) . ',</p>'
                . '<p>The offboarding process for <strong>' . e($offboardee->name) . ' (' . e($offboardee->employee_code) . ')</strong> has been completed and is ready for your Final Approval.</p>'
                . '<p>The completed Clearance Form is attached to this email for your review.</p>'
                . $this->buildApproveButtonHtml($approveUrl);
        }

        $pdfBytes = app(ClearanceFormController::class)->generatePdfBytes($offboardingRequest);
        $pngBytes = null;

        try {
            $pngBytes = app(ClearanceFormController::class)->screenshotPngFromBytes($pdfBytes);
        } catch (\Throwable $e) {
            Log::warning('Failed to generate Clearance Form screenshot for Final Approval email — sending without it.', [
                'offboarding_request_id' => $offboardingRequest->id,
                'exception' => $e->getMessage(),
            ]);
        }

        Mail::to($signatoryEmployee->email)->send(
            new FinalApprovalRequestMail($subject, $body, $pdfBytes, $offboardee->name, $pngBytes)
        );

        return true;
    }

    private function createApprovalUrl(OffboardingRequestFinalApproval $finalApproval): string
    {
        $rawToken = Str::random(64);

        $approvalToken = FinalApprovalToken::create([
            'offboarding_request_final_approval_id' => $finalApproval->id,
            'token' => Hash::make($rawToken),
            'expires_at' => now()->addDays(self::APPROVAL_TOKEN_LIFETIME_DAYS),
        ]);

        return route('final-approval.show', ['id' => $approvalToken->id, 'token' => $rawToken]);
    }

    private function buildApproveButtonHtml(string $url): string
    {
        return '<p style="margin:24px 0 0;">'
            . '<a href="' . e($url) . '" style="display:inline-block;background-color:#145a3a;color:#ffffff;text-decoration:none;padding:12px 24px;border-radius:8px;font-weight:bold;">'
            . 'Approve'
            . '</a>'
            . '</p>';
    }
}
