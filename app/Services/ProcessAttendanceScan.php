<?php

namespace App\Services;

use App\Models\AttendanceEvent;
use App\Models\AttendanceScanIdempotency;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProcessAttendanceScan
{
    private const COOLDOWN_SECONDS = 5;

    /** Minutes before a period's end time from which a first scan is treated as leaving, not arriving. */
    public const EXIT_WINDOW_MINUTES = 30;

    /** In an open-hours group, scanning in again this soon after a time in is a repeat, not a new visit. */
    public const OPEN_REPEAT_MINUTES = 30;

    /** After a night shift ends, a scan this many hours later can still be its (overtime) time out. */
    public const OVERNIGHT_GRACE_HOURS = 4;

    public function __construct(private readonly ScheduleResolver $scheduleResolver, private readonly QueueAttendanceSms $queueAttendanceSms, private readonly HolidayCalendar $holidays) {}

    /** @return array{success: bool, code: string, message: string, data?: array<string, mixed>} */
    public function handle(Organization $organization, string $qrPayload, ?string $scanRequestId = null, ?CarbonInterface $scanAt = null): array
    {
        $occurredAt = ($scanAt ? Carbon::instance($scanAt) : now())->utc();

        return DB::transaction(function () use ($organization, $qrPayload, $scanRequestId, $occurredAt): array {
            if ($scanRequestId) {
                $existing = AttendanceScanIdempotency::query()
                    ->where('organization_id', $organization->id)
                    ->where('scan_request_id', $scanRequestId)
                    ->lockForUpdate()
                    ->first();
                if ($existing) {
                    return $existing->response + ['idempotent_replay' => true];
                }
            }

            $person = Person::query()
                ->where('organization_id', $organization->id)
                ->where('qr_token', trim($qrPayload))
                ->lockForUpdate()
                ->first();
            $person?->load('group');

            if (! $person) {
                return $this->remember($organization, $scanRequestId, null, $this->failure('INVALID_QR', 'Invalid or unrecognized QR code.'));
            }
            $localTime = $occurredAt->copy()->setTimezone($organization->timezone);
            if (! $person->is_active) {
                $this->logRefusal($organization, $person, 'PERSON_INACTIVE', $occurredAt, $localTime);

                return $this->remember($organization, $scanRequestId, $person, $this->failure('PERSON_INACTIVE', 'This person is inactive.'));
            }

            if ($person->membership_valid_until && $localTime->toDateString() > $person->membership_valid_until->toDateString()) {
                $this->logRefusal($organization, $person, 'MEMBERSHIP_EXPIRED', $occurredAt, $localTime);
                $result = $this->failure('MEMBERSHIP_EXPIRED', 'Membership expired on '.$person->membership_valid_until->format('M j, Y').'.', $this->personData($person) + ['membership' => $this->membershipData($person, $localTime)]);

                return $this->remember($organization, $scanRequestId, $person, $result);
            }
            if ($person->group?->isOpen()) {
                return $this->remember($organization, $scanRequestId, $person, $this->openVisit($organization, $person, $occurredAt, $localTime));
            }

            [$shiftDate, $schedule] = $this->shiftFor($organization, $person, $localTime);
            // Employees who work on a holiday are not on their schedule: the hours count as holiday (rest day) hours.
            // Schools and other organizations keep recording attendance as usual.
            $holiday = $organization->tracksWorkHours() ? $this->holidays->nameOn($organization->id, $person->group_id, $shiftDate) : null;
            if ($holiday) {
                $schedule = null;
            }
            // An employee with a schedule on other days is on a rest day; someone with no schedule at all is not set up yet.
            if (! $schedule && $organization->tracksWorkHours() && $this->hasAnySchedule($person)) {
                return $this->remember($organization, $scanRequestId, $person, $this->restDayScan($organization, $person, $occurredAt, $localTime, $shiftDate, $holiday));
            }
            if (! $schedule) {
                return $this->remember($organization, $scanRequestId, $person, $this->failure('NO_SCHEDULE', 'No attendance schedule is assigned for today.'));
            }

            $events = $this->shiftEvents($organization, $person, $shiftDate);

            $latest = $events->sortBy('occurred_at')->last() ?? $this->latestEvent($organization, $person);
            if ($latest && $latest->occurred_at->diffInSeconds($occurredAt, false) < self::COOLDOWN_SECONDS) {
                $result = $this->failure('DUPLICATE_SCAN', 'Already scanned. Please wait before scanning again.', $this->personData($person, $latest->occurred_at->setTimezone($organization->timezone)->format('g:i A')));

                return $this->remember($organization, $scanRequestId, $person, $result);
            }
            $periods = $schedule->effectivePeriods();
            $windows = $schedule->windows($shiftDate, $organization->timezone);
            $slots = $events->sum(fn (AttendanceEvent $event): int => ClassifyDailyAttendance::slotsUsed($event));
            if ($slots >= count($periods) * 2 && $organization->tracksWorkHours()) {
                // Employees may step out and back in during their shift (e.g. lunch): extra scans alternate in and out.
                return $this->remember($organization, $scanRequestId, $person, $this->extraShiftScan($organization, $person, $schedule, $events, $occurredAt, $localTime, $shiftDate));
            }
            if ($slots >= count($periods) * 2) {
                $result = $this->failure('ATTENDANCE_COMPLETE', 'Attendance for today is already complete.', $this->personData($person));

                return $this->remember($organization, $scanRequestId, $person, $result);
            }

            $eventType = 'check_out';
            $direction = 'OUT';
            $status = null;
            $noTimeIn = false;
            if ($slots % 2 === 0) {
                $periodIndex = intdiv($slots, 2);
                if ($this->isExitScan($localTime, $windows[$periodIndex], $windows[$periodIndex + 1] ?? null)) {
                    // First scan of this period came at dismissal: save it as a time out with no time in, counted as late.
                    $noTimeIn = true;
                    $status = 'LATE';
                } else {
                    $status = $localTime->lessThanOrEqualTo($windows[$periodIndex]['late_after']) ? 'PRESENT' : 'LATE';
                    $eventType = $status === 'LATE' ? 'late_check_in' : 'check_in';
                    $direction = 'IN';
                }
            }

            $meta = ['schedule_id' => $schedule->id, 'group_id' => $person->group_id, 'subgroup_id' => $person->subgroup_id, 'attendance_date' => $shiftDate];
            if ($noTimeIn) {
                $meta['no_time_in'] = true;
            }

            $event = AttendanceEvent::create([
                'organization_id' => $organization->id,
                'person_id' => $person->id,
                'event_type' => $eventType,
                'occurred_at' => $occurredAt,
                'performed_by_user_id' => auth()->id(),
                'meta' => $meta,
            ]);

            $this->queueAttendanceSms->handle($organization, $person, $event);

            $result = [
                'success' => true,
                'code' => $direction === 'IN' ? 'CHECKED_IN' : 'CHECKED_OUT',
                'message' => $direction === 'IN' ? 'Attendance checked in.' : ($noTimeIn ? 'Attendance checked out. No time in was recorded today.' : 'Attendance checked out.'),
                'data' => [
                    'person' => $this->personData($person),
                    'direction' => $direction,
                    'attendance_status' => $status,
                    'no_time_in' => $noTimeIn,
                    'event_type' => $event->event_type,
                    'occurred_at' => $event->occurred_at->toISOString(),
                    'local_time' => $localTime->format('g:i A'),
                    'membership' => $this->membershipData($person, $localTime),
                    'schedule' => ['id' => $schedule->id, 'name' => $schedule->name, 'start_time' => $schedule->start_time, 'late_threshold' => $schedule->late_after, 'periods' => $periods],
                ],
            ];

            return $this->remember($organization, $scanRequestId, $person, $result);
        });
    }

    /**
     * A scan for a person in an open-hours group: no schedule is needed and nobody is late. Each scan is a visit
     * (a time in). When the group also scans people out, a scan after a time in is their time out instead.
     * Scanning in again within OPEN_REPEAT_MINUTES of a time in is treated as a repeat and not saved.
     *
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    private function openVisit(Organization $organization, Person $person, Carbon $occurredAt, CarbonInterface $localTime): array
    {
        $events = AttendanceEvent::query()
            ->where('organization_id', $organization->id)
            ->where('person_id', $person->id)
            ->whereBetween('occurred_at', [$localTime->copy()->startOfDay()->utc(), $localTime->copy()->endOfDay()->utc()])
            ->whereIn('event_type', ['check_in', 'late_check_in', 'check_out'])
            ->orderBy('occurred_at')
            ->get();
        $latest = $events->last();
        $latestLocal = $latest?->occurred_at->copy()->setTimezone($organization->timezone)->format('g:i A');
        if ($latest && $latest->occurred_at->diffInSeconds($occurredAt, false) < self::COOLDOWN_SECONDS) {
            return $this->failure('DUPLICATE_SCAN', 'Already scanned. Please wait before scanning again.', $this->personData($person, $latestLocal));
        }

        $isExit = $person->group->scan_out && $latest && $latest->event_type !== 'check_out';
        if (! $isExit && $latest && $latest->event_type !== 'check_out' && $latest->occurred_at->diffInMinutes($occurredAt, false) < self::OPEN_REPEAT_MINUTES) {
            return $this->failure('DUPLICATE_SCAN', "Already checked in at {$latestLocal}.", $this->personData($person, $latestLocal));
        }

        $event = AttendanceEvent::create([
            'organization_id' => $organization->id,
            'person_id' => $person->id,
            'event_type' => $isExit ? 'check_out' : 'check_in',
            'occurred_at' => $occurredAt,
            'performed_by_user_id' => auth()->id(),
            'meta' => ['mode' => 'open', 'group_id' => $person->group_id, 'subgroup_id' => $person->subgroup_id, 'attendance_date' => $localTime->toDateString()],
        ]);
        $this->queueAttendanceSms->handle($organization, $person, $event);
        $visits = $events->where('event_type', '!=', 'check_out')->count() + ($isExit ? 0 : 1);

        return [
            'success' => true,
            'code' => $isExit ? 'CHECKED_OUT' : 'CHECKED_IN',
            'message' => $isExit ? 'Checked out.' : ($visits > 1 ? "Checked in. Visit {$visits} today." : 'Checked in.'),
            'data' => array_filter([
                'person' => $this->personData($person),
                'direction' => $isExit ? 'OUT' : 'IN',
                'attendance_status' => $isExit ? null : 'PRESENT',
                'mode' => 'open',
                'visits_today' => $visits,
                'no_time_in' => false,
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at->toISOString(),
                'local_time' => $localTime->format('g:i A'),
                'membership' => $this->membershipData($person, $localTime),
            ], static fn ($value) => $value !== null),
        ];
    }

    /**
     * An employee scanning on a day they have no schedule (a rest day): scans alternate time in, time out, and the
     * hours count as rest day work. Schools and other non-employers still refuse these scans.
     *
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    private function restDayScan(Organization $organization, Person $person, Carbon $occurredAt, CarbonInterface $localTime, string $date, ?string $holiday = null): array
    {
        $events = $this->shiftEvents($organization, $person, $date);
        $latest = $events->last() ?? $this->latestEvent($organization, $person);
        if ($latest && $latest->occurred_at->diffInSeconds($occurredAt, false) < self::COOLDOWN_SECONDS) {
            return $this->failure('DUPLICATE_SCAN', 'Already scanned. Please wait before scanning again.', $this->personData($person, $latest->occurred_at->copy()->setTimezone($organization->timezone)->format('g:i A')));
        }

        $isOut = $events->count() % 2 === 1;
        $event = AttendanceEvent::create([
            'organization_id' => $organization->id,
            'person_id' => $person->id,
            'event_type' => $isOut ? 'check_out' : 'check_in',
            'occurred_at' => $occurredAt,
            'performed_by_user_id' => auth()->id(),
            'meta' => ['rest_day' => true, 'group_id' => $person->group_id, 'subgroup_id' => $person->subgroup_id, 'attendance_date' => $date] + ($holiday ? ['holiday' => $holiday] : []),
        ]);
        $this->queueAttendanceSms->handle($organization, $person, $event);

        return [
            'success' => true,
            'code' => $isOut ? 'CHECKED_OUT' : 'CHECKED_IN',
            'message' => $holiday
                ? ($isOut ? "Checked out. Counted as holiday hours ({$holiday})." : "Checked in on {$holiday}. Counted as holiday hours.")
                : ($isOut ? 'Checked out. Rest day hours recorded.' : 'Checked in on a rest day (no schedule today).'),
            'data' => [
                'person' => $this->personData($person),
                'direction' => $isOut ? 'OUT' : 'IN',
                'attendance_status' => $isOut ? null : 'PRESENT',
                'rest_day' => true,
                'holiday' => $holiday,
                'no_time_in' => false,
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at->toISOString(),
                'local_time' => $localTime->format('g:i A'),
            ],
        ];
    }

    /**
     * A scan after an employee's periods are all used, e.g. back from lunch on a one-period shift: the next scan
     * is a time in again (counted as present, never late), the one after a time out, and so on.
     *
     * @param  Collection<int, AttendanceEvent>  $events
     * @return array{success: bool, code: string, message: string, data?: array<string, mixed>}
     */
    private function extraShiftScan(Organization $organization, Person $person, Schedule $schedule, Collection $events, Carbon $occurredAt, CarbonInterface $localTime, string $shiftDate): array
    {
        $isOut = $events->last()?->event_type !== 'check_out';
        $event = AttendanceEvent::create([
            'organization_id' => $organization->id,
            'person_id' => $person->id,
            'event_type' => $isOut ? 'check_out' : 'check_in',
            'occurred_at' => $occurredAt,
            'performed_by_user_id' => auth()->id(),
            'meta' => ['schedule_id' => $schedule->id, 'group_id' => $person->group_id, 'subgroup_id' => $person->subgroup_id, 'attendance_date' => $shiftDate, 'extra' => true],
        ]);
        $this->queueAttendanceSms->handle($organization, $person, $event);

        return [
            'success' => true,
            'code' => $isOut ? 'CHECKED_OUT' : 'CHECKED_IN',
            'message' => $isOut ? 'Attendance checked out.' : 'Checked back in.',
            'data' => [
                'person' => $this->personData($person),
                'direction' => $isOut ? 'OUT' : 'IN',
                'attendance_status' => $isOut ? null : 'PRESENT',
                'no_time_in' => false,
                'event_type' => $event->event_type,
                'occurred_at' => $event->occurred_at->toISOString(),
                'local_time' => $localTime->format('g:i A'),
            ],
        ];
    }

    /**
     * Save a turned-away scan so the daily log can show it. It is not attendance: reports only read ATTENDANCE_TYPES.
     * Scanning the same card again within a minute is not saved twice.
     */
    private function logRefusal(Organization $organization, Person $person, string $reason, Carbon $occurredAt, CarbonInterface $localTime): void
    {
        $recent = AttendanceEvent::query()->where('organization_id', $organization->id)->where('person_id', $person->id)
            ->where('event_type', AttendanceEvent::TYPE_REFUSED)->where('occurred_at', '>', $occurredAt->copy()->subMinute())->exists();
        if ($recent) {
            return;
        }

        AttendanceEvent::create([
            'organization_id' => $organization->id,
            'person_id' => $person->id,
            'event_type' => AttendanceEvent::TYPE_REFUSED,
            'occurred_at' => $occurredAt,
            'performed_by_user_id' => auth()->id(),
            'meta' => array_filter(['reason' => $reason, 'group_id' => $person->group_id, 'subgroup_id' => $person->subgroup_id, 'attendance_date' => $localTime->toDateString(), 'membership_valid_until' => $person->membership_valid_until?->toDateString()], static fn ($value) => $value !== null),
        ]);
    }

    /** @return array{valid_until: string, days_left: int}|null */
    private function membershipData(Person $person, CarbonInterface $localTime): ?array
    {
        if (! $person->membership_valid_until) {
            return null;
        }

        $validUntil = Carbon::parse($person->membership_valid_until->toDateString(), $localTime->getTimezone());

        return ['valid_until' => $validUntil->toDateString(), 'days_left' => (int) $localTime->copy()->startOfDay()->diffInDays($validUntil, false)];
    }

    /**
     * A first scan for a period counts as leaving when it happens within EXIT_WINDOW_MINUTES of the period's end
     * or later, and before the next period starts. Periods without an end time never treat a scan as leaving.
     *
     * @param  array{start: Carbon, late_after: Carbon, end: ?Carbon}  $window
     * @param  array{start: Carbon, late_after: Carbon, end: ?Carbon}|null  $nextWindow
     */
    private function isExitScan(CarbonInterface $localTime, array $window, ?array $nextWindow): bool
    {
        if (! $window['end'] || $localTime->lessThan($window['end']->copy()->subMinutes(self::EXIT_WINDOW_MINUTES))) {
            return false;
        }

        return ! $nextWindow || $localTime->lessThan($nextWindow['start']);
    }

    /**
     * Which shift a scan belongs to: the date it starts on, and its schedule. A scan belongs to last night's
     * shift while that shift is still running (e.g. a late arrival at 12:30 AM for a 10 PM to 6 AM shift), and up
     * to OVERNIGHT_GRACE_HOURS after it ends when the person is still checked in (their time out, with overtime).
     *
     * @return array{0: string, 1: ?Schedule}
     */
    private function shiftFor(Organization $organization, Person $person, CarbonInterface $localTime): array
    {
        $yesterday = $localTime->copy()->subDay()->toDateString();
        $lastNight = $this->scheduleResolver->resolve($person, Carbon::parse($yesterday, $organization->timezone));
        if ($lastNight?->isOvernight()) {
            $windows = $lastNight->windows($yesterday, $organization->timezone);
            $shiftEnds = end($windows)['end'];
            if ($shiftEnds && $localTime->lessThan($shiftEnds)) {
                return [$yesterday, $lastNight];
            }
            if ($shiftEnds && $localTime->lessThan($shiftEnds->copy()->addHours(self::OVERNIGHT_GRACE_HOURS))) {
                $slots = $this->shiftEvents($organization, $person, $yesterday)->sum(fn (AttendanceEvent $event): int => ClassifyDailyAttendance::slotsUsed($event));
                if ($slots % 2 === 1) {
                    return [$yesterday, $lastNight];
                }
            }
        }

        return [$localTime->toDateString(), $this->scheduleResolver->resolve($person, $localTime)];
    }

    /**
     * A person's time ins and outs for the shift that starts on $date, in time order (a night shift's time out the
     * next morning included).
     *
     * @return Collection<int, AttendanceEvent>
     */
    private function shiftEvents(Organization $organization, Person $person, string $date): Collection
    {
        $start = Carbon::parse($date, $organization->timezone)->startOfDay();

        return AttendanceEvent::query()
            ->where('organization_id', $organization->id)
            ->where('person_id', $person->id)
            ->whereBetween('occurred_at', [$start->copy()->utc(), $start->copy()->addDay()->endOfDay()->utc()])
            ->whereIn('event_type', ['check_in', 'late_check_in', 'check_out'])
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->get()
            ->filter(fn (AttendanceEvent $event): bool => $event->attendanceDate($organization->timezone) === $date)
            ->values();
    }

    private function hasAnySchedule(Person $person): bool
    {
        return Schedule::query()->where('organization_id', $person->organization_id)->where('group_id', $person->group_id)->where('is_active', true)
            ->where(fn ($query) => $query->whereNull('subgroup_id')->orWhere('subgroup_id', $person->subgroup_id))->exists();
    }

    /** The person's latest scan, for the duplicate-scan check across shifts (e.g. right after a night shift ends). */
    private function latestEvent(Organization $organization, Person $person): ?AttendanceEvent
    {
        return AttendanceEvent::query()
            ->where('organization_id', $organization->id)
            ->where('person_id', $person->id)
            ->whereIn('event_type', ['check_in', 'late_check_in', 'check_out'])
            ->latest('occurred_at')
            ->first();
    }

    private function remember(Organization $organization, ?string $requestId, ?Person $person, array $result): array
    {
        if ($requestId) {
            $now = now()->utc();
            $inserted = AttendanceScanIdempotency::query()->insertOrIgnore([
                'organization_id' => $organization->id,
                'scan_request_id' => $requestId,
                'person_id' => $person?->id,
                'response' => json_encode($result, JSON_THROW_ON_ERROR),
                'processed_at' => $now,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 0) {
                $existing = AttendanceScanIdempotency::query()
                    ->where('organization_id', $organization->id)
                    ->where('scan_request_id', $requestId)
                    ->firstOrFail();

                return $existing->response + ['idempotent_replay' => true];
            }
        }

        return $result;
    }

    private function failure(string $code, string $message, ?array $data = null): array
    {
        return array_filter(['success' => false, 'code' => $code, 'message' => $message, 'data' => $data], static fn ($value) => $value !== null);
    }

    private function personData(Person $person, ?string $localTime = null): array
    {
        return array_filter(['id' => $person->id, 'person_number' => $person->person_number, 'first_name' => $person->first_name, 'last_name' => $person->last_name, 'local_time' => $localTime], static fn ($value) => $value !== null);
    }
}
