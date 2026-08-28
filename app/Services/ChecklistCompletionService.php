<?php

namespace App\Services;

use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\OffboardingRequest;
use App\Models\OffboardingRequestApprover;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class ChecklistCompletionService
{
    /**
     * Email template used to notify Final Pay Checklist approvers once every
     * regular checklist has been approved.
     */
    private const FINAL_PAY_APPROVAL_TEMPLATE = 'Final Pay Checklist Approval';

    /**
     * Once every regular (non-final-pay) checklist assignment on this
     * request is approved, either finish up as before (if no Final Pay
     * Checklist is configured) or attach + notify the Final Pay Checklist
     * approver(s). Guarded by `final_pay_notified_at` under a row lock so
     * two near-simultaneous approvals can never trigger this twice.
     */
    public function checkRegularChecklistsCompletion(OffboardingRequest $offboardingRequest): void
    {
        $allRegularApproved = $offboardingRequest->approvers()
            ->whereHas('checklistTemplate', fn ($q) => $q->where('is_final_pay_checklist', false))
            ->where('status', '!=', 'approved')
            ->doesntExist();

        if (! $allRegularApproved) {
            return;
        }

        DB::transaction(function () use ($offboardingRequest) {
            $locked = OffboardingRequest::whereKey($offboardingRequest->id)
                ->whereNull('final_pay_notified_at')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                // Already claimed by a concurrent approval — nothing more to do here.
                return;
            }

            $locked->activities()->create([
                'action' => 'all_checklists_approved',
                'status' => $locked->status,
            ]);

            $finalPayTemplates = ChecklistTemplate::where('is_active', true)
                ->where('is_final_pay_checklist', true)
                ->applicableToDepartment($locked->employee->department)
                ->with(['departmentHead', 'items.signatory'])
                ->get();

            $locked->update(['final_pay_notified_at' => now()]);

            if ($finalPayTemplates->isEmpty()) {
                $locked->update(['status' => 'in_progress']);

                return;
            }

            $emailTemplate = EmailTemplate::where('is_active', true)
                ->where('template_name', self::FINAL_PAY_APPROVAL_TEMPLATE)
                ->latest('updated_at')
                ->first();

            if (! $emailTemplate) {
                Log::warning('No "' . self::FINAL_PAY_APPROVAL_TEMPLATE . '" email template found — final pay approvers were not emailed.', [
                    'offboarding_request_id' => $locked->id,
                ]);
            }

            $notified = app(ChecklistApprovalNotifier::class)->attachAndNotify(
                $locked,
                $finalPayTemplates,
                $emailTemplate
            );

            $locked->activities()->create([
                'action' => 'final_pay_notified',
                'status' => $locked->status,
                'comment' => 'Sent to: ' . (count($notified) ? implode(', ', $notified) : 'no one — check the department heads\' emails'),
            ]);
        });
    }

    /**
     * Once every Final Pay Checklist assignment is approved, the whole
     * offboarding process is complete. Guarded the same way as the regular
     * -> final-pay trigger: locked inside a transaction so two final-pay
     * approvers finishing at nearly the same moment can never both mark the
     * request completed / create duplicate completion records. Also flips
     * the offboardee's own Employee Master record to `offboarded` (from
     * `offboarding`) — the same terminal state the `employees.status`
     * column has always defined but nothing previously ever set — so the
     * Dashboard's "Total Employees" count (which excludes only this
     * terminal status, not `offboarding`) drops the moment the process is
     * genuinely finished, not while it's still in progress. This never
     * touches the Offboardee page's own records: `OffboardeeController::index()`
     * matches `offboarding` OR `offboarded` explicitly, so a completed
     * offboardee keeps showing there with its full history intact.
     */
    public function checkFinalPayCompletion(OffboardingRequest $offboardingRequest): void
    {
        $allFinalPayApproved = $offboardingRequest->approvers()
            ->whereHas('checklistTemplate', fn ($q) => $q->where('is_final_pay_checklist', true))
            ->where('status', '!=', 'approved')
            ->doesntExist();

        if (! $allFinalPayApproved) {
            return;
        }

        DB::transaction(function () use ($offboardingRequest) {
            $locked = OffboardingRequest::whereKey($offboardingRequest->id)
                ->where('status', '!=', 'completed')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                // Already completed by a concurrent approval.
                return;
            }

            $locked->update(['status' => 'completed', 'completed_at' => now()]);
            $locked->employee()->update(['status' => 'offboarded']);

            $locked->activities()->create([
                'action' => 'completed',
                'status' => 'completed',
                'comment' => 'All required Final Pay Checklist approvals have been completed.',
            ]);
        });
    }

    /**
     * The group equivalent of the old per-row "ready for approval" check:
     * once EVERY checklist this employee is the assigned approver for on
     * this request (i.e. the same combined group the Approvals page now
     * shows as one card) has all its items checked, marks the whole group
     * ready for the Department Head's own manual Submit and sends ONE
     * combined email — this does NOT approve anything itself, the
     * Department Head still has to review and click Submit. A no-op group
     * (nothing in it uses per-item approvers) never gets this nudge — the
     * Department Head can already submit any time, same as a legacy
     * single-approver checklist always could. Guarded by every member's own
     * `ready_for_approval_notified_at` under a row lock, so the
     * notification only ever fires once per group even if items get
     * toggled back and forth afterward, and two people finishing their last
     * item on different checklists in the same group at nearly the same
     * moment can never send a duplicate email.
     */
    public function checkGroupReadyForApproval(OffboardingRequest $offboardingRequest, int $employeeId): bool
    {
        return DB::transaction(function () use ($offboardingRequest, $employeeId) {
            $members = OffboardingRequestApprover::where('offboarding_request_id', $offboardingRequest->id)
                ->where('employee_id', $employeeId)
                ->whereIn('status', ['pending', 'viewed'])
                ->lockForUpdate()
                ->get();

            if ($members->isEmpty() || $members->every(fn (OffboardingRequestApprover $m) => $m->ready_for_approval_notified_at !== null)) {
                return false;
            }

            $usesPerItemApprovers = $members->contains(fn (OffboardingRequestApprover $m) => $m->usesPerItemApprovers());

            if (! $usesPerItemApprovers) {
                return false;
            }

            $allReady = $members->every(
                fn (OffboardingRequestApprover $m) => ! $m->requiresAllItemsCompletedBeforeApproval() || $m->allItemsCompleted()
            );

            if (! $allReady) {
                return false;
            }

            $members->each(fn (OffboardingRequestApprover $m) => $m->update(['ready_for_approval_notified_at' => now()]));

            $offboardingRequest->activities()->create([
                'offboarding_request_approver_id' => $members->first()->id,
                'action' => 'checklist_ready_for_approval',
                'status' => $offboardingRequest->status,
                'comment' => 'All checklist items have been checked for: '
                    . $members->pluck('checklistTemplate.title')->filter()->implode(', ')
                    . '. Awaiting Department Head approval.',
            ]);

            app(ChecklistApprovalNotifier::class)->notifyDepartmentHeadReady($offboardingRequest, $employeeId, $members);

            return true;
        });
    }
}
