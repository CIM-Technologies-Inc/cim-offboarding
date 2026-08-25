<?php

namespace App\Http\Controllers;

use App\Exceptions\FollowUpNotAllowedException;
use App\Models\OffboardingRequestApprover;
use App\Services\ChecklistFollowUpService;
use Illuminate\Http\RedirectResponse;

class EmployeeFollowUpController extends Controller
{
    /**
     * The employee's own "Follow Up" button on one checklist card. Scoped
     * exclusively to the authenticated employee's own checklist — never
     * trusts the route parameter alone, so one employee can never trigger a
     * follow-up on another's offboarding request even by guessing/tampering
     * with the id in the URL. `ChecklistFollowUpService::send()` re-validates
     * the daily cooldown and the request's shared attempt budget itself,
     * locked, immediately before sending — this controller only handles
     * authorization and translating that service's outcome into a flash
     * message.
     */
    public function store(OffboardingRequestApprover $offboardingRequestApprover, ChecklistFollowUpService $service): RedirectResponse
    {
        $employee = auth()->user()->employee;

        abort_if(! $employee, 404);

        abort_unless(
            $offboardingRequestApprover->offboardingRequest->employee_id === $employee->id,
            403
        );

        abort_if(
            $offboardingRequestApprover->status === 'approved',
            422,
            'This checklist has already been completed — there is nothing to follow up on.'
        );

        abort_unless(
            in_array($offboardingRequestApprover->offboardingRequest->status, ['pending', 'in_progress'], true),
            422,
            'This offboarding request is no longer active.'
        );

        $recipient = $offboardingRequestApprover->offboardingRequest->creator;

        try {
            $service->send($offboardingRequestApprover, $employee, $recipient);
        } catch (FollowUpNotAllowedException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with(
            $recipient ? 'success' : 'error',
            $recipient
                ? 'Your follow-up notification has been sent.'
                : 'This request has no HR/Admin contact on file, so the follow-up could not be delivered.'
        );
    }
}
