<?php

namespace App\Http\Controllers;

use App\Mail\ChecklistSignatoryAnnouncementMail;
use App\Models\EmailTemplate;
use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\GeneralSignatoryApprovalToken;
use App\Models\OffboardingRequestGeneralSignatory;
use App\Models\User;
use App\Services\ChecklistApprovalNotifier;
use App\Services\ChecklistCompletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

/**
 * A General Signatory's approval action — in-app Submit and the emailed
 * "Approve" link — kept entirely separate from `ApprovalController` so the
 * checklist Clearance Signatory workflow (that controller, its token model,
 * its routes) is never touched by this feature. Mirrors that controller's
 * email-approval conventions (hashed token + row id lookup, re-validated
 * inside a row-locked transaction, idempotent on an already-approved link)
 * but operates entirely on `OffboardingRequestGeneralSignatory` /
 * `GeneralSignatoryApprovalToken` instead.
 */
class GeneralSignatoryApprovalController extends Controller
{
    /**
     * Fixed-name email template for the "you're a Clearance Signatory on
     * this offboarding request" notice — same template
     * `ChecklistApprovalNotifier::notifyGeneralSignatories()` sends at
     * request-creation time, kept as its own copy here (this controller
     * never depends on that service beyond the one shared resend method)
     * so `remind()` below can default to it by name.
     */
    private const GENERAL_SIGNATORY_NOTIFICATION_TEMPLATE = 'General Signatory Offboarding Notification';

    /**
     * Shown wherever a General Signatory's clearance is blocked for lack of
     * an uploaded e-signature — same rule, same wording convention, as
     * `ApprovalController::MISSING_SIGNATURE_MESSAGE`.
     */
    private const MISSING_SIGNATURE_MESSAGE = 'Please upload your e-signature before clearing this offboarding request.';

    /**
     * The message shown for each possible state of the emailed approval
     * link — shared between the confirmation page and the JSON the actual
     * approval endpoint returns, same convention as
     * `ApprovalController::EMAIL_APPROVAL_MESSAGES`.
     */
    private const EMAIL_APPROVAL_MESSAGES = [
        'invalid' => 'This approval link is invalid or has expired.',
        'already_approved' => 'This offboarding request has already been cleared.',
        'no_signature' => self::MISSING_SIGNATURE_MESSAGE . ' Log in to your account, upload it from your Profile page, then use this link again.',
        'confirm' => 'Please confirm to approve this offboarding request.',
        'approved' => 'The offboarding request was successfully cleared.',
    ];

