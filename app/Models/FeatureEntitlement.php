<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FeatureEntitlement extends Model
{
    public const SMS = 'sms';

    protected $fillable = ['organization_id', 'feature', 'enabled', 'starts_at', 'expires_at', 'enabled_by', 'disabled_at', 'disabled_by'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean', 'starts_at' => 'date', 'expires_at' => 'date', 'disabled_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function enabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'enabled_by');
    }

    public function disabledBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disabled_by');
    }
}
