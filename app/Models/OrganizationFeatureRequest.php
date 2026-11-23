<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OrganizationFeatureRequest extends Model
{
    public const FEATURE_SMS = 'sms';

    public const STATUS_PENDING = 'pending';

    public const STATUS_APPROVED = 'approved';

    public const STATUS_REJECTED = 'rejected';

    public const ACTIVATION_AFTER_TRIAL = 'after_trial';

    public const ACTIVATION_IMMEDIATE = 'immediate';

    /**
     * Used for schools that are no longer on a free trial: SMS starts with the next paid Base + SMS period.
     */
    public const ACTIVATION_NEXT_PAYMENT = 'next_payment';

    protected $fillable = ['organization_id', 'feature', 'status', 'requested_by', 'requested_at', 'quote_sent_at', 'activation_mode', 'reviewed_by', 'reviewed_at', 'notes'];

    protected function casts(): array
    {
        return ['requested_at' => 'datetime', 'quote_sent_at' => 'datetime', 'reviewed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function reviewedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
