<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BaseSubscription extends Model
{
    public const STATUS_TRIALING = 'trialing';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_EXPIRED = 'expired';

    protected $fillable = ['organization_id', 'trial_starts_at', 'trial_ends_at', 'subscription_starts_at', 'subscription_expires_at', 'status', 'start_choice', 'activated_by'];

    protected function casts(): array
    {
        return ['trial_starts_at' => 'datetime', 'trial_ends_at' => 'datetime', 'subscription_starts_at' => 'datetime', 'subscription_expires_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function activatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'activated_by');
    }
}
