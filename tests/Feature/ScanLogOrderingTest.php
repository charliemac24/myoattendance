<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use App\Models\User;
use App\Services\BuildHomeDashboard;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ScanLogOrderingTest extends TestCase
{
    use RefreshDatabase;

    private const DAY_ONE = '2026-11-09';

    private const DAY_TWO = '2026-11-10';

    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        Http::fake();
        $this->fixture = $this->createFixture();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_daily_logs_list_most_recent_activity_first(): void
    {
        $this->createScans();
        $this->assertSame(
            ['Zed Zamora', 'Eve Cruz', 'Dan Cruz', 'Mia Mendoza', 'Ana Abad', 'Ivy Ito', 'Lee Lopez'],
            $this->names($this->dailyReport(self::DAY_ONE)['people']),
        );
    }

    public function test_same_second_ties_are_deterministic(): void
    {
        $this->createScans();
        $orders = [];

        for ($i = 0; $i < 20; $i++) {
            $rows = $this->dailyReport(self::DAY_ONE)['people'];
            $orders[] = array_slice($this->names($rows), 1, 2);
        }

        $this->assertSame(array_fill(0, 20, ['Eve Cruz', 'Dan Cruz']), $orders);
    }

    public function test_checkout_moves_person_to_top(): void
    {
        $this->createScans(6);
        $before = $this->dailyReport(self::DAY_ONE, '2026-11-09 08:09:00');
        $this->assertSame('Eve Cruz', $before['people'][0]['person']['first_name'].' '.$before['people'][0]['person']['last_name']);

        $this->createScans(1, 6);
        $after = $this->dailyReport(self::DAY_ONE, '2026-11-09 08:11:00');
        $this->assertSame('Zed Zamora', $after['people'][0]['person']['first_name'].' '.$after['people'][0]['person']['last_name']);
    }

    public function test_days_are_isolated_and_ordered(): void
    {
        $this->createScans();
        $this->assertSame(['Ana Abad', 'Mia Mendoza'], array_slice($this->names($this->dailyReport(self::DAY_TWO)['people']), 0, 2));
        $dayOne = $this->dailyReport(self::DAY_ONE);
        $this->assertSame(['Zed Zamora', 'Eve Cruz', 'Dan Cruz', 'Mia Mendoza', 'Ana Abad', 'Ivy Ito', 'Lee Lopez'], $this->names($dayOne['people']));
        $this->assertSame('8:10 AM', $dayOne['people'][0]['check_out_time']);
    }

    public function test_local_midnight_boundary(): void
    {
        $this->createScans();
        $dayOne = $this->dailyReport(self::DAY_ONE);
        $dayBefore = $this->dailyReport('2026-11-08');

        $this->assertContains('Ivy Ito', $this->names($dayOne['people']));
        $ivyYesterday = collect($dayBefore['people'])->first(fn (array $row) => $row['person']['first_name'].' '.$row['person']['last_name'] === 'Ivy Ito');
        $this->assertSame('ABSENT', $ivyYesterday['status']);
        $this->assertNull($ivyYesterday['check_in_time']);
    }

    public function test_dashboard_recent_activity_newest_first(): void
    {
        $this->createScans();
        Carbon::setTestNow(Carbon::parse('2026-11-09 08:11:00', 'Asia/Manila'));
        $dashboard = app(BuildHomeDashboard::class)->handle($this->fixture['organization'], Carbon::now('Asia/Manila'));
        $expected = ['Zed Zamora', 'Eve Cruz', 'Dan Cruz'];

        for ($i = 0; $i < 20; $i++) {
            $events = app(BuildHomeDashboard::class)->handle($this->fixture['organization'], Carbon::now('Asia/Manila'))['recent_activity'];
            $this->assertSame($expected, array_map(fn (array $event) => $event['person']['name'], $events));
        }

        $this->assertCount(3, $dashboard['recent_activity']);
    }

    public function test_absent_people_are_listed_after_scans_by_name(): void
    {
        $this->createScans();
        // Day 2: only Ana and Mia scanned, so everyone else is ABSENT and sorted by last name, first name, person number.
        $this->assertSame(['Ana Abad', 'Mia Mendoza', 'Dan Cruz', 'Eve Cruz', 'Ivy Ito', 'Lee Lopez', 'Zed Zamora'], $this->names($this->dailyReport(self::DAY_TWO)['people']));
    }

    private function createFixture(): array
    {
        $organization = Organization::create(['name' => 'Ordering Test Organization', 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Admin Ordering', 'email' => 'ordering-admin@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Ordering Group']);
        $people = [];

        foreach ([
            ['Zed', 'Zamora'], ['Ana', 'Abad'], ['Mia', 'Mendoza'], ['Dan', 'Cruz'],
            ['Eve', 'Cruz'], ['Lee', 'Lopez'], ['Ivy', 'Ito'],
        ] as $index => [$first, $last]) {
            $people[$first] = Person::create([
                'organization_id' => $organization->id,
                'person_number' => sprintf('P%02d', $index + 1),
                'first_name' => $first,
                'last_name' => $last,
                'group_id' => $group->id,
                'contact_number' => null,
            ]);
            $people[$first]->forceFill(['created_at' => '2026-08-01 00:00:00'])->save();
        }

        for ($day = 1; $day <= 7; $day++) {
            Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Daily', 'day_of_week' => $day, 'start_time' => '08:00', 'late_after' => '08:15']);
        }

        return compact('organization', 'user', 'people');
    }

    private function createScans(?int $limit = null, int $offset = 0): void
    {
        $scans = [
            ['2026-11-09 00:30:00', 'Ivy'], ['2026-11-09 08:00:00', 'Zed'], ['2026-11-09 08:01:00', 'Ana'],
            ['2026-11-09 08:02:00', 'Mia'], ['2026-11-09 08:05:00', 'Dan'], ['2026-11-09 08:05:00', 'Eve'],
            ['2026-11-09 08:10:00', 'Zed'], ['2026-11-10 07:30:00', 'Mia'], ['2026-11-10 07:31:00', 'Ana'],
        ];

        foreach (array_slice($scans, $offset, $limit) as [$time, $first]) {
            Carbon::setTestNow(Carbon::parse($time, 'Asia/Manila'));
            $response = $this->actingAs($this->fixture['user'])->postJson('/api/attendance/scan', ['qr_payload' => $this->fixture['people'][$first]->qr_token]);
            $response->assertOk()->assertJsonPath('code', fn ($code) => in_array($code, ['CHECKED_IN', 'CHECKED_OUT'], true));
        }
    }

    private function dailyReport(string $date, ?string $now = null): array
    {
        Carbon::setTestNow(Carbon::parse($now ?: $date.' 23:00:00', 'Asia/Manila'));

        return $this->actingAs($this->fixture['user'])->getJson('/api/attendance/report?date='.$date)->assertOk()->json();
    }

    private function names(array $rows): array
    {
        return array_map(fn (array $row) => $row['person']['first_name'].' '.$row['person']['last_name'], $rows);
    }
}
