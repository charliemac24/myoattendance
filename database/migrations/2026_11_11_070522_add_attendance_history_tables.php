<?php

use Carbon\Carbon;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keep past attendance the way it was: which group/subgroup each person was in on each date, when a schedule
     * applied (edits start a new version instead of rewriting the old one), and when a person was archived.
     */
    public function up(): void
    {
        Schema::create('person_assignments', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->foreignId('person_id')->constrained('persons')->cascadeOnDelete();
            $table->foreignId('group_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('subgroup_id')->nullable()->constrained()->nullOnDelete();
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->timestamps();
            $table->index(['person_id', 'starts_on']);
            $table->index(['organization_id', 'group_id']);
        });

        Schema::table('persons', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('is_active');
        });

        Schema::table('schedules', function (Blueprint $table): void {
            $table->date('effective_from')->nullable()->after('is_active');
            $table->date('effective_to')->nullable()->after('effective_from');
            // A schedule edited after it was in use keeps its old version, so one scope and day can have several rows.
            $table->index(['organization_id', 'group_id', 'day_of_week'], 'schedules_scope_day_index');
        });
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropUnique('schedule_scope_day_unique');
        });

        DB::table('persons')->where('is_active', false)->whereNull('archived_at')->update(['archived_at' => DB::raw('updated_at')]);
        $this->backfillAssignments();
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table): void {
            $table->unique(['organization_id', 'group_id', 'subgroup_id', 'day_of_week'], 'schedule_scope_day_unique');
        });
        Schema::table('schedules', function (Blueprint $table): void {
            $table->dropIndex('schedules_scope_day_index');
            $table->dropColumn(['effective_from', 'effective_to']);
        });
        Schema::table('persons', function (Blueprint $table): void {
            $table->dropColumn('archived_at');
        });
        Schema::dropIfExists('person_assignments');
    }

    /**
     * Each scan saved the group and subgroup the person was in. Rebuild past moves from those, ending with the
     * person's current group; with no scans (or no moves), one assignment covers all time.
     */
    private function backfillAssignments(): void
    {
        $timezones = DB::table('organizations')->pluck('timezone', 'id');
        $now = now();
        DB::table('persons')->orderBy('id')->chunk(200, function ($people) use ($timezones, $now): void {
            $rows = [];
            foreach ($people as $person) {
                $timezone = $timezones[$person->organization_id] ?? 'UTC';
                $segments = [];
                $events = DB::table('attendance_events')->where('person_id', $person->id)->orderBy('occurred_at')->orderBy('id')->get(['occurred_at', 'meta']);
                foreach ($events as $event) {
                    $meta = json_decode((string) $event->meta, true) ?: [];
                    if (! array_key_exists('group_id', $meta)) {
                        continue;
                    }
                    $date = $meta['attendance_date'] ?? Carbon::parse($event->occurred_at, 'UTC')->setTimezone($timezone)->toDateString();
                    $scope = [$meta['group_id'], $meta['subgroup_id'] ?? null];
                    $last = end($segments);
                    if ($last && $last['scope'] === $scope) {
                        continue;
                    }
                    $segments[] = ['scope' => $scope, 'from' => $date];
                }
                $current = [$person->group_id, $person->subgroup_id];
                $last = end($segments);
                if (! $last || $last['scope'] !== $current) {
                    // Moved after their last scan in the old group: the move is counted from the day after it.
                    $segments[] = ['scope' => $current, 'from' => $last ? Carbon::parse($this->lastScanDate($person->id, $timezone))->addDay()->toDateString() : null];
                }
                $segments[0]['from'] = null;
                foreach ($segments as $index => $segment) {
                    $next = $segments[$index + 1] ?? null;
                    $endsOn = $next ? Carbon::parse($next['from'])->subDay()->toDateString() : null;
                    if ($endsOn !== null && $segment['from'] !== null && $endsOn < $segment['from']) {
                        continue;
                    }
                    $rows[] = ['organization_id' => $person->organization_id, 'person_id' => $person->id, 'group_id' => $segment['scope'][0], 'subgroup_id' => $segment['scope'][1], 'starts_on' => $segment['from'], 'ends_on' => $endsOn, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            // Groups or subgroups deleted since a scan cannot be referenced; those rows fall back to no group.
            $groupIds = DB::table('groups')->pluck('id')->flip();
            $subgroupIds = DB::table('subgroups')->pluck('id')->flip();
            foreach ($rows as &$row) {
                if ($row['group_id'] !== null && ! $groupIds->has($row['group_id'])) {
                    $row['group_id'] = null;
                    $row['subgroup_id'] = null;
                }
                if ($row['subgroup_id'] !== null && ! $subgroupIds->has($row['subgroup_id'])) {
                    $row['subgroup_id'] = null;
                }
            }
            unset($row);
            DB::table('person_assignments')->insert($rows);
        });
    }

    private function lastScanDate(int $personId, string $timezone): string
    {
        $event = DB::table('attendance_events')->where('person_id', $personId)->orderByDesc('occurred_at')->first(['occurred_at', 'meta']);
        $meta = json_decode((string) $event->meta, true) ?: [];

        return $meta['attendance_date'] ?? Carbon::parse($event->occurred_at, 'UTC')->setTimezone($timezone)->toDateString();
    }
};
