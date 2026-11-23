<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BillItem extends Model
{
    public const TYPE_BASE = 'base_subscription';

    public const TYPE_SMS = 'sms_addon';

    protected $fillable = ['bill_id', 'type', 'description', 'quantity', 'unit_amount_minor', 'amount_minor', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array'];
    }

    public function bill(): BelongsTo
    {
        return $this->belongsTo(Bill::class);
    }
}