    /**
     * In-app Submit — the Approvals page's General Signatory modal posts
     * here. Authorized to the assignment's own configured Clearance
     * Signatory (or an admin), same authorization shape as
     * `ApprovalController::authorizeAssignment()`.
     */
    public function approve(Request $request, OffboardingRequestGeneralSignatory $generalSignatoryApproval): RedirectResponse
    {
        $this->authorizeAssignment($generalSignatoryApproval);

        if (! auth()->user()->hasUsableSignature()) {
            return redirect()->route('profile')->with('error', self::MISSING_SIGNATURE_MESSAGE);
        }

        abort_unless($generalSignatoryApproval->status === 'pending', 422, 'This has already been actioned.');

        // Same shape/limit as `ApprovalController::approve()`'s own
        // `remarks` input — optional, never required to clear the request.
        $validated = $request->validate([
            'remarks' => ['nullable', 'string', 'max:2000'],
        ]);

        DB::transaction(function () use ($generalSignatoryApproval, $validated) {
            $locked = OffboardingRequestGeneralSignatory::whereKey($generalSignatoryApproval->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            abort_if(! $locked, 422, 'This has already been actioned.');

            $this->finalizeApproval($locked, auth()->user(), $validated['remarks'] ?? null);
        });

        return back()->with('success', $generalSignatoryApproval->offboardingRequest->employee->name . '\'s offboarding request was cleared.');
    }

    /**
     * In-app Decline — the General Signatory equivalent of
     * `ApprovalController::decline()`, same completed-action semantics:
     * requires an e-signature (declining is still a signed-off decision)
     * and a mandatory reason, never blocks the offboarding request, and
     * counts as this General Signatory's required action being done (see
     * `ChecklistCompletionService`'s gates, widened to accept 'declined'
     * alongside 'approved').
     */
    public function decline(Request $request, OffboardingRequestGeneralSignatory $generalSignatoryApproval): RedirectResponse|JsonResponse
    {
        $this->authorizeAssignment($generalSignatoryApproval);

        if (! auth()->user()->hasUsableSignature()) {
            return $request->wantsJson()
                ? response()->json(['message' => self::MISSING_SIGNATURE_MESSAGE], 422)
                : redirect()->route('profile')->with('error', self::MISSING_SIGNATURE_MESSAGE);
        }

        abort_unless($generalSignatoryApproval->status === 'pending', 422, 'This has already been actioned.');

        if ($request->has('reason')) {
            $request->merge(['reason' => trim((string) $request->input('reason'))]);
        }

        $validated = $request->validate([
            'reason' => ['required', 'string', 'max:2000'],
        ], [
            'reason.required' => 'A reason is required to decline this checklist.',
        ]);

        $actor = auth()->user();
        $reason = $validated['reason'];

        DB::transaction(function () use ($generalSignatoryApproval, $actor, $reason) {
            $locked = OffboardingRequestGeneralSignatory::whereKey($generalSignatoryApproval->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            abort_if(! $locked, 422, 'This has already been actioned.');

            $this->finalizeDecline($locked, $actor, $reason);
        });

        $this->sendDeclineNotification($generalSignatoryApproval->fresh(), $actor, $reason);

        if ($request->wantsJson()) {
            return response()->json([
                'message' => 'Declined.',
                'declinedAt' => $generalSignatoryApproval->fresh()->declined_at->format('M d, Y g:i A'),
            ]);
        }

        return back()->with('success', $generalSignatoryApproval->offboardingRequest->employee->name . '\'s General Signatory checklist was declined.');
    }

    /**
     * HR/Admin-only resend of the General Signatory notification email —
     * the "Notify Approver" action on the Offboarding Status/Timeline's
     * General Signatory section, for when the original email was
     * accidentally deleted or is otherwise no longer available. Mirrors
     * `ApprovalController::remind()`'s exact shape (explicit admin check
     * on top of the route's own permission middleware, optional per-click
     * template override, activity logging with sender/recipient/template),
     * so both "Notify Approver" buttons behave identically to admins.
     *
     * Deliberately takes no new input beyond the optional template
     * override and never touches `$generalSignatoryApproval` itself beyond
     * reading it — no new `OffboardingRequestGeneralSignatory` row, no
     * status change — so this can only ever resend the notification for
     * the General Signatory already assigned here, never create a second
     * assignment or redirect the email to anyone else.
     */
    public function remind(Request $request, OffboardingRequestGeneralSignatory $generalSignatoryApproval): RedirectResponse
    {
        abort_unless(auth()->user()->isAdmin(), 403);

        abort_if(
            $generalSignatoryApproval->status === 'approved',
            422,
            'This General Signatory has already cleared — no notification needed.'
        );

        $validated = $request->validate([
            'email_template_id' => ['nullable', Rule::exists('email_templates', 'id')->where('is_active', true)],
        ]);

        $emailTemplate = ($validated['email_template_id'] ?? null)
            ? EmailTemplate::find($validated['email_template_id'])
            : EmailTemplate::where('is_active', true)
                ->where('template_name', self::GENERAL_SIGNATORY_NOTIFICATION_TEMPLATE)
                ->latest('updated_at')
                ->first();

        if (! $emailTemplate) {
            return back()->with('error', 'No "' . self::GENERAL_SIGNATORY_NOTIFICATION_TEMPLATE . '" email template found. Please create one first.');
        }

        $generalSignatoryApproval->loadMissing('generalSignatory.clearanceSignatory', 'offboardingRequest.employee');
        $clearanceSignatory = $generalSignatoryApproval->generalSignatory->clearanceSignatory;

        if (! $clearanceSignatory?->email || ! filter_var($clearanceSignatory->email, FILTER_VALIDATE_EMAIL)) {
            return back()->with('error', 'This General Signatory has no valid email address on file.');
        }

        $offboardingRequest = $generalSignatoryApproval->offboardingRequest;

        try {
            // Email validity was already checked above — this only fails
            // now if `$clearanceSignatory` itself somehow vanished between
            // the check and here, which the transaction-free, single-read
            // nature of this action makes effectively impossible.
            app(ChecklistApprovalNotifier::class)->resendGeneralSignatoryNotification($generalSignatoryApproval, $emailTemplate);

            $offboardingRequest->activities()->create([
                'user_id' => auth()->id(),
                'offboarding_request_general_signatory_id' => $generalSignatoryApproval->id,
                'action' => 'general_signatory_reminder_sent',
                'status' => $offboardingRequest->status,
                'comment' => 'Sent to: ' . $clearanceSignatory->name . ' (using template: ' . $emailTemplate->template_name . ')',
            ]);

            return redirect()
                ->route('offboardees.index', ['offboardee' => $offboardingRequest->employee_id])
                ->with('success', 'Notification resent to ' . $clearanceSignatory->name . '.');
        } catch (\Throwable $e) {
            Log::error('Failed to resend General Signatory offboarding notification email.', [
                'offboarding_request_general_signatory_id' => $generalSignatoryApproval->id,
                'recipient' => $clearanceSignatory->email,
                'exception' => $e->getMessage(),
            ]);

            return back()->with('error', 'Failed to resend the notification email.');
        }
    }

    /**
     * Public confirmation page for the "Approve" link embedded in the
     * General Signatory Offboarding Notification email — reachable while
     * logged out, same as `ApprovalController::showEmailApproval()`. Only
     * validates/displays state; nothing is approved until the page's own
     * confirmation triggers `confirmEmailApproval()`.
     */
    public function showEmailApproval(int $id, string $token): View
    {
        [$state, $assignment] = $this->resolveEmailApprovalState($id, $token);

        return view('pages.approvals.general-signatory-email-confirm', [
            'title' => 'General Signatory Approval',
            'state' => $state,
            'message' => self::EMAIL_APPROVAL_MESSAGES[$state],
            'confirmUrl' => route('general-signatory-approval.confirm', ['id' => $id, 'token' => $token]),
            'offboardeeName' => $assignment?->offboardingRequest->employee->name,
            'offboardeeEmployeeCode' => $assignment?->offboardingRequest->employee->employee_code,
            'generalSignatoryName' => $assignment?->generalSignatory->clearanceSignatory?->name,
            'tasks' => $assignment?->generalSignatory->tasks->map(fn ($task) => [
                'title' => $task->title,
                'assigneeName' => $task->signatory?->name,
            ])->values()->all() ?? [],
        ]);
    }

    /**
     * The actual approval, triggered only once the General Signatory
     * confirms on the page above. Re-validates and re-locks the row from
     * scratch, exactly like `ApprovalController::confirmEmailApproval()`,
     * so a double-click or two tabs on the same link can never approve
     * twice.
     */
    public function confirmEmailApproval(int $id, string $token): JsonResponse
    {
        [$state, $assignment] = $this->resolveEmailApprovalState($id, $token);

        if ($state === 'confirm' && $assignment) {
            $clearanceSignatory = $assignment->generalSignatory->clearanceSignatory;

            // The General Signatory might never have logged in before —
            // they only need an email address to have received this link —
            // so their account is guaranteed to exist here, same convention
            // `ChecklistApprovalNotifier::notifyGeneralSignatories()` already
            // uses when it first emails them.
            $actor = $clearanceSignatory ? User::findOrCreateGeneralSignatory(
                $clearanceSignatory,
                $this->hasActiveGroupMembers($clearanceSignatory)
            ) : null;

            $state = DB::transaction(function () use ($assignment, $actor) {
                $locked = OffboardingRequestGeneralSignatory::whereKey($assignment->id)
                    ->where('status', 'pending')
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    return 'already_approved';
                }

                $this->finalizeApproval($locked, $actor);

                return 'approved';
            });
        }

        return response()->json([
            'state' => $state,
            'message' => self::EMAIL_APPROVAL_MESSAGES[$state],
        ]);
    }

    /**
     * Validates the emailed link (hash-verified against `$token`, not
     * expired — same convention as `ChecklistApprovalToken`/
     * `ApprovalController::resolveEmailApprovalState()`) and resolves it to
     * the current state of the `OffboardingRequestGeneralSignatory` row it
     * points at. The token's own FK is the only source of which
     * request/signatory pair this resolves to.
     *
     * @return array{0: string, 1: ?OffboardingRequestGeneralSignatory}
     */
    private function resolveEmailApprovalState(int $id, string $token): array
    {
        $approvalToken = GeneralSignatoryApprovalToken::find($id);

        if (! $approvalToken || ! $approvalToken->isValid() || ! Hash::check($token, $approvalToken->token)) {
            return ['invalid', null];
        }

        $assignment = $approvalToken->assignment;

        if (! $assignment) {
            return ['invalid', null];
        }

        $assignment->load(['offboardingRequest.employee', 'generalSignatory.clearanceSignatory', 'generalSignatory.tasks.signatory']);

        if ($assignment->status === 'approved') {
            return ['already_approved', $assignment];
        }

        // Same e-signature gate `approve()` enforces in-app, applied here
        // too so the emailed link can never finalize a clearance without
        // one either — see `ApprovalController::resolveEmailApprovalState()`'s
        // matching docblock for why this is looked up by username instead
        // of `User::findOrCreateGeneralSignatory()` (no need to create an
        // account just to answer "does one already exist with a signature").
        $clearanceSignatory = $assignment->generalSignatory->clearanceSignatory;
        $hasSignature = $clearanceSignatory
            && (User::firstWhere('username', $clearanceSignatory->employee_code_digits)?->hasUsableSignature() ?? false);

        if (! $hasSignature) {
            return ['no_signature', $assignment];
        }

        return ['confirm', $assignment];
    }

    /**
     * `$remarks` is only ever populated from the in-app Submit form
     * (`approve()`) — the emailed one-click link (`confirmEmailApproval()`)
     * has no form to collect one, so it always passes null here, leaving
     * `remarks` unset for that path exactly like every other optional field
     * this method doesn't receive from the email flow. Stored on THIS
     * assignment's own row only — every other approver/signatory's remarks
     * (`OffboardingRequestApprover.approval_remarks`,
     * `OffboardingRequestFinalApproval.remarks`) live on their own separate
     * rows and are never touched here.
     */
    private function finalizeApproval(OffboardingRequestGeneralSignatory $assignment, ?User $actor, ?string $remarks = null): void
    {
        $assignment->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $actor?->id,
            'remarks' => $remarks,
        ]);

