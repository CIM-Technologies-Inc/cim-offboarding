<?php

namespace App\Services;

use App\Models\ChecklistTemplate;
use App\Models\EmailTemplate;
use App\Models\GeneralSignatory;
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
     * Dispatches to whichever gate actually applies for THIS request's own
     * frozen `approval_mode` — an Async request (today's original, only
     * behavior) goes straight to `attachFinalPayChecklistsIfReady()`
     * unchanged; a Sync request instead runs the Primary -> Secondary gate
     * first, which itself falls through to that same shared method once
     * Secondary is also satisfied. Called from the exact same places as
     * before (`ApprovalController::finalizeGroupApproval()`,
     * `autoApproveIfHeadless()`) — neither needed to change, since both
     * already call this method unconditionally after any non-final-pay
     * approval, regardless of sync/async.
     */
    public function checkRegularChecklistsCompletion(OffboardingRequest $offboardingRequest): void
    {
        if ($offboardingRequest->approval_mode === 'sync') {
            $this->checkPrimaryChecklistsCompletion($offboardingRequest);

            return;
        }

        $this->attachFinalPayChecklistsIfReady($offboardingRequest);
    }

    /**
     * Sync-mode-only stage gate: once every Primary (non-final-pay)
     * checklist assignment is approved, attaches + notifies the Secondary
     * checklist(s) — the exact same `notifyDepartmentHeadsOfNewRequest()` +
     * `attachAndNotify()` pair already used for the initial Primary batch
     * at request creation (`OffboardingRequestController::notifyDepartmentHeads()`),
     * reused here rather than duplicated. Guarded by `secondary_notified_at`
     * under a row lock, the same one-shot-per-request pattern
     * `final_pay_notified_at` already uses one stage later.
     *
     * Whether or not there were any Secondary templates to attach, this
     * always finishes by calling `attachFinalPayChecklistsIfReady()` — a
     * harmless no-op if Secondary was just attached (nothing on it is
     * approved yet), but the same "zero templates configured" self-heal
     * `attachFinalPayChecklistsIfReady()` itself already relies on for
     * Final Pay: a Sync request with zero Secondary templates configured
     * falls straight through to the Final Pay gate instead of stalling
     * forever waiting for an approval that can never happen.
     */
    private function checkPrimaryChecklistsCompletion(OffboardingRequest $offboardingRequest): void
    {
        $primaryApprovers = $offboardingRequest->approvers()
            ->whereHas('checklistTemplate', fn ($q) => $q
                ->where('is_final_pay_checklist', false)
                ->where('sequence_type', ChecklistTemplate::SEQUENCE_TYPE_PRIMARY));

        // Deliberately WITHOUT the `exists()` half of the vacuous-truth
        // guard used elsewhere in this file: here, "zero Primary checklists
        // were ever attached" must count as the Primary stage trivially
        // PASSED (so a department with none configured advances straight
        // to Secondary — see `OffboardingRequestController::notifyDepartmentHeads()`'s
        // defensive call right after creation), not blocked forever. The
        // `secondary_notified_at` lock below still makes this safe to
        // re-evaluate on every call — it only ever attaches Secondary once.
        $allPrimaryApproved = $primaryApprovers->clone()->whereNotIn('status', ['approved', 'declined'])->doesntExist();

        if ($allPrimaryApproved) {
            DB::transaction(function () use ($offboardingRequest) {
                $locked = OffboardingRequest::whereKey($offboardingRequest->id)
                    ->whereNull('secondary_notified_at')
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    // Already claimed by a concurrent approval — nothing more to do here.
                    return;
                }

                $locked->activities()->create([
                    'action' => 'primary_checklists_approved',
                    'status' => $locked->status,
                ]);

                $secondaryTemplates = ChecklistTemplate::where('is_active', true)
                    ->where('is_final_pay_checklist', false)
                    ->where('sequence_type', ChecklistTemplate::SEQUENCE_TYPE_SECONDARY)
                    ->applicableToDepartment($locked->employee->department)
                    ->with(['departmentHead', 'items.signatory'])
                    ->get();

                $locked->update(['secondary_notified_at' => now()]);

                if ($secondaryTemplates->isEmpty()) {
                    return;
                }

                app(ChecklistApprovalNotifier::class)->notifyDepartmentHeadsOfNewRequest($locked, $secondaryTemplates);
                $notified = app(ChecklistApprovalNotifier::class)->attachAndNotify($locked, $secondaryTemplates, null);

                $locked->activities()->create([
                    'action' => 'secondary_checklists_notified',
                    'status' => $locked->status,
                    'comment' => 'Sent to: '.(count($notified) ? implode(', ', $notified) : 'no one — check the department heads\' emails'),
                ]);
            });
        }

        $this->attachFinalPayChecklistsIfReady($offboardingRequest);
    }

    /**
     * Once every regular (non-final-pay) checklist assignment on this
     * request is approved, either finish up as before (if no Final Pay
     * Checklist is configured) or attach + notify the Final Pay Checklist
     * approver(s). Guarded by `final_pay_notified_at` under a row lock so
     * two near-simultaneous approvals can never trigger this twice. Shared
     * verbatim by both Async requests (called directly, unchanged from
     * before this feature existed) and Sync requests (called by
     * `checkPrimaryChecklistsCompletion()` above once Secondary is also
     * satisfied) — by this point "every regular checklist approved" means
     * the same thing either way, so this needs no awareness of
     * `approval_mode` at all.
     */
    private function attachFinalPayChecklistsIfReady(OffboardingRequest $offboardingRequest): void
    {
        $regularApprovers = $offboardingRequest->approvers()
            ->whereHas('checklistTemplate', fn ($q) => $q->where('is_final_pay_checklist', false));

        // Same vacuous-truth guard as `checkFinalPayCompletion()` below:
        // `doesntExist()` alone would read "all approved" as true for a
        // request with zero regular checklists attached, wrongly attaching
        // (and notifying) the Final Pay Checklist before any real approval
        // ever happened.
        $allRegularApproved = $regularApprovers->clone()->exists()
            && $regularApprovers->clone()->whereNotIn('status', ['approved', 'declined'])->doesntExist();

        // A General Signatory is an additional, checklist-independent
        // clearance requirement (see `OffboardingRequestGeneralSignatory`) —
        // snapshotted onto the request once at creation time, so this
        // always reflects who was actually assigned then, never a later
        // change to the live General Signatory configuration. The Final Pay
        // Checklist must never even be attached while any REGULAR (Core/
        // Secondary) General Signatory is still pending, regardless of how
        // quickly the regular checklists themselves get approved. This is
        // deliberately scoped to the regular General Signatory tier only —
        // the General Signatory track's OWN, independent Final Pay tier
        // (see `attachFinalPayGeneralSignatoriesIfReady()`) is neither a
        // precondition for, nor blocked by, this checklist-side gate.
        if (! $allRegularApproved || ! $this->allRegularGeneralSignatoriesApproved($offboardingRequest)) {
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
                Log::warning('No "'.self::FINAL_PAY_APPROVAL_TEMPLATE.'" email template found — final pay approvers were not emailed.', [
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
                'comment' => 'Sent to: '.(count($notified) ? implode(', ', $notified) : 'no one — check the department heads\' emails'),
            ]);
        });
    }

    /**
     * The General Signatory equivalent of `checkRegularChecklistsCompletion()`
     * above — same dispatcher shape, same `approval_mode` branch — but for
     * the independent General Signatory track (see `GeneralSignatory`'s own
     * docblock for why it's never mixed into `OffboardingRequestApprover`).
     * Called once, defensively, right after a Sync request is created (see
     * `OffboardingRequestController::notifyDepartmentHeads()`) and every
     * time a General Signatory approves (`GeneralSignatoryApprovalController::finalizeApproval()`).
     */
    public function checkRegularGeneralSignatoriesCompletion(OffboardingRequest $offboardingRequest): void
    {
        if ($offboardingRequest->approval_mode === 'sync') {
            $this->checkPrimaryGeneralSignatoriesCompletion($offboardingRequest);

            return;
        }

        $this->attachFinalPayGeneralSignatoriesIfReady($offboardingRequest);
    }

    /**
     * Sync-mode-only stage gate, the General Signatory equivalent of
     * `checkPrimaryChecklistsCompletion()`: once every Core (non-final-pay)
     * General Signatory approval is approved, attaches + notifies the
     * Secondary General Signatory(-ies) — reusing
     * `ChecklistApprovalNotifier::notifyGeneralSignatories()`, the exact
     * same method used for the initial Core batch at request creation, not
     * a duplicate. Guarded by `general_signatory_secondary_notified_at`
     * under a row lock, the General Signatory track's own one-shot-per-
     * request lock, independent of the checklist track's
     * `secondary_notified_at`.
     *
     * Whether or not there were any Secondary General Signatories to
     * attach, this always finishes by calling
     * `attachFinalPayGeneralSignatoriesIfReady()` — a harmless no-op if
     * Secondary was just attached (nothing on it is approved yet), but the
     * same "zero configured" self-heal that method itself already relies
     * on for its own Final Pay tier: a Sync request with zero Secondary
     * General Signatories configured falls straight through to the Final
     * Pay tier instead of stalling forever.
     */
    private function checkPrimaryGeneralSignatoriesCompletion(OffboardingRequest $offboardingRequest): void
    {
        $primaryApprovals = $offboardingRequest->generalSignatoryApprovals()
            ->where('is_final_pay_signatory', false)
            ->where('sequence_type', ChecklistTemplate::SEQUENCE_TYPE_PRIMARY);

        // Same deliberately-without-`exists()` vacuous-truth guard as
        // `checkPrimaryChecklistsCompletion()`: zero Core General
        // Signatories ever attached must count as the Core stage trivially
        // PASSED, not blocked forever — the `general_signatory_secondary_notified_at`
        // lock below still makes this safe to re-evaluate on every call.
        $allPrimaryApproved = $primaryApprovals->clone()->whereNotIn('status', ['approved', 'declined'])->doesntExist();

        if ($allPrimaryApproved) {
            DB::transaction(function () use ($offboardingRequest) {
                $locked = OffboardingRequest::whereKey($offboardingRequest->id)
                    ->whereNull('general_signatory_secondary_notified_at')
                    ->lockForUpdate()
                    ->first();

                if (! $locked) {
                    // Already claimed by a concurrent approval — nothing more to do here.
                    return;
                }

                $locked->activities()->create([
                    'action' => 'primary_general_signatories_approved',
                    'status' => $locked->status,
                ]);

                $secondaryGeneralSignatories = GeneralSignatory::where('is_active', true)
                    ->where('is_final_pay_signatory', false)
                    ->where('sequence_type', ChecklistTemplate::SEQUENCE_TYPE_SECONDARY)
                    ->whereNotIn('id', $locked->generalSignatories()->pluck('general_signatories.id'))
                    ->with(['clearanceSignatory', 'tasks.signatory'])
                    ->get();

                $locked->update(['general_signatory_secondary_notified_at' => now()]);

                app(ChecklistApprovalNotifier::class)->notifyGeneralSignatories($locked, $secondaryGeneralSignatories);

                if ($secondaryGeneralSignatories->isEmpty()) {
                    return;
                }

                $locked->activities()->create([
                    'action' => 'secondary_general_signatories_notified',
                    'status' => $locked->status,
                    'comment' => 'Sent to: '.$secondaryGeneralSignatories
                        ->pluck('clearanceSignatory.name')
                        ->filter()
                        ->implode(', '),
                ]);
            });

            // The transaction above updates a separately-fetched `$locked`
            // instance, not this method's own `$offboardingRequest` — so
            // its in-memory `general_signatory_secondary_notified_at`
            // attribute is still stale at this point. Refreshed here so the
            // fall-through call below (particularly the zero-Secondary-
            // configured self-heal case) sees the lock as already resolved
            // instead of incorrectly reading it as still pending.
            $offboardingRequest->refresh();
        }

        $this->attachFinalPayGeneralSignatoriesIfReady($offboardingRequest);
    }

    /**
     * Once every regular (non-final-pay) General Signatory approval on this
     * request is approved, attaches + notifies the Final-Pay-tier General
     * Signatory(-ies), if any — the General Signatory equivalent of
     * `attachFinalPayChecklistsIfReady()`, shared verbatim by both Async
     * requests (Core+Secondary already attached together, so this is the
     * very next gate) and Sync requests (called by
     * `checkPrimaryGeneralSignatoriesCompletion()` above once Secondary is
     * also satisfied).
     *
     * The extra `general_signatory_secondary_notified_at IS NOT NULL`
     * check (Sync mode only) closes a race that doesn't exist on the
     * checklist side: there, an unattached Secondary template simply
     * produces zero rows to evaluate, so "no unapproved row exists" is
     * always safely vacuous. Here, a Sync request could have its Core tier
     * fully approved while Secondary hasn't been attached yet — without
     * this guard, "no unapproved row exists" would vacuously read true
     * over the Core-only rows and let the Final Pay tier jump the queue
     * before Secondary is even attached. Async mode never needs this
     * guard: Core and Secondary attach together at creation, so there's no
     * intermediate gap to race.
     */
    private function attachFinalPayGeneralSignatoriesIfReady(OffboardingRequest $offboardingRequest): void
    {
        if (! $this->allRegularGeneralSignatoriesApproved($offboardingRequest)) {
            return;
        }

        DB::transaction(function () use ($offboardingRequest) {
            $locked = OffboardingRequest::whereKey($offboardingRequest->id)
                ->whereNull('general_signatory_final_pay_notified_at')
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                // Already claimed by a concurrent approval — nothing more to do here.
                return;
            }

            $locked->activities()->create([
                'action' => 'all_general_signatories_approved',
                'status' => $locked->status,
            ]);

            $finalPayGeneralSignatories = GeneralSignatory::where('is_active', true)
                ->where('is_final_pay_signatory', true)
                ->whereNotIn('id', $locked->generalSignatories()->pluck('general_signatories.id'))
                ->with(['clearanceSignatory', 'tasks.signatory'])
                ->get();

            $locked->update(['general_signatory_final_pay_notified_at' => now()]);

            app(ChecklistApprovalNotifier::class)->notifyGeneralSignatories($locked, $finalPayGeneralSignatories);

            if ($finalPayGeneralSignatories->isEmpty()) {
                return;
            }

            $locked->activities()->create([
                'action' => 'final_pay_general_signatories_notified',
                'status' => $locked->status,
                'comment' => 'Sent to: '.$finalPayGeneralSignatories
                    ->pluck('clearanceSignatory.name')
                    ->filter()
                    ->implode(', '),
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
        $finalPayApprovers = $offboardingRequest->approvers()
            ->whereHas('checklistTemplate', fn ($q) => $q->where('is_final_pay_checklist', true));

        // `doesntExist()` on the "not yet approved" query is vacuously true
        // when the Final Pay Checklist hasn't even been attached to this
        // request yet (zero rows to contradict it) — so an `exists()` check
        // is required too, otherwise a General Signatory approving before
        // `checkRegularChecklistsCompletion()` has attached any Final Pay
        // Checklist would incorrectly read as "all final pay approved" and
        // let this method complete the request with regular checklists (and
        // the Final Pay Checklist itself) still outstanding.
        $allFinalPayApproved = $finalPayApprovers->clone()->exists()
            && $finalPayApprovers->clone()->whereNotIn('status', ['approved', 'declined'])->doesntExist();

        // Gives the General Signatory track's own Final Pay tier a chance
        // to attach (or self-heal past, if none is configured) right now —
        // safe/idempotent, see its own docblock. Without this, a request
        // whose General Signatory track never independently triggered this
        // (e.g. zero General Signatories configured at all, so no approval
        // event ever fires `checkRegularGeneralSignatoriesCompletion()`)
        // would leave `general_signatory_final_pay_notified_at` null
        // forever, permanently blocking `allGeneralSignatoriesFullyCleared()`
        // below.
        $this->attachFinalPayGeneralSignatoriesIfReady($offboardingRequest);

        // That call updates a separately-fetched instance internally, not
        // this method's own `$offboardingRequest` — refreshed here so the
        // check below sees the lock it may have just set.
        $offboardingRequest->refresh();

        // Defense in depth alongside the same check in
        // `checkRegularChecklistsCompletion()` above: the Final Pay
        // Checklist is normally never even attached until every General
        // Signatory has approved, so this is expected to already be true by
        // the time any Final Pay checklist exists at all — but the request
        // must never be marked `completed` while one is still outstanding,
        // regardless of which path got a Final Pay checklist approved.
        if (! $allFinalPayApproved || ! $this->allGeneralSignatoriesFullyCleared($offboardingRequest)) {
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
     * Whether every REGULAR (Core/Secondary, non-final-pay) General
     * Signatory snapshotted onto this request has approved — trivially true
     * for a request with none at all, so this never changes behavior for
     * the common case where no General Signatory is configured. For a Sync
     * request, also requires `general_signatory_secondary_notified_at` to
     * already be set: without it, "no unapproved row exists" would
     * vacuously read true over the Core-only rows while Secondary hasn't
     * even been attached yet, wrongly treating the regular tier as cleared
     * (see `attachFinalPayGeneralSignatoriesIfReady()`'s own docblock for
     * the race this closes). Consulted by both the checklist-side
     * `attachFinalPayChecklistsIfReady()` (gates the Final Pay CHECKLIST)
     * and this class's own `attachFinalPayGeneralSignatoriesIfReady()`
     * (gates the General Signatory track's own, independent Final Pay
     * tier) — the two tracks share this one "regular General Signatory
     * clearance" precondition, kept otherwise independent of each other.
     */
    private function allRegularGeneralSignatoriesApproved(OffboardingRequest $offboardingRequest): bool
    {
        if ($offboardingRequest->approval_mode === 'sync' && $offboardingRequest->general_signatory_secondary_notified_at === null) {
            return false;
        }

        return $offboardingRequest->generalSignatoryApprovals()
            ->where('is_final_pay_signatory', false)
            ->whereNotIn('status', ['approved', 'declined'])
            ->doesntExist();
    }

    /**
     * Whether EVERY General Signatory snapshotted onto this request — every
     * tier, Core/Secondary/Final Pay alike — has approved, AND the General
     * Signatory track's own Final Pay tier has already been resolved
     * (`general_signatory_final_pay_notified_at` is set, whether or not
     * there turned out to be any Final-Pay-tier signatory to attach).
     * Without that second condition, a request with a still-pending
     * Final-Pay-tier General Signatory that simply hasn't been attached YET
     * would otherwise read as "all approved" (vacuously, since its row
     * doesn't exist to contradict that) and let `checkFinalPayCompletion()`
     * below mark the request `completed` prematurely. Consulted only by
     * `checkFinalPayCompletion()`, which defensively re-runs
     * `attachFinalPayGeneralSignatoriesIfReady()` immediately beforehand so
     * this can resolve correctly even for a request whose General
     * Signatory track was never separately triggered (e.g. zero General
     * Signatories configured at all).
     */
    private function allGeneralSignatoriesFullyCleared(OffboardingRequest $offboardingRequest): bool
    {
        if ($offboardingRequest->general_signatory_final_pay_notified_at === null) {
            return false;
        }

        return $offboardingRequest->generalSignatoryApprovals()
            ->whereNotIn('status', ['approved', 'declined'])
            ->doesntExist();
    }

    /**
     * A "Use Task Assignee as Clearance Signatory" checklist has no
     * Department Head/Clearance Signatory at all — its individually
     * assigned Task Assignees ARE the signatories, each responsible only
     * for their own item(s). Nobody exists who could ever click Submit
     * (`ApprovalController::approve()`/`approveGroup()` both authorize via
     * `employee_id`, which is null here), so this is the only path that
     * can ever move such a row to `'approved'`: called after every item
     * check (`ChecklistDelegationController::saveProgress()`/
     * `saveProgressGroup()`, in place of `checkGroupReadyForApproval()` —
     * which requires a non-null `int $employeeId` and would throw for this
     * row), it flips the assignment to `'approved'` the moment
     * `allItemsCompleted()` becomes true, with `user_id = null` on the
     * resulting activity — the exact case `OffboardingActivity::label()`'s
     * `'All checklist items completed — auto-approved'` branch already
     * expected. Feeds the SAME downstream pipeline a human Submit would
     * (`checkRegularChecklistsCompletion()`/`checkFinalPayCompletion()`),
     * so Final Pay attachment and the request's own completion tracking
     * are unaffected by there being no primary approver here. Locked and
     * idempotent the same way `checkFinalPayCompletion()` above is, so two
     * Task Assignees finishing their last items at nearly the same moment
     * can never both approve/notify twice.
     */
    public function autoApproveIfHeadless(OffboardingRequestApprover $assignment): void
    {
        if ($assignment->employee_id !== null) {
            return;
        }

        DB::transaction(function () use ($assignment) {
            $locked = OffboardingRequestApprover::whereKey($assignment->id)
                ->whereIn('status', ['pending', 'viewed'])
                ->lockForUpdate()
                ->first();

            if (! $locked) {
                // Already approved by a concurrent request.
                return;
            }

            // A forced `load()`, not `loadMissing()` — the caller's own
            // `authorizeItemAction()` already cached this same relation
            // (empty, pre-sync) earlier in this same request, so
            // `allItemsCompleted()`'s own `loadMissing()` would silently
            // keep serving that stale snapshot instead of the rows
            // `ChecklistItemProgress::syncForAssignment()` just persisted
            // moments ago — the exact same pitfall documented on
            // `ChecklistDelegationController::checkedItemPatches()`.
            $locked->load('checklistTemplate.items', 'itemProgress');

            if (! $locked->allItemsCompleted()) {
                return;
            }

            $locked->update(['status' => 'approved', 'approved_at' => now()]);

            $locked->offboardingRequest->activities()->create([
                'user_id' => null,
                'offboarding_request_approver_id' => $locked->id,
                'action' => 'approved',
                'status' => $locked->offboardingRequest->status,
                'comment' => 'All checklist items completed — auto-approved (Task Assignee signatories).',
            ]);

            $locked->checklistTemplate->is_final_pay_checklist
                ? $this->checkFinalPayCompletion($locked->offboardingRequest)
                : $this->checkRegularChecklistsCompletion($locked->offboardingRequest);
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
     * (nothing in it requires full completion before approval — see
     * `requiresAllItemsCompletedBeforeApproval()`'s two independent
     * triggers) never gets this nudge — the Department Head can already
     * submit any time, same as a legacy single-approver checklist always
     * could. This deliberately covers BOTH triggers, not just per-item
     * approvers: an Immediate Head/Department Head checklist forwarded
     * whole to a delegate also requires full completion before Submit, so
     * its Clearance Signatory — who may never open the checklist
     * themselves — equally needs this "come approve" nudge once the
     * delegate finishes, not just a per-item-approver checklist's owner.
     * Guarded by every member's own `ready_for_approval_notified_at` under
     * a row lock, so the notification only ever fires once per group even
     * if items get toggled back and forth afterward, and two people
     * finishing their last item on different checklists in the same group
     * at nearly the same moment can never send a duplicate email.
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

            $requiresNotification = $members->contains(fn (OffboardingRequestApprover $m) => $m->requiresAllItemsCompletedBeforeApproval());

            if (! $requiresNotification) {
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
