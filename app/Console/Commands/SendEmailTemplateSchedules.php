<?php

namespace App\Console\Commands;

use App\Models\EmailTemplateSchedule;
use App\Services\EmailTemplateScheduleDispatcher;
use Illuminate\Console\Command;

class SendEmailTemplateSchedules extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'app:send-email-template-schedules';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Resolve every active EmailTemplateSchedule ("N days before/after Last Working Day", or "on/after Request Creation") against every still-active offboarding request that hasn\'t been resolved for it yet.';

    /**
     * Thin wrapper — all resolution/send logic lives in
     * `EmailTemplateScheduleDispatcher`, shared with the eager,
     * at-creation trigger in `OffboardingRequestController::notifyDepartmentHeads()`.
     * Both write to the same `EmailTemplateScheduleSend` ledger, so running
     * this on its usual interval can never double-send an occurrence the
     * eager trigger already delivered, and vice versa.
     */
    public function handle(EmailTemplateScheduleDispatcher $dispatcher): int
    {
        $schedules = EmailTemplateSchedule::where('is_active', true)
            ->whereHas('emailTemplate', fn ($q) => $q->where('is_active', true))
            ->with('emailTemplate', 'checklistTemplates:id', 'generalSignatories:id')
            ->get();

        $processed = 0;

        foreach ($schedules as $schedule) {
            $processed += $dispatcher->processSchedule($schedule);
        }

        $this->info("Processed {$processed} schedule occurrence(s).");

        return self::SUCCESS;
    }
}
