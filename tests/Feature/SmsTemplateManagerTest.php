<?php

namespace Tests\Feature;

use App\Models\FeatureEntitlement;
use App\Models\Organization;
use App\Models\SmsTemplate;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SmsTemplateManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_sms_templates_require_active_entitlement_and_are_seeded_per_organization(): void
    {
        [$user, $organization] = $this->tenant('owner');

        $this->actingAs($user)->getJson('/api/organization/sms/templates')->assertForbidden();
        FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => 'sms', 'enabled' => true]);

        $this->actingAs($user)->getJson('/api/organization/sms/templates')->assertOk()->assertJsonCount(4, 'templates');
        $this->assertDatabaseCount('sms_templates', 4);
        $this->assertDatabaseHas('sms_templates', ['organization_id' => $organization->id, 'template_key' => SmsTemplate::TIME_IN]);
    }

    public function test_owner_and_admin_can_update_only_their_organization_templates(): void
    {
        [$owner, $organization] = $this->tenant('owner');
        FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => 'sms', 'enabled' => true]);
        $this->actingAs($owner)->getJson('/api/organization/sms/templates')->assertOk();

        $this->actingAs($owner)->putJson('/api/organization/sms/templates/time_in', ['body' => 'Hello {{name}} at {{time}}.', 'enabled' => false])
            ->assertOk()->assertJsonPath('template.body', 'Hello {{name}} at {{time}}.')->assertJsonPath('template.enabled', false);
        $this->assertDatabaseHas('sms_templates', ['organization_id' => $organization->id, 'template_key' => 'time_in', 'enabled' => 0]);

        $admin = User::factory()->create(['organization_id' => $organization->id, 'role' => 'admin']);
        $this->actingAs($admin)->putJson('/api/organization/sms/templates/late', ['body' => 'Late: {{name}}'])->assertOk();
        $scanner = User::factory()->create(['organization_id' => $organization->id, 'role' => 'scanner']);
        $this->actingAs($scanner)->putJson('/api/organization/sms/templates/late', ['body' => 'No'])->assertForbidden();
    }

    public function test_expired_access_cannot_read_or_update_templates(): void
    {
        [$user, $organization] = $this->tenant('owner');
        FeatureEntitlement::create(['organization_id' => $organization->id, 'feature' => 'sms', 'enabled' => true, 'expires_at' => '2020-01-01']);

        $this->actingAs($user)->getJson('/api/organization/sms/templates')->assertForbidden();
        $this->assertDatabaseCount('sms_templates', 0);
    }

    private function tenant(string $role): array
    {
        $organization = Organization::create(['name' => 'SMS Org '.uniqid(), 'type' => 'school']);

        return [User::factory()->create(['organization_id' => $organization->id, 'role' => $role]), $organization];
    }
}
