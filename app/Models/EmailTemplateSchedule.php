<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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
        'event',
        'timing',
        'days',
        'clearance_signing_deadline_days',
        'channel',
        'repeat_enabled',
        'repeat_interval_days',
        'is_active',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'days' => 'integer',
            'clearance_signing_deadline_days' => 'integer',
            'is_active' => 'boolean',
            'repeat_enabled' => 'boolean',
            'repeat_interval_days' => 'integer',
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
     * The "Active Checklist Selection" for this one schedule — which
     * checklist templates' Clearance Signatories it should reach. Empty
     * means no selection was made; see `SendEmailTemplateSchedules::resolveRecipients()`
     * for how that's resolved (every checklist actually attached to the
     * request, never "nobody").
     */
    public function checklistTemplates(): BelongsToMany
    {
        return $this->belongsToMany(ChecklistTemplate::class, 'email_template_schedule_checklist_templates');
    }

    /**
     * The "Active General Signatory Selection" for this one schedule —
     * which General Signatories (via their own Clearance Signatory) it
     * should reach. Empty means no selection was made; same "defaults to
     * everyone attached to the request" resolution as `checklistTemplates()`
     * above.
     */
    public function generalSignatories(): BelongsToMany
    {
        return $this->belongsToMany(GeneralSignatory::class, 'email_template_schedule_general_signatories');
    }

    /**
     * `event` => its display label, used both here and wherever the admin
     * UI needs the same mapping (e.g. `workspace.blade.php`'s own
     * `scheduleLabel()` JS mirrors this exact list).
     */
    public const EVENT_LABELS = [
        'creation' => 'Creation',
        'last_working_day' => 'Last Working Day',
        'clearance' => 'Clearance Due Date',
        'final_pay' => 'Final Pay',
    ];

    /**
     * Human-readable label for the timeline UI, e.g. "7 days Before
     * Clearance (19 days after Last Working Day)" or "On Last Working Day"
     * when `days` is 0 (before/after are equivalent at zero days, so the
     * label collapses to one phrase rather than showing a misleading
     * direction) — a cosmetic convenience on top of the ticket's own
     * literal "{days} days {Timing} {Event}" template, not a departure from
     * it.
     */
    /**
     * The repeat interval in days when Repeat is on (and has a valid
     * interval), otherwise null — the one place every caller checks whether
     * this schedule recurs.
     */
    public function repeatsEvery(): ?int
    {
        return $this->repeat_enabled && $this->repeat_interval_days > 0 ? $this->repeat_interval_days : null;
    }

    public function label(): string
    {
        $eventLabel = self::EVENT_LABELS[$this->event] ?? $this->event;

        if ($this->days === 0) {
            $base = "On {$eventLabel}";
        } else {
            $unit = $this->days === 1 ? 'day' : 'days';
            $timingLabel = $this->timing === 'before' ? 'Before' : 'After';
            $base = "{$this->days} {$unit} {$timingLabel} {$eventLabel}";
        }

        if ($this->repeatsEvery() !== null) {
            $every = $this->repeatsEvery() === 1 ? 'day' : "{$this->repeatsEvery()} days";
            $base .= ", repeating every {$every}";
        }

        return $base;
    }
}
