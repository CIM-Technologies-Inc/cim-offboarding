<?php

namespace App\Providers;

use App\Listeners\ApplyNoReplyHeaders;
use App\Listeners\RedirectMailInNonProduction;
use App\Mail\LoggingMailManager;
use App\Services\EmailLogRecorder;
use App\Services\MailConfigurator;
use App\Support\EmailLogContext;
use Illuminate\Console\Events\CommandFinished;
use Illuminate\Console\Events\CommandStarting;
use Illuminate\Mail\Events\MessageSending;
use Illuminate\Mail\Events\MessageSent;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Every transport the mailer builds is wrapped so FAILED sends are
        // recorded in the email notification log — see LoggingMailManager.
        $this->app->extend('mail.manager', fn ($manager, $app) => new LoggingMailManager($app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Email notification log (Logs → Email Notifications) — see
        // EmailLogRecorder. Registered FIRST so it records each email's
        // real recipients before RedirectMailInNonProduction (below) can
        // rewrite them; marked "sent" only once the transport accepted it.
        Event::listen(MessageSending::class, fn (MessageSending $event) => app(EmailLogRecorder::class)->sending($event->message, $event->data));
        Event::listen(MessageSent::class, fn (MessageSent $event) => app(EmailLogRecorder::class)->sent($event->sent->getSymfonySentMessage()));
        // Which Artisan command is running decides Scheduled vs
        // System-Triggered for the emails it sends — see EmailLogContext.
        Event::listen(CommandStarting::class, fn (CommandStarting $event) => EmailLogContext::commandStarted((string) $event->command));
        Event::listen(CommandFinished::class, fn (CommandFinished $event) => EmailLogContext::commandFinished((string) $event->command));

        // Only ever active when mail.redirect_to (env MAIL_REDIRECT_TO) is
        // set — see RedirectMailInNonProduction's own docblock. A no-op in
        // production, where that env var must stay unset.
        Event::listen(MessageSending::class, RedirectMailInNonProduction::class);

        // Sets Reply-To to the configured no-reply From address on every
        // outgoing email — see ApplyNoReplyHeaders's own docblock.
        Event::listen(MessageSending::class, ApplyNoReplyHeaders::class);

        // Applies the admin-configured SMTP settings (if any, and only
        // when active) over the .env-sourced mail config, on every
        // request — see MailConfigurator's own docblock for why this
        // needs no config:cache/restart, and why it safely no-ops before
        // the smtp_settings table exists (e.g. during the very first
        // `migrate` run).
        MailConfigurator::apply();
    }
}
