<?php

namespace App\Exceptions;

use RuntimeException;

/**
 * Thrown by `App\Services\ChecklistFollowUpService::send()` when the
 * employee is still within the per-checklist cooldown, or has already used
 * up their whole request's shared follow-up budget. The message is written
 * to be shown to the employee as-is.
 */
class FollowUpNotAllowedException extends RuntimeException
{
    //
}
