<?php

namespace Tests\Feature;

use App\Models\BaseSubscription;
use App\Models\Bill;
use App\Models\Organization;
use App\Models\SmsPricingAgreement;
use App\Models\User;
use App\Services\BaseSubscriptionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsPriceEstimateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->travelTo('2026-11-10 02:00:00');
    }

    private function trialOwner(): User
    {
        $organization = Organization::create(['name' => 'Estimate School '.uniqid(), 'type' => 'school', 'timezone' => 'Asia/Manila']);
        BaseSubscription::create(['organization_id' => $organization->id, 'trial_starts_at' => now()->subDays(10), 'trial_ends_at' => now()->addDays(10), 'status' => 'trialing']);

        return User::factory()->create(['organization_id' => $organization->id, 'role' => 'owner']);
    }

    private function superAdmin(): User
    {
        return User::factory()->create(['organization_id' => null, 'role' => User::ROLE_SUPER_ADMIN]);
    }

    /**
     * @return array<string, mixed>
     */
    private function registration(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Owner', 'organization_name' => 'New School', 'organization_type' => 'school',
            'username' => 'new.owner', 'email' => 'owner@example.test', 'contact_number' => '09171234567',
            'password' => 'password', 'password_confirmation' => 'password',
        ], $overrides);
    }

    public function test_registration_without_sms_answers_has_no_sms_price(): void
    {
        $this->postJson('/api/auth/register', $this->registration())->assertCreated();

        $organization = Organization::where('name', 'New School')->firstOrFail();
        $this->assertNull($organization->sms_estimate_people);
        $this->assertSame(0, $organization->smsPricingAgreements()->count());
    }

    public function test_registration_with_sms_answers_creates_an_estimated_price(): void
    {
        $this->postJson('/api/auth/register', $this->registration([
            'sms_estimate_people' => 350, 'sms_estimate_texts' => ['time_in', 'time_out'], 'sms_estimate_days' => 'weekdays',
        ]))->assertCreated();

        $organization = Organization::where('name', 'New School')->firstOrFail();
        $agreement = $organization->smsPricingAgreements()->firstOrFail();
        $this->assertSame(770000, $agreement->sms_monthly_price_minor);
        $this->assertSame(SmsPricingAgreement::SOURCE_ESTIMATE, $agreement->source);
        $this->assertSame(SmsPricingAgreement::STATUS_ACTIVE, $agreement->status);
    }

    public function test_registration_rejects_invalid_sms_answers(): void
    {
        $this->postJson('/api/auth/register', $this->registration(['sms_estimate_people' => 0, 'sms_estimate_texts' => ['weekly'], 'sms_estimate_days' => 'sundays']))
            ->assertStatus(422)->assertJsonValidationErrors(['sms_estimate_people', 'sms_estimate_texts.0', 'sms_estimate_days']);
    }

    public function test_trial_school_without_a_price_sees_empty_answers_and_base_plus_sms_unpriced(): void
    {
        $owner = $this->trialOwner();

        $this->actingAs($owner)->getJson('/api/billing')->assertOk()
            ->assertJsonPath('available_types.package', false)
            ->assertJsonPath('sms_estimate.people', null)
            ->assertJsonPath('sms_estimate.amount_minor', null)
            ->assertJsonPath('sms_estimate.price_source', null)
            ->assertJsonPath('sms_estimate.settings.price_per_text_minor', 50)
            ->assertJsonPath('sms_estimate.settings.round_to_minor', 5000);
    }

    public function test_saving_answers_on_billing_unlocks_base_plus_sms_at_the_estimated_price(): void
    {
        $owner = $this->trialOwner();

        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 350, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertOk()
            ->assertJsonPath('applied', true)
            ->assertJsonPath('amount_minor', 770000);

        $bill = $this->actingAs($owner)->getJson('/api/billing?type=package')->assertOk()
            ->assertJsonPath('available_types.package', true)
            ->assertJsonPath('sms_estimate.people', 350)
            ->assertJsonPath('sms_estimate.amount_minor', 770000)
            ->assertJsonPath('sms_estimate.price_source', 'estimate')
            ->json('current_bill');
        $this->assertSame(BaseSubscriptionService::MONTHLY_PRICE_MINOR + 770000, $bill['total_minor']);
        $this->assertSame('2026-11-20', substr($bill['billing_period_start'], 0, 10));
    }

    public function test_changing_answers_on_the_same_day_keeps_one_estimate_and_a_new_day_keeps_history(): void
    {
        $owner = $this->trialOwner();
        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 1, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertOk();
        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 400, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertOk()
            ->assertJsonPath('amount_minor', 880000);

        $agreements = $owner->organization->smsPricingAgreements();
        $this->assertSame(1, $agreements->count());
        $this->assertSame(880000, $agreements->value('sms_monthly_price_minor'));
        $this->assertSame("School's answers: 400 people · Time In, Time Out · Weekdays", $agreements->value('notes'));

        $this->travelTo('2026-11-12 02:00:00');
        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 300, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertOk();
        $history = $owner->organization->smsPricingAgreements()->orderBy('id')->get();
        $this->assertSame([SmsPricingAgreement::STATUS_SUPERSEDED, SmsPricingAgreement::STATUS_ACTIVE], $history->pluck('status')->all());
        $this->assertSame('2026-11-11', $history[0]->effective_until->toDateString());

        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 300, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertOk();
        $this->assertSame(2, $owner->organization->smsPricingAgreements()->count());
    }

    public function test_admin_price_set_on_the_estimates_first_day_marks_the_estimate_never_billed(): void
    {
        $owner = $this->trialOwner();
        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 50, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertOk();

        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '1200'])->assertOk()
            ->assertJsonPath('history.0.status', SmsPricingAgreement::STATUS_ACTIVE)
            ->assertJsonPath('history.1.status', SmsPricingAgreement::STATUS_CANCELLED)
            ->assertJsonPath('history.1.effective_until', null);
    }

    public function test_admin_price_stays_in_control_over_the_estimate(): void
    {
        $owner = $this->trialOwner();
        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 350, 'texts' => ['time_in', 'time_out'], 'days' => 'weekdays'])->assertOk();

        $this->actingAs($this->superAdmin())->putJson('/api/super-admin/organizations/'.$owner->organization_id.'/sms-pricing', ['sms_monthly_price' => '5000'])->assertOk()
            ->assertJsonPath('agreement.source', SmsPricingAgreement::SOURCE_ADMIN);

        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 50, 'texts' => ['time_out'], 'days' => 'weekdays'])->assertOk()
            ->assertJsonPath('applied', false)
            ->assertJsonPath('message', 'Saved. Your SMS price is set by MYO Attendance.');

        $this->assertSame(500000, $owner->organization->smsPricingAgreements()->where('status', 'active')->value('sms_monthly_price_minor'));
        $this->assertSame(50, $owner->organization->fresh()->sms_estimate_people);
        $this->actingAs($owner)->getJson('/api/billing')->assertJsonPath('sms_estimate.price_source', 'admin');
    }

    public function test_admin_review_shows_the_schools_numbers_for_an_estimated_price(): void
    {
        $owner = $this->trialOwner();
        $this->actingAs($owner)->postJson('/api/billing/sms-estimate', ['people' => 350, 'texts' => ['time_in', 'time_out', 'late'], 'days' => 'mon_sat'])->assertOk();
        $bill = $this->actingAs($owner)->getJson('/api/billing?type=package')->json('current_bill');
        $paymentId = $this->actingAs($owner)->postJson('/api/billing/payments', [
            'amount' => number_format($bill['total_minor'] / 100, 2, '.', ''), 'bill_id' => $bill['id'], 'reference_number' => 'EST-1', 'paid_at' => '2026-11-10',
        ])->assertCreated()->json('payment.id');

        $admin = $this->superAdmin();
        $this->actingAs($admin)->getJson('/api/super-admin/billing/payments/'.$paymentId)->assertOk()
            ->assertJsonPath('sms_price_source', 'estimate')
            ->assertJsonPath('sms_estimate.people', 350)->assertJsonPath('sms_estimate.texts_per_day', 2)->assertJsonPath('sms_estimate.days', 'mon_sat');
        $this->actingAs($admin)->getJson('/api/super-admin/organizations/'.$owner->organization_id)->assertOk()
            ->assertJsonPath('organization.sms_pricing.source', 'estimate')
            ->assertJsonPath('organization.sms_estimate.people', 350);
        $this->assertSame(Bill::TYPE_PACKAGE, Bill::findOrFail($bill['id'])->bill_type);
    }

    public function test_billing_estimate_requires_complete_answers(): void
    {
        $this->actingAs($this->trialOwner())->postJson('/api/billing/sms-estimate', ['people' => 0, 'texts' => [], 'days' => 'yearly'])
            ->assertStatus(422)->assertJsonValidationErrors(['people', 'texts', 'days']);
    }
}
