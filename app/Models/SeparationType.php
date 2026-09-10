<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An admin-managed Separation Type (Resignation, Retirement, etc.) shown on
 * the New Offboarding Request form's "Separation Type" picker — see
 * `SeparationTypeController` for the CRUD page that manages these.
 *
 * Never read live by an already-created `OffboardingRequest` — its own
 * `reason`/`separation_type_description`/`notice_period_days` columns are a
 * frozen snapshot taken at creation time (`OffboardingRequestController::store()`),
 * specifically so editing or deleting a row here can never alter a request
 * that already exists. `offboardingRequests()` below is for
 * reporting/traceability only.
 */
class SeparationType extends Model
{
    protected $fillable = [
        'title',
        'description',
        'default_notice_period_days',
    ];

    protected function casts(): array
    {
        return [
            'default_notice_period_days' => 'integer',
        ];
    }

    public function offboardingRequests(): HasMany
    {
        return $this->hasMany(OffboardingRequest::class);
    }
}
