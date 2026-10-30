<?php

namespace App\Services;

use App\Models\EmailLog;
use App\Models\OffboardingRequest;
use App\Support\EmailLogContext;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\Message;
use Symfony\Component\Mime\RawMessage;

/**
 * The single write path for the email notification log (Logs → Email
 * Notifications). Hooked into Laravel's own mail pipeline, so every email
 * the app sends is covered regardless of which feature sent it or how
 * (immediately, queued, from the scheduler):
 *
 *  - `sending()` — `MessageSending` event, just before the transport: one
 *    row per attempt, status "sending", tagged on the message with an
 *    internal header so the outcome can find it again;
 *  - `sent()`    — `MessageSent` event, only after the transport accepted
 *    the message: status "sent";
 *  - `failed()`  — `LoggingTransport`, when the transport throws: status
 *    "failed" with the error message (then the exception is re-thrown, so
 *    every caller's existing error handling is unchanged).
 *
 * Logging must never break or delay email delivery, so every method
 * swallows (and reports to the app log) its own errors.
 */
class EmailLogRecorder
{
    public const HEADER = 'X-CIM-Email-Log-Id';

    private const ERROR_MAX = 2000;

    /**
     * @param  array<string, mixed>  $data  The mail event's view data — a
     *     Mailable's public properties, including `emailLogContext`.
     */
    public function sending(Email $message, array $data): void
    {
        try {
            $context = is_array($data['emailLogContext'] ?? null) && $data['emailLogContext'] !== []
                ? $data['emailLogContext']
                : EmailLogContext::snapshot($message->getSubject());

            $to = $this->addresses($message->getTo());
            $cc = $this->addresses($message->getCc());
            $bcc = $this->addresses($message->getBcc());
            $subject = Str::limit((string) $message->getSubject(), 497);

            [$messageKey, $attempt, $retryOfId] = $this->attemptChain($context['message_key'] ?? (string) Str::uuid(), $subject, $to);

            $log = EmailLog::create([
                'message_key' => $messageKey,
                'attempt' => $attempt,
                'retry_of_id' => $retryOfId,
                'notification_type' => $context['notification_type'] ?? EmailLog::TYPE_SYSTEM,
                'status' => EmailLog::STATUS_SENDING,
                'subject' => $subject,
                'template_name' => $context['template_name'] ?? null,
                'mailable' => $context['mailable'] ?? null,
                'to_recipients' => $to,
                'cc_recipients' => $cc ?: null,
                'bcc_recipients' => $bcc ?: null,
                'recipients_search' => implode(', ', array_unique([...$to, ...$cc, ...$bcc])),
                'redirected_to' => config('mail.redirect_to') ?: null,
                'trigger' => Str::limit((string) ($context['trigger'] ?? 'System'), 250),
                'trigger_source' => $context['trigger_source'] ?? (app()->runningInConsole() ? 'console' : 'web'),
                'delivery' => EmailLogContext::isQueueWorker() ? 'queued' : 'immediate',
                'schedule_name' => $context['schedule_name'] ?? null,
                'scheduled_for' => $context['scheduled_for'] ?? null,
                'offboarding_request_id' => $this->resolveRequestId($context),
                'offboardee_name' => $context['offboardee_name'] ?? null,
                'triggered_by_user_id' => $context['triggered_by_user_id'] ?? null,
                'triggered_by_name' => $context['triggered_by_name'] ?? null,
            ]);

            $message->getHeaders()->remove(self::HEADER);
            $message->getHeaders()->addTextHeader(self::HEADER, (string) $log->id);
        } catch (\Throwable $e) {
            Log::warning('Email notification log: could not record a sending attempt.', ['exception' => $e->getMessage()]);
        }
    }

    public function sent(SentMessage $sent): void
    {
        try {
            $log = $this->find($sent->getOriginalMessage());

            $log?->update([
                'status' => EmailLog::STATUS_SENT,
                'sent_at' => now(),
                'failed_at' => null,
                'error_message' => null,
                'smtp_message_id' => Str::limit((string) $sent->getMessageId(), 250, ''),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Email notification log: could not mark an email as sent.', ['exception' => $e->getMessage()]);
        }
    }

    public function failed(RawMessage $message, \Throwable $error): void
    {
        try {
            $this->find($message)?->update([
                'status' => EmailLog::STATUS_FAILED,
                'failed_at' => now(),
                'error_message' => Str::limit($this->safeError($error), self::ERROR_MAX),
            ]);
        } catch (\Throwable $e) {
            Log::warning('Email notification log: could not mark an email as failed.', ['exception' => $e->getMessage()]);
        }
    }

    private function find(RawMessage $message): ?EmailLog
    {
        if (! $message instanceof Message) {
            return null;
        }

        $id = $message->getHeaders()->get(self::HEADER)?->getBodyAsString();

        return $id ? EmailLog::find((int) $id) : null;
    }

    /**
     * Same notification re-sent → same `message_key`, next `attempt`, linked
     * to the first attempt. Queue retries share the key automatically (it's
     * frozen on the queued Mailable); an app-level re-send builds a new
     * Mailable, so a recent FAILED attempt with the same subject and
     * recipients (and no successful send since) is treated as its original.
     *
     * @param  array<int, string>  $to
     * @return array{0: string, 1: int, 2: ?int}
     */
    private function attemptChain(string $messageKey, string $subject, array $to): array
    {
        $previous = EmailLog::where('message_key', $messageKey)->orderBy('attempt')->get(['id', 'attempt']);

        if ($previous->isNotEmpty()) {
            return [$messageKey, $previous->max('attempt') + 1, $previous->first()->id];
        }

        $recentFailure = EmailLog::where('subject', $subject)
            ->where('recipients_search', 'like', implode(', ', $to).'%')
            ->where('created_at', '>=', now()->subDays(7))
            ->latest('id')
            ->first();

        if ($recentFailure && $recentFailure->status === EmailLog::STATUS_FAILED && $recentFailure->to_recipients === $to) {
            return [
                $recentFailure->message_key,
                EmailLog::where('message_key', $recentFailure->message_key)->max('attempt') + 1,
                $recentFailure->retry_of_id ?? $recentFailure->id,
            ];
        }

        return [$messageKey, 1, null];
    }

    private function resolveRequestId(array $context): ?int
    {
        if (! empty($context['offboarding_request_id'])) {
            return (int) $context['offboarding_request_id'];
        }

        if (empty($context['offboardee_name'])) {
            return null;
        }

        // Best effort: the offboardee's most recent request.
        return OffboardingRequest::whereHas('employee', fn ($q) => $q->where('name', $context['offboardee_name']))
            ->latest('id')
            ->value('id');
    }

    /**
     * @param  array<int, Address>  $addresses
     * @return array<int, string>
     */
    private function addresses(array $addresses): array
    {
        return array_values(array_map(fn (Address $address) => $address->getAddress(), $addresses));
    }

    /** Never let a credential that an SMTP library echoed back reach the log. */
    private function safeError(\Throwable $error): string
    {
        $message = trim($error->getMessage()) ?: class_basename($error);

        return (string) preg_replace('/(password|passwd|pwd|token|secret)\s*[=:]\s*\S+/i', '$1=[hidden]', $message);
    }
}
