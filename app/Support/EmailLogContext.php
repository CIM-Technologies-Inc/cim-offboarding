<?php

namespace App\Support;

use App\Models\EmailLog;
use App\Models\EmailTemplate;
use Illuminate\Support\Str;

/**
 * Process-local "why is this email being sent?" context for the email
 * notification log (see `EmailLogRecorder`). Filled from three central
 * places — never per notification type:
 *
 *  - `EmailTemplate::render()` remembers which template (and offboardee /
 *    request) produced each rendered subject, so the log can name the
 *    template an email came from by matching its subject;
 *  - the running Artisan command (tracked from Laravel's own console
 *    events), which tells scheduled notification commands apart from
 *    system-triggered ones;
 *  - an explicit `scope()` for richer context, e.g. the scheduled email
 *    template dispatcher passing the schedule name and intended send date.
 *
 * `snapshot()` freezes all of it into a plain array. Mailables capture it
 * when they're constructed (`CapturesEmailLogContext`), so it survives
 * being queued and is still accurate when the queue worker sends it later
 * with no user session.
 */
class EmailLogContext
{
    /** Scheduled notification commands → their log trigger label. */
    public const SCHEDULED_COMMANDS = [
        'app:send-email-template-schedules' => 'Scheduled email template run',
        'app:send-scheduled-checklist-item-notifications' => 'Scheduled checklist item notification',
        'app:send-checklist-reminders' => 'Checklist reminder (scheduled)',
        'app:notify-overdue-checklists' => 'Overdue checklist notice (scheduled)',
        'app:notify-clearance-signing-due' => 'Clearance signing due notice (scheduled)',
    ];

    /** Automated, but event-driven (not a configured schedule) → System-Triggered. */
    public const SYSTEM_COMMANDS = [
        'app:escalate-clearance-signatories' => 'Automatic Clearance Signatory escalation',
    ];

    /** @var array<int, array<string, mixed>> */
    private static array $scopes = [];

    /** @var array<int, string> */
    private static array $commands = [];

    /** @var array<string, array<string, mixed>> rendered subject => template context */
    private static array $renders = [];

    public static function rememberRender(EmailTemplate $template, string $subject, ?string $offboardeeName, ?string $offboardingRequestId): void
    {
        unset(self::$renders[$subject]);
        self::$renders[$subject] = array_filter([
            'template_name' => $template->template_name,
            'offboardee_name' => filled($offboardeeName) ? $offboardeeName : null,
            'offboarding_request_id' => is_numeric($offboardingRequestId) ? (int) $offboardingRequestId : null,
        ], fn ($value) => $value !== null);

        // Bounded: a long-running worker renders many templates.
        if (count(self::$renders) > 100) {
            array_shift(self::$renders);
        }
    }

    public static function commandStarted(string $command): void
    {
        self::$commands[] = $command;
    }

    public static function commandFinished(string $command): void
    {
        $index = array_search($command, array_reverse(self::$commands, true), true);
        if ($index !== false) {
            unset(self::$commands[$index]);
            self::$commands = array_values(self::$commands);
        }
    }

    /**
     * Runs `$callback` with extra context (e.g. notification_type,
     * schedule_name, scheduled_for, offboarding_request_id, trigger).
     */
    public static function scope(array $context, callable $callback): mixed
    {
        self::$scopes[] = $context;

        try {
            return $callback();
        } finally {
            array_pop(self::$scopes);
        }
    }

    public static function currentCommand(): ?string
    {
        return self::$commands === [] ? null : end(self::$commands);
    }

    public static function isQueueWorker(): bool
    {
        return in_array(self::currentCommand(), ['queue:work', 'queue:listen'], true);
    }

    /**
     * @return array<string, mixed>
     */
    public static function snapshot(?string $subject = null, array $extra = []): array
    {
        $context = [
            'message_key' => (string) Str::uuid(),
            'notification_type' => EmailLog::TYPE_SYSTEM,
        ];

        $command = self::currentCommand();

        if (app()->runningInConsole() && $command !== null) {
            $context['trigger_source'] = self::isQueueWorker() ? 'queue' : 'console';

            if (isset(self::SCHEDULED_COMMANDS[$command])) {
                $context['notification_type'] = EmailLog::TYPE_SCHEDULED;
                $context['trigger'] = self::SCHEDULED_COMMANDS[$command]." ({$command})";
            } elseif (isset(self::SYSTEM_COMMANDS[$command])) {
                $context['trigger'] = self::SYSTEM_COMMANDS[$command]." ({$command})";
            } elseif (self::isQueueWorker()) {
                $context['trigger'] = 'Background queue job';
            } else {
                $context['trigger'] = "Console command ({$command})";
            }
        } elseif (! app()->runningInConsole() || app()->runningUnitTests()) {
            $route = request()?->route();
            if ($route) {
                $context['trigger_source'] = 'web';
                $context['trigger'] = self::describeRoute((string) ($route->getName() ?? $route->uri()));
            }
        }

        if ($user = auth()->user()) {
            $context['triggered_by_user_id'] = $user->id;
            $context['triggered_by_name'] = $user->name;
        }

        if ($subject !== null && isset(self::$renders[$subject])) {
            $context = array_merge($context, self::$renders[$subject]);
        }

        foreach (self::$scopes as $scope) {
            $context = array_merge($context, array_filter($scope, fn ($value) => $value !== null));
        }

        return array_merge($context, array_filter($extra, fn ($value) => $value !== null));
    }

    /** "approvals.decline" → "Approvals › Decline (web action)". */
    private static function describeRoute(string $name): string
    {
        $label = collect(preg_split('/[.\/]/', $name))
            ->filter()
            ->map(fn ($part) => Str::of($part)->replace(['-', '_'], ' ')->title())
            ->implode(' › ');

        return ($label !== '' ? $label : 'Web request').' (web action)';
    }

    /** Test helper: forget everything remembered in this process. */
    public static function flush(): void
    {
        self::$scopes = [];
        self::$commands = [];
        self::$renders = [];
    }
}
