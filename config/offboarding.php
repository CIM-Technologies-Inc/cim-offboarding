<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Checklist Follow-Up Limits
    |--------------------------------------------------------------------------
    |
    | Governs the employee-facing "Follow Up" button on the Employee
    | Dashboard (see App\Services\ChecklistFollowUpService). The cooldown is
    | a rolling window per checklist (not a calendar-day reset); the max
    | attempts is a single shared budget across every checklist on one
    | offboarding request, not per checklist.
    |
    */

    'max_follow_up_attempts' => env('OFFBOARDING_MAX_FOLLOW_UP_ATTEMPTS', 5),

    'follow_up_cooldown_hours' => env('OFFBOARDING_FOLLOW_UP_COOLDOWN_HOURS', 24),

    /*
    |--------------------------------------------------------------------------
    | Clearance Signatory Escalation
    |--------------------------------------------------------------------------
    |
    | Holidays skipped when a checklist's "Escalate After (Days)" is counted
    | in Working Days (weekends are always skipped). Comma-separated Y-m-d
    | dates, e.g. OFFBOARDING_ESCALATION_HOLIDAYS="2026-12-25,2027-01-01".
    | See App\Services\ClearanceSignatoryEscalationService::computeDeadline().
    |
    */

    'escalation_holidays' => array_values(array_filter(array_map('trim', explode(',', (string) env('OFFBOARDING_ESCALATION_HOLIDAYS', ''))))),

    'escalation_max_days' => 365,

];
