<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * QA/staging safety net: when `mail.redirect_to` (env `MAIL_REDIRECT_TO`) is
 * set, every outgoing email's To/Cc/Bcc is rewritten to that one address
 * before it actually sends — so testing offboarding flows never emails a
 * real employee, approver, or admin. The original recipient(s) are kept
 * visible by prefixing the subject with who would have received it, rather
 * than silently disappearing. Registered in `AppServiceProvider::boot()`.
 * A no-op whenever `mail.redirect_to` is empty (the production default), so
 * this can never affect real outgoing mail.
 */
class RedirectMailInNonProduction
{
    public function handle(MessageSending $event): void
    {
        $redirectTo = config('mail.redirect_to');

        if (! $redirectTo) {
            return;
        }

        $message = $event->message;
        $originalCc = $message->getCc();
        $originalBcc = $message->getBcc();

        $originalRecipients = collect([...$message->getTo(), ...$originalCc, ...$originalBcc])
            ->map(fn (Address $address) => $address->getAddress())
            ->unique()
            ->values();

        // Already going only to the redirect address — nothing to rewrite
        // or annotate.
        if ($originalRecipients->isEmpty() || $originalRecipients->every(fn (string $email) => $email === $redirectTo)) {
            return;
        }

        $message->to($redirectTo);

        // Only touch Cc/Bcc if this message actually had any — calling
        // cc()/bcc() with no arguments on a message that never had that
        // header adds a new, empty one rather than leaving it absent.
        if ($originalCc) {
            $message->cc();
        }

        if ($originalBcc) {
            $message->bcc();
        }

        $message->subject('[Redirected from: '.$originalRecipients->implode(', ').'] '.($message->getSubject() ?? ''));
    }
}
