<?php

namespace App\Mail\Concerns;

use App\Support\EmailLogContext;

/**
 * Freezes the email-log context (what triggered this email, which template,
 * Scheduled vs System-Triggered — see `EmailLogContext`) onto the Mailable
 * the moment it's built. It's a public property, so it is serialized with a
 * queued Mailable and handed to Laravel's mail events (`$event->data`) when
 * the queue worker finally sends it — every retry of that job therefore
 * shares the same `message_key`. Never contains the email body.
 */
trait CapturesEmailLogContext
{
    /** @var array<string, mixed> */
    public array $emailLogContext = [];

    protected function captureEmailLogContext(?string $subject = null, ?string $offboardeeName = null): void
    {
        $this->emailLogContext = EmailLogContext::snapshot($subject) + array_filter([
            'mailable' => class_basename(static::class),
            'offboardee_name' => $offboardeeName,
        ]);
    }
}
