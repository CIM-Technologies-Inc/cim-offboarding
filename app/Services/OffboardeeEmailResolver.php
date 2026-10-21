<?php

namespace App\Services;

use App\Models\OffboardingRequest;
use App\Models\User;
use App\Notifications\MissingPersonalEmailNotification;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

/**
 * The single place every offboardee-directed email notification resolves
 * its recipient address through — never the many "someone else" recipients
 * (Admin, HR, Immediate Head, Clearance Signatories, Task Assignees,
 * General Signatories, Final Approvers), which keep using `Employee::email`
 * at their own call sites, untouched by this class.
 */
class OffboardeeEmailResolver
{
    /**
     * Returns the offboardee's `personal_email` once it's present and
     * well-formed — NEVER falls back to their company `email`, since the
     * whole point is that an offboardee may no longer have access to it.
     * When it's missing/invalid, logs it and raises a one-time admin alert
     * (see `alertAdminsOnce()`) instead, and returns null so the caller
     * skips sending.
     */
    public static function resolve(OffboardingRequest $offboardingRequest, string $context): ?string
    {
        $offboardee = $offboardingRequest->employee;

        if ($offboardee->hasUsablePersonalEmail()) {
            return $offboardee->personal_email;
        }

        Log::warning("Offboardee has no usable personal email on file — \"{$context}\" notification was not sent.", [
            'offboarding_request_id' => $offboardingRequest->id,
            'employee_id' => $offboardee->id,
        ]);

        static::alertAdminsOnce($offboardingRequest, $context);

        return null;
    }

    /**
     * Raises `MissingPersonalEmailNotification` to every Admin, but only
     * once per (request, context) pair — otherwise a recurring scheduled
     * email (see `SendEmailTemplateSchedules`) would re-alert every admin
     * on every run until someone fixes the employee record.
     */
    private static function alertAdminsOnce(OffboardingRequest $offboardingRequest, string $context): void
    {
        // `type` stores `MissingPersonalEmailNotification::databaseType()`
        // ('missing_personal_email'), NOT the notification's class name —
        // `DatabaseChannel::buildPayload()` prefers `databaseType()` over
        // `get_class()` whenever a notification defines it, as this one does.
        $alreadyAlerted = DatabaseNotification::where('type', 'missing_personal_email')
            ->where('data->offboarding_request_id', $offboardingRequest->id)
            ->where('data->context', $context)
            ->exists();

        if ($alreadyAlerted) {
            return;
        }

        Notification::send(
            User::role(User::ROLE_ADMIN)->get(),
            new MissingPersonalEmailNotification($offboardingRequest, $context)
        );
    }
}
