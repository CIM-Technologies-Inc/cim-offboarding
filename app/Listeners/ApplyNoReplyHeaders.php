<?php

namespace App\Listeners;

use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

/**
 * Sets a `Reply-To` header, mirroring the configured no-reply From address,
 * on every outgoing email — so a recipient's "Reply" button addresses the
 * same unmonitored mailbox the message came from, rather than whatever
 * address Laravel's default Reply-To behavior (none, unless a Mailable sets
 * one) would otherwise leave it pointed at. None of this app's 9 Mailables
 * set their own Reply-To (confirmed by direct read), so this is a true
 * catch-all: it covers every current and future Mailable with zero changes
 * to any of them. Registered in `AppServiceProvider::boot()`, same
 * `MessageSending` event `RedirectMailInNonProduction` already hooks.
 *
 * Deliberately never overrides a Reply-To a Mailable DID set itself (should
 * one ever need to) — this only ever fills in an otherwise-absent header.
 * A `Reply-To` header alone cannot force every email client to refuse a
 * reply, nor guarantee one is discarded if sent anyway — actually rejecting
 * replies requires a mailbox/mail-server-level control for whatever address
 * `mail.from.address` resolves to (e.g. no mailbox provisioned for it at
 * all, or an auto-discard rule at the mail provider), which is outside
 * anything this application can configure.
 */
class ApplyNoReplyHeaders
{
    public function handle(MessageSending $event): void
    {
        if ($event->message->getReplyTo()) {
            return;
        }

        $address = config('mail.from.address');

        if (! $address) {
            return;
        }

        $event->message->replyTo(new Address($address, config('mail.from.name') ?? ''));
    }
}
