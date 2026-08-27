<?php

namespace App\Http\Controllers;

use App\Models\Employee;
use App\Models\EmployeeGroup;
use App\Models\GeneralSignatoryApprovalToken;
use App\Models\OffboardingRequestGeneralSignatory;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
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
     * The message shown for each possible state of the emailed approval
     * link — shared between the confirmation page and the JSON the actual
     * approval endpoint returns, same convention as
     * `ApprovalController::EMAIL_APPROVAL_MESSAGES`.
     */
    private const EMAIL_APPROVAL_MESSAGES = [
        'invalid' => 'This approval link is invalid or has expired.',
        'already_approved' => 'This offboarding request has already been cleared.',
        'confirm' => 'Please confirm to approve this offboarding request.',
        'approved' => 'The offboarding request was successfully cleared.',
    ];

    /**
     * In-app Submit — the Approvals page's General Signatory modal posts
     * here. Authorized to the assignment's own configured Clearance
     * Signatory (or an admin), same authorization shape as
     * `ApprovalController::authorizeAssignment()`.
     */
    public function approve(OffboardingRequestGeneralSignatory $generalSignatoryApproval): RedirectResponse
    {
        $this->authorizeAssignment($generalSignatoryApproval);

        abort_unless($generalSignatoryApproval->status === 'pending', 422, 'This has already been actioned.');

        DB::transaction(function () use ($generalSignatoryApproval) {
            $locked = OffboardingRequestGeneralSignatory::whereKey($generalSignatoryApproval->id)
                ->where('status', 'pending')
                ->lockForUpdate()
                ->first();

            abort_if(! $locked, 422, 'This has already been actioned.');

            $this->finalizeApproval($locked, auth()->user());
        });

        return back()->with('success', $generalSignatoryApproval->offboardingRequest->employee->name . '\'s offboarding request was cleared.');
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

        return ['confirm', $assignment];
    }

    private function finalizeApproval(OffboardingRequestGeneralSignatory $assignment, ?User $actor): void
    {
        $assignment->update([
            'status' => 'approved',
            'approved_at' => now(),
            'approved_by' => $actor?->id,
        ]);

        $offboardingRequest = $assignment->offboardingRequest;
        $signatoryName = $assignment->generalSignatory->clearanceSignatory?->name ?? 'Unknown';

        $offboardingRequest->activities()->create([
            'user_id' => $actor?->id,
            'action' => 'general_signatory_approved',
            'status' => $offboardingRequest->status,
            'comment' => 'Cleared by General Signatory: ' . $signatoryName,
        ]);
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
