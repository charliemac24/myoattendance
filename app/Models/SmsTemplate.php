<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SmsTemplate extends Model
{
    public const TIME_IN = 'time_in';

    public const TIME_OUT = 'time_out';

    public const LATE = 'late';

    public const ABSENT = 'absent';

    protected $fillable = ['organization_id', 'template_key', 'name', 'body', 'enabled'];

    protected function casts(): array
    {
        return ['enabled' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }
}
