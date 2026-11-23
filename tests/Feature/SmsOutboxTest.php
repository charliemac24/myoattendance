<?php

namespace Tests\Feature;

use App\Models\AttendanceEvent;
use App\Models\FeatureEntitlement;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\Schedule;
use App\Models\SmsMessage;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsOutboxTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_successful_eligible_scan_queues_one_snapshot_message(): void
    {
        [$user, $organization, $person] = $this->personWithSchedule('09171234567');
        FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => 'sms', 'enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-14 00:15:00', 'UTC'));

        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertJsonPath('code', 'CHECKED_IN');

        $event = AttendanceEvent::firstOrFail();
        $message = SmsMessage::firstOrFail();
        $this->assertSame($organization->id, $message->organization_id);
        $this->assertSame($person->id, $message->person_id);
        $this->assertSame($event->id, $message->attendance_event_id);
        $this->assertSame('time_in', $message->notification_type);
        $this->assertSame('639171234567', $message->recipient_phone);
        $this->assertStringContainsString('Test Person', $message->message);
        $this->assertSame('pending', $message->status->value);
        $this->assertSame('semaphore', $message->provider->value);
        $this->assertSame(0, $message->attempt_count);
        $this->assertNull($message->provider_response);
        $this->assertNull($message->sent_at);
    }

    public function test_sms_disabled_expired_or_not_started_scan_still_succeeds_without_queueing(): void
    {
        foreach (['none', 'expired', 'future'] as $state) {
            [$user, $organization, $person] = $this->personWithSchedule($state);
            if ($state !== 'none') {
                FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => 'sms', 'enabled' => true, 'starts_at' => $state === 'future' ? '2026-10-01' : null, 'expires_at' => $state === 'expired' ? '2026-09-01' : null]);
            }
            Carbon::setTestNow(Carbon::parse('2026-09-14 00:15:00', 'UTC'));
            $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertJsonPath('success', true);
        }

        $this->assertSame(3, AttendanceEvent::count());
        $this->assertSame(0, SmsMessage::count());
    }

    public function test_enabled_scan_without_phone_does_not_fail_or_queue(): void
    {
        [$user, $organization, $person] = $this->personWithSchedule(null);
        FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => 'sms', 'enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-14 00:15:00', 'UTC'));

        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertJsonPath('success', true);

        $this->assertSame(1, AttendanceEvent::count());
        $this->assertSame(0, SmsMessage::count());
    }

    public function test_late_and_checkout_events_use_one_matching_template_each(): void
    {
        [$user, $organization, $person] = $this->personWithSchedule('09171234567');
        FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => 'sms', 'enabled' => true]);
        foreach (['2026-09-14 00:16:00', '2026-09-14 00:30:00'] as $time) {
            Carbon::setTestNow(Carbon::parse($time, 'UTC'));
            $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertJsonPath('success', true);
        }

        $this->assertSame(['late', 'time_out'], SmsMessage::orderBy('id')->pluck('notification_type')->all());
        $this->assertSame($organization->id, SmsMessage::firstOrFail()->organization_id);
    }

    public function test_invalid_and_duplicate_scans_do_not_create_extra_sms(): void
    {
        [$user, $organization, $person] = $this->personWithSchedule('09171234567');
        FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => 'sms', 'enabled' => true]);
        Carbon::setTestNow(Carbon::parse('2026-09-14 00:15:00', 'UTC'));
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => 'invalid'])->assertJsonPath('code', 'INVALID_QR');
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertJsonPath('code', 'CHECKED_IN');
        Carbon::setTestNow(Carbon::parse('2026-09-14 00:15:01', 'UTC'));
        $this->actingAs($user)->postJson('/api/attendance/scan', ['qr_payload' => $person->qr_token])->assertJsonPath('code', 'DUPLICATE_SCAN');
        $this->assertSame(1, SmsMessage::where('organization_id', $organization->id)->count());
    }

    private function personWithSchedule(?string $phone): array
    {
        $organization = Organization::create(['name' => 'Outbox Org '.uniqid(), 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $user = User::create(['organization_id' => $organization->id, 'name' => 'Admin', 'email' => uniqid().'@example.test', 'password' => 'password', 'email_verified_at' => now(), 'role' => 'owner']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Group']);
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => uniqid(), 'first_name' => 'Test', 'last_name' => 'Person', 'group_id' => $group->id, 'contact_number' => $phone]);
        Schedule::create(['organization_id' => $organization->id, 'group_id' => $group->id, 'name' => 'Daily', 'day_of_week' => 1, 'start_time' => '08:00', 'late_after' => '08:15']);

        return [$user, $organization, $person];
    }
}
