<?php

namespace Tests\Feature;

use App\Models\BaseSubscription;
use App\Models\Bill;
use App\Models\FeatureEntitlement;
use App\Models\Organization;
use App\Models\SmsPricingAgreement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * The super admin Organizations list filters by Scan plan status, SMS status and industry, together with the search box.
 */
class SuperAdminOrganizationFiltersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 02:00:00');
        Storage::fake('local');
    }

    private function school(string $name, string $plan, string $type = 'school', ?string $sms = null): User
    {
        $organization = Organization::create(['name' => $name, 'type' => $type, 'timezone' => 'Asia/Manila']);
        $owner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
        match ($plan) {
            'trialing' => BaseSubscription::create(['organization_id' => $organization->id, 'trial_starts_at' => now()->subDays(5), 'trial_ends_at' => now()->addDays(25), 'status' => 'trialing']),
            'active' => BaseSubscription::create(['organization_id' => $organization->id, 'subscription_starts_at' => now()->subDays(5), 'subscription_expires_at' => now()->addDays(25), 'status' => 'active']),
            'expired' => BaseSubscription::create(['organization_id' => $organization->id, 'trial_ends_at' => now()->subDay(), 'status' => 'expired']),
        };
        match ($sms) {
            'on' => FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => FeatureEntitlement::SMS, 'enabled' => true, 'starts_at' => now()->subDays(5), 'expires_at' => now()->addDays(25)]),
            'scheduled' => FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => FeatureEntitlement::SMS, 'enabled' => true, 'starts_at' => now()->addDays(25), 'expires_at' => now()->addDays(55)]),
            default => null,
        };
        SmsPricingAgreement::create(['organization_id' => $organization->id, 'sms_monthly_price_minor' => 49900, 'person_count_snapshot' => 50, 'effective_from' => '2026-11-01', 'status' => SmsPricingAgreement::STATUS_ACTIVE, 'created_by' => $owner->id, 'agreed_at' => now()]);

        return $owner;
    }

    /** @return list<string> */
    private function names(array $query): array
    {
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        $response = $this->actingAs($admin)->getJson('/api/super-admin/organizations?'.http_build_query($query))->assertOk();
        $this->assertSame(count($response->json('data')), $response->json('total'));

        return collect($response->json('data'))->pluck('name')->sort()->values()->all();
    }

    public function test_plan_sms_and_industry_filters_combine_with_search(): void
    {
        $this->school('Trial Academy', 'trialing');
        $this->school('Active Academy', 'active', sms: 'on');
        $this->school('Expired Academy', 'expired');
        $this->school('Scheduled Academy', 'active', sms: 'scheduled');
        $this->school('Iron Gym', 'expired', 'fitness');
        $this->school('Acme Corp', 'active', 'company');

        $this->assertSame(['Trial Academy'], $this->names(['plan' => 'trialing']));
        $this->assertSame(['Expired Academy', 'Iron Gym'], $this->names(['plan' => 'expired']));
        $this->assertSame(['Acme Corp', 'Active Academy', 'Scheduled Academy'], $this->names(['plan' => 'active']));
        $this->assertSame(['Active Academy'], $this->names(['sms' => 'on']));
        $this->assertSame(['Scheduled Academy'], $this->names(['sms' => 'scheduled']));
        $this->assertSame(['Acme Corp', 'Expired Academy', 'Iron Gym', 'Trial Academy'], $this->names(['sms' => 'off']));
        $this->assertSame(['Iron Gym'], $this->names(['type' => 'fitness']));
        $this->assertSame(['Acme Corp'], $this->names(['type' => 'other']));
        $this->assertSame(['Expired Academy'], $this->names(['plan' => 'expired', 'type' => 'school']));
        $this->assertSame(['Acme Corp'], $this->names(['plan' => 'active', 'sms' => 'off']));
        $this->assertSame(['Iron Gym'], $this->names(['plan' => 'expired', 'search' => 'gym']));
        $this->assertCount(6, $this->names([]));
    }

    public function test_payment_under_review_filter_lists_schools_with_a_submitted_payment(): void
    {
        $waiting = $this->school('Waiting School', 'expired');
        $this->school('Quiet School', 'expired');
        $bill = $this->actingAs($waiting)->getJson('/api/billing?type='.Bill::TYPE_BASE)->assertOk()->json('current_bill');
        $this->actingAs($waiting)->post('/api/billing/payments', [
            'amount' => number_format($bill['total_minor'] / 100, 2, '.', ''), 'bill_id' => $bill['id'], 'reference_number' => 'REVIEW-1', 'paid_at' => '2026-11-10',
        ], ['Accept' => 'application/json'])->assertCreated();

        $this->assertSame(['Waiting School'], $this->names(['plan' => 'under_review']));
    }

    public function test_dashboard_cards_count_expiring_and_expired_organizations_and_open_the_matching_list(): void
    {
        $this->school('Ends In 3 Days', 'active');
        BaseSubscription::query()->whereHas('organization', fn ($q) => $q->where('name', 'Ends In 3 Days'))->update(['subscription_expires_at' => now()->addDays(3)]);
        $this->school('Trial Ends Soon', 'trialing');
        BaseSubscription::query()->whereHas('organization', fn ($q) => $q->where('name', 'Trial Ends Soon'))->update(['trial_ends_at' => now()->addDays(5)]);
        $this->school('Sms Ends Soon', 'active', sms: 'on');
        FeatureEntitlement::query()->whereHas('organization', fn ($q) => $q->where('name', 'Sms Ends Soon'))->update(['expires_at' => now()->addDays(6)]);
        $this->school('Plenty Of Time', 'active', sms: 'on');
        // Expired Base + SMS: Scan expired and the school had SMS before (its SMS ran out too).
        $hadSms = $this->school('Lapsed With Sms', 'expired');
        FeatureEntitlement::create(['organization_id' => $hadSms->organization_id, 'feature' => FeatureEntitlement::SMS, 'enabled' => true, 'starts_at' => now()->subDays(60), 'expires_at' => now()->subDays(30)]);
        // Expired Base only: Scan expired and SMS was never turned on.
        $this->school('Lapsed Base Only', 'expired');
        $this->school('Lapsed Gym', 'expired', 'fitness');

        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($admin)->getJson('/api/super-admin/dashboard')->assertOk()
            ->assertJsonPath('total_organizations', 7)
            ->assertJsonPath('expiring_soon', 3)
            ->assertJsonPath('expired_with_sms', 1)
            ->assertJsonPath('expired_base_only', 2);

        $this->assertSame(['Ends In 3 Days', 'Sms Ends Soon', 'Trial Ends Soon'], $this->names(['plan' => 'expiring_soon']));
        $this->assertSame(['Lapsed With Sms'], $this->names(['plan' => 'expired_with_sms']));
        $this->assertSame(['Lapsed Base Only', 'Lapsed Gym'], $this->names(['plan' => 'expired_base_only']));
    }

    public function test_dashboard_counts_payments_still_waiting_for_review(): void
    {
        $owner = $this->school('Waiting', 'expired');
        $admin = User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
        $this->actingAs($admin)->getJson('/api/super-admin/dashboard')->assertOk()->assertJsonPath('pending_payment_reviews', 0);

        $bill = $this->actingAs($owner)->getJson('/api/billing?type='.Bill::TYPE_BASE)->assertOk()->json('current_bill');
        $paymentId = $this->actingAs($owner)->post('/api/billing/payments', [
            'amount' => number_format($bill['total_minor'] / 100, 2, '.', ''), 'bill_id' => $bill['id'], 'reference_number' => 'COUNT-1', 'paid_at' => '2026-11-10',
        ], ['Accept' => 'application/json'])->assertCreated()->json('payment.id');
        $this->actingAs($admin)->getJson('/api/super-admin/dashboard')->assertOk()->assertJsonPath('pending_payment_reviews', 1);

        $this->actingAs($admin)->postJson('/api/super-admin/billing/payments/'.$paymentId.'/activate')->assertOk();
        $this->actingAs($admin)->getJson('/api/super-admin/dashboard')->assertOk()->assertJsonPath('pending_payment_reviews', 0);
    }
}
