<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Schedule extends Model
{
    protected $fillable = ['organization_id', 'group_id', 'subgroup_id', 'name', 'day_of_week', 'start_time', 'late_after', 'end_time', 'periods', 'break_minutes', 'is_active', 'effective_from', 'effective_to'];

    protected function casts(): array
    {
        return ['day_of_week' => 'integer', 'periods' => 'array', 'break_minutes' => 'integer', 'is_active' => 'boolean', 'effective_from' => 'date:Y-m-d', 'effective_to' => 'date:Y-m-d'];
    }

    /** @return array<int, array{start_time: string, late_after: string, end_time: string|null}> */
    public function effectivePeriods(): array
    {
        return $this->periods ?: [['start_time' => $this->start_time, 'late_after' => $this->late_after, 'end_time' => $this->end_time]];
    }

    /**
     * The real start, late-after and end moments of each period for a shift that starts on $date. Times are read in
     * order, and a time earlier than the one before it is on the next day, so a 22:00 to 06:00 shift ends the
     * morning after $date.
     *
     * @return array<int, array{start: Carbon, late_after: Carbon, end: ?Carbon}>
     */
    public function windows(string $date, string $timezone): array
    {
        $cursor = null;
        $at = function (string $time) use ($date, $timezone, &$cursor): Carbon {
            $moment = Carbon::parse($date.' '.substr($time, 0, 5), $timezone);
            while ($cursor && $moment->lt($cursor)) {
                $moment->addDay();
            }

            return $cursor = $moment;
        };

        return array_map(fn (array $period): array => [
            'start' => $at((string) $period['start_time']),
            'late_after' => $at((string) $period['late_after']),
            'end' => empty($period['end_time']) ? null : $at((string) $period['end_time']),
        ], $this->effectivePeriods());
    }

    /** True when the shift ends after midnight (on the day after it starts). */
    public function isOvernight(): bool
    {
        $windows = $this->windows('2000-01-03', 'UTC');
        $last = end($windows);

        return $last && ($last['end'] ?? $last['late_after'])->toDateString() !== '2000-01-03';
    }

    /** Scheduled working minutes: from the first start to the last end, minus the unpaid break. Null without end times. */
    public function scheduledMinutes(): ?int
    {
        $windows = $this->windows('2000-01-03', 'UTC');
        $minutes = 0;
        foreach ($windows as $window) {
            if (! $window['end']) {
                return null;
            }
            $minutes += (int) $window['start']->diffInMinutes($window['end']);
        }

        return max(0, $minutes - (int) $this->break_minutes);
    }

    /** Versions in use on $date: an edited schedule keeps its old version for the days before the edit. */
    public function scopeEffectiveOn(Builder $query, string $date): Builder
    {
        return $query->where(fn (Builder $from) => $from->whereNull('effective_from')->orWhere('effective_from', '<=', $date))
            ->where(fn (Builder $to) => $to->whereNull('effective_to')->orWhere('effective_to', '>=', $date));
    }

    /** Schedules still in use today or later (ended versions are kept only for past reports). */
    public function scopeCurrent(Builder $query, string $today): Builder
    {
        return $query->where(fn (Builder $to) => $to->whereNull('effective_to')->orWhere('effective_to', '>=', $today));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }

    public function subgroup(): BelongsTo
    {
        return $this->belongsTo(Subgroup::class);
    }
}
