<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsPricingAgreement extends Model
{
    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUPERSEDED = 'superseded';

    public const STATUS_CANCELLED = 'cancelled';

    public const SOURCE_ADMIN = 'admin';

    public const SOURCE_ESTIMATE = 'estimate';

    protected $fillable = ['organization_id', 'sms_monthly_price_minor', 'currency', 'person_count_snapshot', 'effective_from', 'effective_until', 'status', 'source', 'created_by', 'agreed_at', 'notes'];

    protected function casts(): array
    {
        return ['effective_from' => 'date', 'effective_until' => 'date', 'agreed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
