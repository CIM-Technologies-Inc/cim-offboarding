<?php

use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// Flags newly-overdue checklist assignments and notifies admins/responsible
// approvers — driven by actual due-date state, not by anyone visiting a
// page. Requires the server's scheduler to actually be running (e.g. a
// `php artisan schedule:run` every minute via cron or Windows Task
// Scheduler); the definition here is inert without it.
Schedule::command('app:notify-overdue-checklists')->everyFiveMinutes();

// Sends every active, scheduled Email and Notification template whose "N
// days before/after Last Working Day" date is due, for every still-active
// offboarding request. Daily is sufficient granularity since the trigger is
// a calendar date, not a time-of-day deadline — same scheduler-must-be-
// running caveat as the overdue-checklist job above.
Schedule::command('app:send-scheduled-email-templates')->dailyAt('08:00');

// Per-task-item version of the same idea: sends each checklist item's own
// scheduled Email and Notification template to that item's Task Assignee
// once its "N days before/after Last Working Day" date is due. Staggered 10
// minutes after the template-level job purely to avoid same-minute
// contention.
Schedule::command('app:send-scheduled-checklist-item-notifications')->dailyAt('08:10');

// Per-CHECKLIST reminder — independent of both jobs above, and of every
// other checklist on the same request: timed off THIS checklist's own
// current due date (`OffboardingRequestApprover::due_at`, already kept
// current by Extend Due), not the offboardee's Last Working Day directly.
// The only one of these three with a real "Time to Send" field, so it runs
// far more often than daily — same reasoning as `app:notify-overdue-checklists`
// above.
Schedule::command('app:send-checklist-reminders')->everyFifteenMinutes();
