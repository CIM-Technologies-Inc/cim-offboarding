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
