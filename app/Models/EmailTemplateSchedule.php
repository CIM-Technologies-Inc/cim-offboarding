<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One HR/Admin-configured notification schedule on an `EmailTemplate` — "N
 * days before/after Last Working Day", with a channel. Reusable across every
 * eligible offboarding request (current and future), never tied to one
 * request — see `SendEmailTemplateSchedules` for how it's actually applied.
 */
class EmailTemplateSchedule extends Model
{
    protected $fillable = [
        'email_template_id',
        'timing',
        'days',
        'channel',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function emailTemplate(): BelongsTo
    {
        return $this->belongsTo(EmailTemplate::class);
    }

    public function sends(): HasMany
    {
        return $this->hasMany(EmailTemplateScheduleSend::class);
    }

    /**
     * Human-readable label for the timeline UI, e.g. "7 days before Last
     * Working Day" or "On Last Working Day" when `days` is 0 (before/after
     * are equivalent at zero days, so the label collapses to one phrase
     * rather than showing a misleading direction).
     */
    public function label(): string
    {
        if ($this->days === 0) {
            return 'On Last Working Day';
        }

        $unit = $this->days === 1 ? 'day' : 'days';
        $timing = $this->timing === 'before' ? 'before' : 'after';

        return "{$this->days} {$unit} {$timing} Last Working Day";
    }

    /**
     * Signed day offset from Last Working Day, used purely to sort schedules
     * chronologically in the timeline UI ('before' sorts earlier than
     * 'after' at the same day count).
     */
    public function sortOffset(): int
    {
        return $this->timing === 'before' ? -$this->days : $this->days;
    }
}
