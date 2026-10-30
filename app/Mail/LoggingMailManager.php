<?php

namespace App\Mail;

use Illuminate\Mail\MailManager;

/**
 * Laravel's own MailManager, with every transport it builds (including
 * custom ones registered via `Mail::extend()`) wrapped in
 * `LoggingTransport` so failed sends reach the email notification log.
 * Swapped in by `AppServiceProvider`; nothing else changes.
 */
class LoggingMailManager extends MailManager
{
    public function createSymfonyTransport(array $config)
    {
        return new LoggingTransport(parent::createSymfonyTransport($config));
    }
}
