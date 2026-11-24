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

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request')->assertSuccessful();
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

    public function test_a_school_with_only_an_estimate_can_still_ask_for_a_custom_price_but_not_once_admin_set_one(): void
    {
        $owner = $this->expiredSchool();
        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 30, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertSuccessful();
        $this->assertSame(SmsPricingAgreement::SOURCE_ESTIMATE, SmsPricingAgreement::query()->where('organization_id', $owner->organization_id)->value('source'));

        $this->actingAs($owner)->postJson('/api/organization/sms/access/request')->assertSuccessful();

        $priced = $this->expiredSchool();
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($admin)->putJson('/api/super-admin/organizations/'.$priced->organization_id.'/sms-pricing', ['sms_monthly_price' => '650'])->assertOk();
        $this->actingAs($priced)->postJson('/api/organization/sms/access/request')->assertStatus(409);
    }
}
