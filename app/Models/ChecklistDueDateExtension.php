<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One permanent audit row per "Extend Due" action — see the migration's
 * own docblock and `OffboardingRequestApprover::extendDue()`, the only
 * place these are ever created. Never updated after creation.
 */
class ChecklistDueDateExtension extends Model
{
    protected $fillable = [
        'offboarding_request_approver_id',
        'offboarding_request_id',
        'checklist_template_id',
        'checklist_title',
        'previous_due_date',
        'configured_extension_days',
        'additional_extension_days',
        'new_due_date',
        'extended_by',
        'reason',
    ];

    protected function casts(): array
    {
        return [
            'previous_due_date' => 'datetime',
            'new_due_date' => 'datetime',
            'configured_extension_days' => 'integer',
            'additional_extension_days' => 'integer',
        ];
    }

    public function offboardingRequestApprover(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequestApprover::class);
    }

    public function offboardingRequest(): BelongsTo
    {
        return $this->belongsTo(OffboardingRequest::class);
    }

    public function checklistTemplate(): BelongsTo
    {
        return $this->belongsTo(ChecklistTemplate::class);
    }

    /**
     * Who performed the extension — nullable so a since-deleted user
     * account never breaks reading this permanent audit record.
     */
    public function extendedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'extended_by');
    }
}
