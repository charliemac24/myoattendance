<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A day (or range of days) when nobody is expected: nobody is marked absent or late, and reports show "Holiday".
 * It applies to the whole organization, or only to one group when group_id is set.
 */
class Holiday extends Model
{
    protected $fillable = ['organization_id', 'group_id', 'name', 'starts_on', 'ends_on'];

    protected function casts(): array
    {
        return ['starts_on' => 'date:Y-m-d', 'ends_on' => 'date:Y-m-d'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
