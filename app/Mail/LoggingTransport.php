<?php

namespace App\Mail;

use App\Services\EmailLogRecorder;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\RawMessage;

/**
 * Wraps whatever transport the app is configured with (SMTP, array, log,
 * …) purely to record a send that FAILED in the email notification log —
 * successes are recorded from Laravel's `MessageSent` event. The exception
 * is always re-thrown unchanged, so every caller's existing error handling,
 * queue retries and failed-job tracking behave exactly as before.
 */
class LoggingTransport implements TransportInterface
{
    public function __construct(private TransportInterface $transport) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->transport->send($message, $envelope);
        } catch (\Throwable $e) {
            app(EmailLogRecorder::class)->failed($message, $e);

            throw $e;
        }
    }

    public function inner(): TransportInterface
    {
        return $this->transport;
    }

    public function __toString(): string
    {
        return (string) $this->transport;
    }
}
