<?php

namespace App\Console\Commands;

use App\Services\ClearanceSignatoryEscalationService;
use Illuminate\Console\Command;

/**
 * Applies due Clearance Signatory escalations — primary → secondary, and
 * secondary (or HR-assigned) → HR/Admin Action Required — then retries any
 * escalation notifications that failed earlier. Safe to run as often as
 * scheduled: every transition is re-checked under a row lock and applied at
 * most once. See `ClearanceSignatoryEscalationService`.
 */
class EscalateClearanceSignatories extends Command
{
    protected $signature = 'app:escalate-clearance-signatories';

    protected $description = 'Escalate checklists whose Clearance Signatory missed their completion deadline';

    public function handle(ClearanceSignatoryEscalationService $service): int
    {
        $counts = $service->processDue();

        $this->info("Escalated to secondary: {$counts['escalated']}; HR/Admin action required: {$counts['hr_action_required']}; monitoring stopped: {$counts['stopped']}; notification retries: {$counts['notifications_retried']}.");

        return self::SUCCESS;
    }
}
