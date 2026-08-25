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

];
