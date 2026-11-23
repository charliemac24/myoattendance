<?php

namespace Tests\Feature;

use App\Enums\SmsMessageStatus;
use App\Enums\SmsProvider;
use App\Models\AttendanceEvent;
use App\Models\AttendanceScanIdempotency;
use App\Models\FeatureEntitlement;
use App\Models\Group;
use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Models\Person;
use App\Models\SmsMessage;
use App\Models\User;
use App\Services\FeatureEntitlementService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class SuperAdminSubscribersTest extends TestCase
{
    use RefreshDatabase;

    private function admin(): User
    {
        return User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
    }

    public function test_only_platform_super_admin_can_access_namespace(): void
    {
        $organization = Organization::create(['name' => 'Tenant', 'type' => 'company']);
        $tenant = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);

        $this->getJson('/api/super-admin/organizations')->assertUnauthorized();
        $this->actingAs($tenant)->getJson('/api/super-admin/organizations')->assertForbidden();
        $this->actingAs($this->admin())->getJson('/api/super-admin/organizations')->assertOk();
    }

    public function test_organization_search_details_and_payment_history_are_server_side(): void
    {
        $admin = $this->admin();
        $organization = Organization::create(['name' => 'Stars Educational Center', 'type' => 'school']);
        User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner', 'name' => 'Maria Owner', 'email' => 'maria@example.test']);

        $this->actingAs($admin)->getJson('/api/super-admin/organizations?search=maria')->assertOk()->assertJsonPath('data.0.name', $organization->name);
        $this->actingAs($admin)->getJson('/api/super-admin/organizations/'.$organization->id)->assertOk()->assertJsonPath('organization.owner.email', 'maria@example.test');
        $this->actingAs($admin)->postJson('/api/super-admin/organizations/'.$organization->id.'/payments', [
            'amount' => '4000.50', 'payment_method' => 'gcash', 'reference_number' => '000012345678',
            'paid_at' => '2026-09-30 10:00:00', 'coverage_start' => '2026-10-01', 'coverage_end' => '2026-10-31', 'notes' => 'October',
        ])->assertCreated()->assertJsonPath('payment.reference_number', '000012345678');

        $this->assertDatabaseHas('payments', ['organization_id' => $organization->id, 'amount_minor' => 400050, 'recorded_by' => $admin->id]);
        $this->actingAs($admin)->getJson('/api/super-admin/payments')->assertOk()->assertJsonPath('data.0.organization.id', $organization->id);
    }

    public function test_entitlement_rules_have_inclusive_dates_and_manual_operations(): void
    {
        $admin = $this->admin();
        $organization = Organization::create(['name' => 'Subscriber', 'type' => 'company']);
        $service = app(FeatureEntitlementService::class);
        Carbon::setTestNow('2026-10-31 12:00:00');

        $this->assertFalse($service->canUseSms($organization));
        $this->actingAs($admin)->postJson('/api/super-admin/organizations/'.$organization->id.'/sms/enable', ['starts_at' => '2026-10-01', 'expires_at' => '2026-10-31'])->assertOk();
        $this->assertTrue($service->canUseSms($organization->fresh(), now()));
        $this->actingAs($admin)->postJson('/api/super-admin/organizations/'.$organization->id.'/sms/extend', ['expires_at' => '2026-11-30'])->assertOk();
        $this->assertSame('2026-11-30', $organization->featureEntitlements()->firstOrFail()->fresh()->expires_at->toDateString());
        $this->actingAs($admin)->postJson('/api/super-admin/organizations/'.$organization->id.'/sms/disable')->assertOk();
        $this->assertFalse($service->canUseSms($organization->fresh(), now()));
        Carbon::setTestNow();
    }

    public function test_enabling_sms_marks_a_pending_request_as_approved(): void
    {
        $admin = $this->admin();
        $organization = Organization::create(['name' => 'Approval Sync', 'type' => 'company']);
        $request = OrganizationFeatureRequest::create(['organization_id' => $organization->id, 'feature' => OrganizationFeatureRequest::FEATURE_SMS, 'status' => OrganizationFeatureRequest::STATUS_PENDING, 'requested_by' => $admin->id, 'requested_at' => now()]);

        $this->actingAs($admin)->postJson('/api/super-admin/organizations/'.$organization->id.'/sms/enable')->assertOk();

        $this->assertSame(OrganizationFeatureRequest::STATUS_APPROVED, $request->fresh()->status);
        $this->assertSame($admin->id, $request->fresh()->reviewed_by);
    }

    public function test_future_start_and_expired_access_are_denied_without_a_job(): void
    {
        $organization = Organization::create(['name' => 'Future', 'type' => 'company']);
        FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => 'sms', 'enabled' => true, 'starts_at' => '2026-10-02', 'expires_at' => '2026-10-31']);
        $service = app(FeatureEntitlementService::class);
        $this->assertFalse($service->canUseSms($organization, Carbon::parse('2026-10-01')));
        $this->assertTrue($service->canUseSms($organization, Carbon::parse('2026-10-02')));
        $this->assertFalse($service->canUseSms($organization, Carbon::parse('2026-11-01')));
    }

    public function test_super_admin_can_set_or_clear_an_organization_sms_sender_name(): void
    {
        $admin = $this->admin();
        $organization = Organization::create(['name' => 'Sender Org', 'type' => 'company']);
        config(['services.semaphore.sender_name' => 'MYODEFAULT']);

        $this->actingAs($admin)->getJson('/api/super-admin/organizations/'.$organization->id)
            ->assertOk()
            ->assertJsonPath('organization.sms_sender_name', null)
            ->assertJsonPath('organization.sms_sender_name_effective', 'MYODEFAULT');

        $this->actingAs($admin)->putJson('/api/super-admin/organizations/'.$organization->id.'/sms-sender-name', ['sender_name' => 'SCHOOLONE'])
            ->assertOk()
            ->assertJsonPath('organization.sms_sender_name', 'SCHOOLONE');
        $this->assertSame('SCHOOLONE', $organization->fresh()->sms_sender_name);

        $this->actingAs($admin)->putJson('/api/super-admin/organizations/'.$organization->id.'/sms-sender-name', ['sender_name' => null])
            ->assertOk()
            ->assertJsonPath('organization.sms_sender_name', null)
            ->assertJsonPath('organization.sms_sender_name_effective', 'MYODEFAULT');
        $this->assertDatabaseHas('organizations', ['id' => $organization->id, 'sms_sender_name' => null]);
    }

    public function test_sender_name_must_be_at_most_eleven_alphanumeric_characters(): void
    {
        $admin = $this->admin();
        $organization = Organization::create(['name' => 'Sender Org', 'type' => 'company']);

        $this->actingAs($admin)->putJson('/api/super-admin/organizations/'.$organization->id.'/sms-sender-name', ['sender_name' => 'Too Long Sender'])
            ->assertUnprocessable();
    }

    public function test_super_admin_can_delete_only_current_day_attendance_for_one_organization(): void
    {
        $admin = $this->admin();
        $organization = Organization::create(['name' => 'Attendance Cleanup', 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $otherOrganization = Organization::create(['name' => 'Other Organization', 'type' => 'company', 'timezone' => 'Asia/Manila']);
        $group = Group::create(['organization_id' => $organization->id, 'name' => 'Group']);
        $person = Person::create(['organization_id' => $organization->id, 'person_number' => '001', 'first_name' => 'Test', 'last_name' => 'Person', 'group_id' => $group->id]);
        Carbon::setTestNow(Carbon::parse('2026-09-18 12:00:00', 'Asia/Manila'));
        $today = AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'check_in', 'occurred_at' => now()->utc()]);
        AttendanceEvent::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'event_type' => 'check_out', 'occurred_at' => now()->subDay()->utc()]);
        $otherGroup = Group::create(['organization_id' => $otherOrganization->id, 'name' => 'Group']);
        $otherPerson = Person::create(['organization_id' => $otherOrganization->id, 'person_number' => '002', 'first_name' => 'Other', 'last_name' => 'Person', 'group_id' => $otherGroup->id]);
        AttendanceEvent::create(['organization_id' => $otherOrganization->id, 'person_id' => $otherPerson->id, 'event_type' => 'check_in', 'occurred_at' => now()->utc()]);
        AttendanceScanIdempotency::create(['organization_id' => $organization->id, 'scan_request_id' => '11111111-1111-4111-8111-111111111111', 'person_id' => $person->id, 'response' => ['success' => true], 'processed_at' => now()->utc()]);
        SmsMessage::create(['organization_id' => $organization->id, 'person_id' => $person->id, 'attendance_event_id' => $today->id, 'notification_type' => 'time_in', 'recipient_phone' => '639171234567', 'message' => 'Test', 'status' => SmsMessageStatus::Pending, 'provider' => SmsProvider::Semaphore, 'attempt_count' => 0]);

        $this->actingAs($admin)->deleteJson('/api/super-admin/organizations/'.$organization->id.'/attendance/current-day')
            ->assertOk()
            ->assertJsonPath('deleted.events', 1)
            ->assertJsonPath('deleted.sms_messages', 1)
            ->assertJsonPath('deleted.scan_idempotencies', 1)
            ->assertJsonPath('local_date', '2026-09-18');

        $this->assertDatabaseMissing('attendance_events', ['id' => $today->id]);
        $this->assertDatabaseCount('sms_messages', 0);
        $this->assertDatabaseCount('attendance_scan_idempotencies', 0);
        $this->assertDatabaseHas('attendance_events', ['organization_id' => $organization->id, 'event_type' => 'check_out']);
        $this->assertDatabaseHas('attendance_events', ['organization_id' => $otherOrganization->id, 'person_id' => $otherPerson->id]);
        $this->assertDatabaseHas('persons', ['id' => $person->id]);
        Carbon::setTestNow();
    }

    public function test_tenants_cannot_create_payments_or_change_entitlements(): void
    {
        $organization = Organization::create(['name' => 'Tenant', 'type' => 'company']);
        $tenant = User::factory()->create(['organization_id' => $organization->id, 'role' => 'admin']);
        $this->actingAs($tenant)->postJson('/api/super-admin/organizations/'.$organization->id.'/payments', [])->assertForbidden();
        $this->actingAs($tenant)->postJson('/api/super-admin/organizations/'.$organization->id.'/sms/enable', [])->assertForbidden();
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_sms_requests_panel_endpoint_only_returns_pending_requests(): void
    {
        $admin = $this->admin();
        $organization = Organization::create(['name' => 'Reviewed Requests', 'type' => 'company']);
        foreach ([OrganizationFeatureRequest::STATUS_PENDING, OrganizationFeatureRequest::STATUS_APPROVED, OrganizationFeatureRequest::STATUS_REJECTED] as $status) {
            OrganizationFeatureRequest::create(['organization_id' => $organization->id, 'feature' => OrganizationFeatureRequest::FEATURE_SMS, 'status' => $status, 'requested_by' => $admin->id, 'requested_at' => now()]);
        }

        $this->actingAs($admin)->getJson('/api/super-admin/sms-requests?status=approved')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.status', OrganizationFeatureRequest::STATUS_PENDING);
    }
}
