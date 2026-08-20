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
     * request completed / create duplicate completion records.
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

            $locked->activities()->create([
                'action' => 'completed',
                'status' => 'completed',
                'comment' => 'All required Final Pay Checklist approvals have been completed.',
            ]);
        });
    }

    /**
     * For per-item-approver assignments only: once every item is checked,
     * marks the assignment ready for the Department Head's own manual
     * Submit and emails them — this does NOT approve anything itself, the
     * Department Head still has to review and click Submit. No-op for
     * legacy single-approver assignments (they've never needed a
     * "ready" signal — the Department Head can already submit any time).
     * Guarded by `ready_for_approval_notified_at` under a row lock, the
     * same pattern as `final_pay_notified_at`, so the notification only
     * ever fires once even if items get toggled back and forth afterward
     * (e.g. the Department Head correcting something during review) and
     * two item-approvers finishing their last item at nearly the same
     * moment can never send a duplicate email.
     */
    public function checkReadyForDepartmentHeadApproval(OffboardingRequestApprover $assignment): bool
    {
        return DB::transaction(function () use ($assignment) {
            $locked = OffboardingRequestApprover::whereKey($assignment->id)
                ->whereNull('ready_for_approval_notified_at')
                ->lockForUpdate()
                ->first();

            if (! $locked || ! in_array($locked->status, ['pending', 'viewed'], true)) {
                return false;
            }

            if (! $locked->usesPerItemApprovers() || ! $locked->allItemsCompleted()) {
                return false;
            }

            $locked->update(['ready_for_approval_notified_at' => now()]);

            $locked->offboardingRequest->activities()->create([
                'offboarding_request_approver_id' => $locked->id,
                'action' => 'checklist_ready_for_approval',
                'status' => $locked->offboardingRequest->status,
                'comment' => 'All checklist items have been checked. Awaiting Department Head approval.',
            ]);

            app(ChecklistApprovalNotifier::class)->notifyDepartmentHeadReady($locked);

            return true;
        });
    }
}
