<?php

namespace Tests\Feature;

use App\Enums\SmsMessageStatus;
use App\Enums\SmsProvider;
use App\Models\AttendanceEvent;
use App\Models\FeatureEntitlement;
use App\Models\Group;
use App\Models\Organization;
use App\Models\Person;
use App\Models\SmsMessage;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SmsProcessorTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_sends_snapshots_and_persists_provider_metadata(): void
    {
        $message = $this->message();
        config(['services.semaphore.api_key' => 'secret-test-key', 'services.semaphore.sender_name' => 'Fallback']);
        Http::fake(['https://api.semaphore.co/*' => Http::response([['message_id' => 12345, 'status' => 'Queued', 'sender_name' => 'MYO']], 200)]);

        $this->artisan('sms:process')->assertSuccessful();
        Http::assertSent(function ($request) use ($message): bool {
            return $request->url() === 'https://api.semaphore.co/api/v4/messages'
                && $request['apikey'] === 'secret-test-key'
                && $request['number'] === $message->recipient_phone
                && $request['message'] === $message->message
                && $request['sendername'] === $message->sender_name;
        });
        $message->refresh();
        $this->assertSame(SmsMessageStatus::Sent, $message->status);
        $this->assertSame(1, $message->attempt_count);
        $this->assertSame('12345', $message->provider_message_id);
        $this->assertSame('Queued', $message->provider_status);
        $this->assertArrayNotHasKey('apikey', $message->provider_response);
        $this->assertNotNull($message->sent_at);
    }

    public function test_retryable_failure_is_scheduled_and_does_not_stop_later_messages(): void
    {
        $first = $this->message();
        $second = $this->message();
        Http::fakeSequence()->pushStatus(500)->push([['message_id' => 99, 'status' => 'Queued']]);

        Carbon::setTestNow(Carbon::parse('2026-09-18 01:00:00'));
        $this->artisan('sms:process')->assertSuccessful();
        $this->assertSame(SmsMessageStatus::Pending, $first->fresh()->status);
        $this->assertSame(1, $first->fresh()->attempt_count);
        $this->assertSame('provider_temporary_failure', $first->fresh()->last_error_code);
        $this->assertSame(SmsMessageStatus::Sent, $second->fresh()->status);
    }

    public function test_permanent_provider_rejection_is_not_retried(): void
    {
        $message = $this->message();
        Http::fake(['*' => Http::response([['status' => 'Failed', 'message_id' => 10]], 200)]);

        $this->artisan('sms:process')->assertSuccessful();
        $message = $message->fresh();
        $this->assertSame(SmsMessageStatus::Failed, $message->status);
        $this->assertSame('provider_rejected', $message->last_error_code);
        $this->assertNull($message->next_attempt_at);
        $this->assertNotNull($message->failed_at);
    }

    public function test_only_due_pending_messages_are_claimed_and_limit_is_bounded(): void
    {
        $messages = collect(range(1, 3))->map(fn () => $this->message());
        $messages[1]->update(['next_attempt_at' => now()->addHour()]);
        $messages[2]->update(['status' => SmsMessageStatus::Sent]);
        Http::fake(['*' => Http::response([['message_id' => 1, 'status' => 'Queued']], 200)]);

        $this->artisan('sms:process', ['--limit' => 1])->assertSuccessful();
        Http::assertSentCount(1);
        $this->assertSame(SmsMessageStatus::Sent, $messages[0]->fresh()->status);
        $this->assertSame(SmsMessageStatus::Pending, $messages[1]->fresh()->status);
        $this->assertSame(SmsMessageStatus::Sent, $messages[2]->fresh()->status);
    }

    public function test_queued_message_is_not_sent_after_sms_access_expires(): void
    {
        $message = $this->message();
        $message->organization->featureEntitlements()->where('feature', 'sms')->update(['expires_at' => '2026-09-01']);
        Http::fake();
        Carbon::setTestNow(Carbon::parse('2026-09-19 00:00:00'));

        $this->artisan('sms:process')->assertSuccessful();

        Http::assertNothingSent();
        $this->assertSame(SmsMessageStatus::Failed, $message->fresh()->status);
        $this->assertSame('sms_not_entitled', $message->fresh()->last_error_code);
    }

    private function message(): SmsMessage
    {
        $organization = Organization::create(['name' => 'Processor Org '.uniqid(), 'type' => 'company', 'timezone' => 'Asia/Manila']);
        FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => 'sms', 'enabled' => true]);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Group']);
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => uniqid(), 'first_name' => 'Test', 'last_name' => 'Person', 'group_id' => $group->id, 'contact_number' => '+639171234567']);
        $event = AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'check_in', 'occurred_at' => now()]);

        return SmsMessage::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'attendance_event_id' => $event->id, 'notification_type' => 'time_in', 'recipient_phone' => '639171234567', 'message' => 'Original snapshot', 'status' => SmsMessageStatus::Pending, 'provider' => SmsProvider::Semaphore, 'sender_name' => 'MYO', 'attempt_count' => 0]);
    }
}