        $offboardingRequest = $assignment->offboardingRequest;
        $signatoryName = $assignment->generalSignatory->clearanceSignatory?->name ?? 'Unknown';

        $offboardingRequest->activities()->create([
            'user_id' => $actor?->id,
            'action' => 'general_signatory_approved',
            'status' => $offboardingRequest->status,
            'comment' => 'Cleared by General Signatory: ' . $signatoryName . ($remarks ? '. Remarks: ' . $remarks : ''),
        ]);

        // A General Signatory can be the LAST outstanding requirement —
        // every regular (and even Final Pay) checklist may already be fully
        // approved while this was still pending, since the two tracks are
        // actioned independently. All completion gates below are already
        // idempotent/self-guarding (see `ChecklistCompletionService`'s own
        // docblocks) and simply no-op if their own precondition isn't ALSO
        // satisfied yet, so it's always safe to re-check them all here
        // rather than only from the checklist side.
        $completionService = app(ChecklistCompletionService::class);
        $completionService->checkRegularChecklistsCompletion($offboardingRequest);
        // Advances THIS General Signatory's own Core -> Secondary -> Final
        // Pay staging — the actual trigger for that track, since it only
        // ever progresses off a General Signatory's own approval.
        $completionService->checkRegularGeneralSignatoriesCompletion($offboardingRequest);
        $completionService->checkFinalPayCompletion($offboardingRequest);
    }

    /**
     * The decline counterpart to `finalizeApproval()` — same shape, same
     * completion cascade (a decline is just as much "this General
     * Signatory's own track can advance" as an approval is), different
     * terminal status/columns and activity action.
     */
    private function finalizeDecline(OffboardingRequestGeneralSignatory $assignment, ?User $actor, string $reason): void
    {
        $assignment->update([
            'status' => 'declined',
            'declined_at' => now(),
            'decline_reason' => $reason,
        ]);

        $offboardingRequest = $assignment->offboardingRequest;
        $signatoryName = $assignment->generalSignatory->clearanceSignatory?->name ?? 'Unknown';

        $offboardingRequest->activities()->create([
            'user_id' => $actor?->id,
            'action' => 'general_signatory_declined',
            'status' => $offboardingRequest->status,
            'comment' => 'Declined by General Signatory: ' . $signatoryName . '. Reason: ' . $reason,
        ]);

        $completionService = app(ChecklistCompletionService::class);
        $completionService->checkRegularChecklistsCompletion($offboardingRequest);
        $completionService->checkRegularGeneralSignatoriesCompletion($offboardingRequest);
        $completionService->checkFinalPayCompletion($offboardingRequest);
    }

    /**
     * Emails admins and this request's creator that this General Signatory
     * has declined — same dedicated template and recipient rule as
     * `ApprovalController::sendDeclineNotification()` (kept as a separate
     * copy, matching this controller's own established independence from
     * that one). A General Signatory has no `ChecklistTemplate` of its own
     * (unlike a Clearance Signatory's checklist), so "checklist name"/
     * "type" are represented generically here instead.
     */
    private function sendDeclineNotification(OffboardingRequestGeneralSignatory $generalSignatoryApproval, User $actor, string $declineReason): void
    {
        $offboardingRequest = $generalSignatoryApproval->offboardingRequest;
        $offboardee = $offboardingRequest->employee;
        $generalSignatory = $generalSignatoryApproval->generalSignatory;

        $recipients = User::role(User::ROLE_ADMIN)->get();

        if ($offboardingRequest->creator && $offboardingRequest->creator->email) {
            $recipients = $recipients->push($offboardingRequest->creator);
        }

        $recipients = $recipients->filter(fn (User $recipient) => $recipient->email && filter_var($recipient->email, FILTER_VALIDATE_EMAIL))
            ->unique('id');

        if ($recipients->isEmpty()) {
            return;
        }

        $emailTemplate = EmailTemplate::where('is_active', true)
            ->where('template_name', 'Checklist Signatory Declined')
            ->latest('updated_at')
            ->first();

        if (! $emailTemplate) {
            Log::warning('No "Checklist Signatory Declined" email template found — decline notifications were not sent.', [
                'offboarding_request_general_signatory_id' => $generalSignatoryApproval->id,
            ]);

            return;
        }

        $declinedAt = $generalSignatoryApproval->declined_at->format('M d, Y g:i A');
        $checklistType = $generalSignatory?->is_final_pay_signatory
            ? 'Final Pay'
            : ($generalSignatory?->sequence_type === 'secondary' ? 'Secondary' : 'Core/Primary');

        foreach ($recipients as $recipient) {
            try {
                [$subject, $body] = $emailTemplate->render(
                    approverName: $recipient->name,
                    offboardeeName: $offboardee->name,
                    employeeNumber: $offboardee->employee_code,
                    department: $offboardee->department,
                    position: $offboardee->designation,
                    separationDate: $offboardingRequest->last_working_day?->format('M d, Y'),
                    reason: $offboardingRequest->reason,
                    checklistName: 'General Signatory Clearance',
                    checklistType: $checklistType,
                    checklistStatus: $generalSignatoryApproval->clearanceStatusLabel(),
                    offboardingRequestId: (string) $offboardingRequest->id,
                    declinedBy: $actor->name,
                    declinedAt: $declinedAt,
                    declineReason: $declineReason,
                    signatoryType: 'General Signatory',
                );

                Mail::to($recipient->email)->send(new ChecklistSignatoryAnnouncementMail($subject, $body));
            } catch (\Throwable $e) {
                Log::error('Failed to send General Signatory decline notification.', [
                    'offboarding_request_general_signatory_id' => $generalSignatoryApproval->id,
                    'recipient' => $recipient->email,
                    'exception' => $e->getMessage(),
                ]);
            }
        }
    }

    /**
     * Same eligibility check `ChecklistApprovalNotifier::notifyGeneralSignatories()`
     * uses to decide a freshly created account's role — kept here as its
     * own small copy (this controller never depends on that service) rather
     * than exposing a public method on it just for this one call.
     */
    private function hasActiveGroupMembers(Employee $clearanceSignatory): bool
    {
        return EmployeeGroup::where('group_head_employee_id', $clearanceSignatory->id)
            ->whereHas('employees', fn ($q) => $q->where('status', 'active'))
            ->exists();
    }

    /**
     * Blocks Submit on an assignment the user isn't the assigned General
     * Signatory for, even if they reach it by guessing/typing the URL —
     * same shape as `ApprovalController::authorizeAssignment()`.
     */
    private function authorizeAssignment(OffboardingRequestGeneralSignatory $generalSignatoryApproval): void
    {
        $user = auth()->user();

        if ($user->isAdmin()) {
            return;
        }

        abort_unless(
            $generalSignatoryApproval->generalSignatory->clearance_signatory_id === $user->employee?->id,
            403
        );
    }
}
