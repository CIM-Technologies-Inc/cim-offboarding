<?php

namespace App\Services;

use App\Models\SmtpSetting;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Mailer\Transport;

/**
 * The runtime bridge between the admin-configured `smtp_settings` row and
 * Laravel's own `config('mail.*')` — called from
 * `AppServiceProvider::boot()` on every request, so a saved change takes
 * effect immediately with no `config:cache`/restart (confirmed this app
 * doesn't cache config in normal operation; this is plain in-memory
 * `config()` mutation, read fresh every request regardless).
 *
 * Laravel 12's `config/mail.php` has no literal `encryption` key under
 * `mailers.smtp` — Symfony Mailer infers TLS/SSL from a `scheme`, not a
 * config value. `ssl` maps to the `smtps` scheme (implicit TLS, typically
 * port 465); `none`/`tls` both map to the plain `smtp` scheme, since
 * STARTTLS is negotiated opportunistically by the transport on port 587
 * regardless — there is no separate Symfony scheme for "no encryption at
 * all" on an ESMTP connection.
 */
class MailConfigurator
{
    /**
     * @param  array<string, mixed>|null  $overrides  Draft, unsaved form
     *     values to apply for THIS request only (used by the Test
     *     Connection / Send Test Email actions, so an admin can try
     *     settings before saving them) — never persisted. Omitted, this
     *     reads the active DB row instead; if none exists or none is
     *     active, `.env` is left completely untouched as the fallback.
     */
    public static function apply(?array $overrides = null): void
    {
        if (! Schema::hasTable('smtp_settings')) {
            return;
        }

        try {
            $settings = $overrides ?? SmtpSetting::active()?->toConfigArray();

            if (! $settings) {
                return;
            }

            config([
                'mail.default' => $settings['mail_driver'] ?? 'smtp',
                'mail.mailers.smtp.scheme' => self::schemeFor($settings['smtp_encryption'] ?? 'tls'),
                'mail.mailers.smtp.host' => $settings['smtp_host'] ?? null,
                'mail.mailers.smtp.port' => $settings['smtp_port'] ?? null,
                'mail.mailers.smtp.username' => ($settings['smtp_authentication'] ?? false) ? ($settings['smtp_username'] ?? null) : null,
                'mail.mailers.smtp.password' => ($settings['smtp_authentication'] ?? false) ? ($settings['smtp_password'] ?? null) : null,
                'mail.mailers.smtp.timeout' => $settings['smtp_timeout'] ?? null,
                'mail.from.address' => $settings['from_email'] ?? config('mail.from.address'),
                'mail.from.name' => $settings['from_name'] ?? config('mail.from.name'),
            ]);
        } catch (\Throwable $e) {
            // Never includes credentials — just the exception message,
            // same convention every other mail-failure log in this app
            // already uses. Runs on EVERY request (via the service
            // provider), so a DB hiccup here must degrade to the .env
            // fallback, never take the whole app down.
            Log::error('Failed to apply SMTP settings; falling back to .env mail config.', [
                'exception' => $e->getMessage(),
            ]);
        }
    }

    /**
     * A real SMTP handshake (EHLO/STARTTLS/AUTH) with no message sent —
     * distinct from `sendTestEmail()`, which actually delivers one. Never
     * surfaces the raw exception (host/port/auth-server detail) to the
     * caller; only a safe, generic message.
     *
     * @param  array<string, mixed>  $settings
     * @return array{success: bool, message: string}
     */
    public static function testConnection(array $settings): array
    {
        try {
            $transport = Transport::fromDsn(self::buildDsn($settings));
            $transport->start();
            $transport->stop();

            return [
                'success' => true,
                'message' => 'SMTP connection successful. The application can connect to the configured mail server.',
            ];
        } catch (\Throwable $e) {
            Log::error('SMTP connection test failed.', [
                'host' => $settings['smtp_host'] ?? null,
                'port' => $settings['smtp_port'] ?? null,
                'exception' => $e->getMessage(),
            ]);

            return [
                'success' => false,
                'message' => 'SMTP connection failed. Please verify the SMTP host, port, encryption, username, password, and authentication settings.',
            ];
        }
    }

    private static function schemeFor(string $encryption): string
    {
        return $encryption === 'ssl' ? 'smtps' : 'smtp';
    }

    /**
     * @param  array<string, mixed>  $settings
     */
    private static function buildDsn(array $settings): string
    {
        $scheme = self::schemeFor($settings['smtp_encryption'] ?? 'tls');
        $host = $settings['smtp_host'] ?? '';
        $port = $settings['smtp_port'] ?? 587;

        $userinfo = '';

        if (($settings['smtp_authentication'] ?? false) && ! empty($settings['smtp_username'])) {
            $userinfo = rawurlencode($settings['smtp_username']).':'.rawurlencode($settings['smtp_password'] ?? '').'@';
        }

        return "{$scheme}://{$userinfo}{$host}:{$port}";
    }
}
