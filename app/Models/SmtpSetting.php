<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A singleton settings record (see the `create_smtp_settings_table`
 * migration's own docblock for why this is one row, not many) — the
 * admin-configured SMTP server this app uses instead of `.env`, applied
 * at runtime by `MailConfigurator::apply()`. `smtp_password` is held via
 * Laravel's built-in `'encrypted'` cast (backed by `APP_KEY`, via the
 * `Crypt` facade) — the first use of that cast in this app, and exactly
 * the "Laravel's recommended encryption mechanism" the feature asked to
 * reuse rather than hand-rolling a new one.
 */
class SmtpSetting extends Model
{
    protected $fillable = [
        'mail_driver',
        'smtp_host',
        'smtp_port',
        'smtp_encryption',
        'smtp_username',
        'smtp_password',
        'smtp_authentication',
        'smtp_timeout',
        'from_email',
        'from_name',
        'is_active',
        'created_by',
        'updated_by',
    ];

    // Belt-and-suspenders on top of the controller never passing this to
    // a view — even an accidental array/JSON cast of the model (e.g. a
    // stray `response()->json($setting)`) can never leak it.
    protected $hidden = [
        'smtp_password',
    ];

    protected function casts(): array
    {
        return [
            'smtp_password' => 'encrypted',
            'smtp_authentication' => 'boolean',
            'is_active' => 'boolean',
            'smtp_port' => 'integer',
            'smtp_timeout' => 'integer',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    /**
     * The one settings row, whether active or not — for the edit page,
     * which must show (and let the admin toggle) an inactive
     * configuration too.
     */
    public static function current(): ?self
    {
        return static::first();
    }

    /**
     * Only when explicitly enabled — the single source of truth
     * `MailConfigurator::apply()` reads before overriding `.env`.
     */
    public static function active(): ?self
    {
        return static::where('is_active', true)->first();
    }

    /**
     * The plain array shape `MailConfigurator::apply()`/`testConnection()`
     * expect — `smtp_password` here is the CAST-DECRYPTED plaintext
     * (needed to actually authenticate with the mail server), so this
     * array must never be passed to a view, logged, or serialized back
     * to the browser. See `SmtpSettingController`'s own docblocks for
     * where that boundary is enforced.
     *
     * @return array<string, mixed>
     */
    public function toConfigArray(): array
    {
        return [
            'mail_driver' => $this->mail_driver,
            'smtp_host' => $this->smtp_host,
            'smtp_port' => $this->smtp_port,
            'smtp_encryption' => $this->smtp_encryption,
            'smtp_username' => $this->smtp_username,
            'smtp_password' => $this->smtp_password,
            'smtp_authentication' => $this->smtp_authentication,
            'smtp_timeout' => $this->smtp_timeout,
            'from_email' => $this->from_email,
            'from_name' => $this->from_name,
        ];
    }
}
