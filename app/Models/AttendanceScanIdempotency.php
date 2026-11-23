<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AttendanceScanIdempotency extends Model
{
    protected $fillable = ['organization_id', 'scan_request_id', 'person_id', 'response', 'processed_at'];

    protected function casts(): array
    {
        return ['response' => 'array', 'processed_at' => 'datetime'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function person(): BelongsTo
    {
        return $this->belongsTo(Person::class);
    }
}
