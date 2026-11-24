<?php

namespace Tests\Feature;

use App\Models\BaseSubscription;
use App\Models\Bill;
use App\Models\Organization;
use App\Models\OrganizationFeatureRequest;
use App\Models\SmsPricingAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Any school without a price set by MYO Attendance can ask for a custom SMS price from Billing, even when its Base
 * has expired or it already has an estimate. Super admin sets the price, the school pays Base + SMS, Activate turns SMS on.
 */
class SmsCustomPriceRequestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 02:00:00');
        Storage::fake('local');
        Http::fake();
    }

    private function expiredSchool(): User
    {
        $organization = Organization::create(['name' => 'No Price School', 'type' => 'school', 'timezone' => 'Asia/Manila']);
        BaseSubscription::create(['organization_id' => $organization->id, 'trial_ends_at' => now()->subDay(), 'status' => 'expired']);

        return User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
    }

    public function test_an_expired_school_without_a_price_can_request_one_and_the_whole_flow_turns_sms_on(): void
    {
        $owner = $this->expiredSchool();
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertSuccessful();
        $this->assertSame(1, OrganizationFeatureRequest::query()->where('organization_id', $owner->organization_id)->where('status', 'pending')->count());
        $this->actingAs($admin)->getJson('/api/super-admin/dashboard')->assertOk()->assertJsonPath('sms_price_requests', 1);
        $this->actingAs($owner)->getJson('/api/billing')->assertJsonPath('available_types.package', false);

        $this->actingAs($admin)->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '650'])->assertOk();
        $request = OrganizationFeatureRequest::query()->where('organization_id', $owner->organization_id)->firstOrFail();
        $this->actingAs($admin)->postJson('/api/super-admin/sms-requests/'.$request->id.'/send-quote')->assertOk();

        $bill = $this->actingAs($owner)->getJson('/api/billing?type='.Bill::TYPE_PACKAGE)->assertOk()
            ->assertJsonPath('available_types.package', true)->json('current_bill');
        $this->assertSame(299900 + 65000, $bill['total_minor']);
        $paymentId = $this->actingAs($owner)->post('/api/billing/payments', [
            'amount' => number_format($bill['total_minor'] / 100, 2, '.', ''), 'bill_id' => $bill['id'], 'reference_number' => 'CUSTOM-1', 'paid_at' => '2026-11-10',
        ], ['Accept' => 'application/json'])->assertCreated()->json('payment.id');

        $this->actingAs($admin)->postJson('/api/super-admin/billing/payments/'.$paymentId.'/activate')->assertOk();
        $this->actingAs($owner)->getJson('/api/billing')->assertOk()->assertJsonPath('current_plan.sms.state', 'on');
    }

    public function test_a_school_can_ask_for_a_custom_price_over_an_estimate_or_a_new_one_over_an_admin_price(): void
    {
        $owner = $this->expiredSchool();
        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 30, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertSuccessful();
        $this->assertSame(SmsPricingAgreement::SOURCE_ESTIMATE, SmsPricingAgreement::query()->where('organization_id', $owner->organization_id)->value('source'));

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertSuccessful();

        $priced = $this->expiredSchool();
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($admin)->putJson('/api/super-admin/organizations/'.$priced->organization_id.'/sms-pricing', ['sms_monthly_price' => '650'])->assertOk();
        $this->actingAs($priced)->postJson('/api/organization/sms/access/request', self::SMS_ANSWERS)->assertSuccessful();
        $this->assertSame(65000, SmsPricingAgreement::query()->where('organization_id', $priced->organization_id)->where('status', SmsPricingAgreement::STATUS_ACTIVE)->value('sms_monthly_price_minor'));
    }

    public function test_a_custom_price_request_needs_people_texts_and_days(): void
    {
        $owner = $this->expiredSchool();

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request')->assertUnprocessable()->assertJsonValidationErrors('people');
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['people' => 30])->assertUnprocessable()->assertJsonValidationErrors(['texts', 'days']);
        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['people' => 30, 'texts' => [], 'days' => 'weekdays'])->assertUnprocessable();

        $this->assertSame(0, OrganizationFeatureRequest::query()->where('organization_id', $owner->organization_id)->count());
    }

    public function test_the_answers_reach_super_admin_with_an_estimate_but_do_not_set_a_price(): void
    {
        config(['services.sms_estimate.price_per_text' => 0.5, 'services.sms_estimate.round_to' => 50]);
        $owner = $this->expiredSchool();
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['people' => 350, 'texts' => ['late', 'time_out'], 'days' => 'mon_sat'])->assertCreated();

        $this->assertSame(0, SmsPricingAgreement::query()->where('organization_id', $owner->organization_id)->count());
        $this->actingAs($owner)->getJson('/api/billing')->assertJsonPath('available_types.package', false);
        // 350 people × 2 texts × 26 days × PHP 0.50 = PHP 9,100.
        $this->actingAs($admin)->getJson('/api/super-admin/organizations/'.$owner->organization_id)->assertOk()
            ->assertJsonPath('organization.sms_estimate.people', 350)
            ->assertJsonPath('organization.sms_estimate.texts', ['time_out', 'late'])
            ->assertJsonPath('organization.sms_estimate.texts_per_day', 2)
            ->assertJsonPath('organization.sms_estimate.days', 'mon_sat')
            ->assertJsonPath('organization.sms_estimate.amount_minor', 910000);
    }

    public function test_a_gym_asks_for_its_price_with_its_own_wording_and_super_admin_sees_the_answers(): void
    {
        $gym = Organization::create(['name' => 'Iron Gym', 'type' => 'fitness', 'timezone' => 'Asia/Manila']);
        BaseSubscription::create(['organization_id' => $gym->id, 'trial_ends_at' => now()->subDay(), 'status' => 'expired']);
        $owner = User::factory()->create(['organization_id' => $gym->id, 'role' => 'owner']);
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);

        $this->actingAs($owner)->get('/billing')->assertOk()
            ->assertSee('organizationType: "fitness"', false)
            ->assertSee('"person_plural":"Members"', false);

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request', ['people' => 200, 'texts' => ['time_in'], 'days' => 'every_day'])->assertCreated();
        $this->actingAs($admin)->getJson('/api/super-admin/organizations/'.$gym->id)->assertOk()
            ->assertJsonPath('organization.sms_estimate.people', 200)
            ->assertJsonPath('organization.sms_estimate.days', 'every_day');
    }
}
