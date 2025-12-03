<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\User;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

/**
 * ModuleLockingTest
 * 
 * Tests for EnsureModuleEnabled middleware:
 * - Travels module access (Team/Enterprise OR Starter >2 users)
 * - Planning module access (Team/Enterprise + addon)
 * - AI module access (Enterprise + addon)
 * - 403 responses with helpful upgrade messages
 */
class ModuleLockingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'Module Lock Test',
            'slug' => 'module-lock-test',
        ]);

        $this->user = User::factory()->create([
            'email' => 'user@moduletest.com',
        ]);

        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function starter_plan_with_1_user_cannot_access_travels()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'starter',
            'user_limit' => 1,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/travels');

        $response->assertForbidden()
            ->assertJson([
                'message' => 'Travels module not available',
                'requires_plan' => 'Team or Enterprise',
            ]);
    }

    /** @test */
    public function starter_plan_with_3_users_can_access_travels()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'starter',
            'user_limit' => 3,
            'status' => 'active',
        ]);

        // This should not return 403 (might return 404 or other, but not 403)
        $response = $this->getJson('/api/travels');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function team_plan_can_access_travels()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 1,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/travels');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function enterprise_plan_can_access_travels()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 10,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/travels');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function starter_plan_cannot_access_planning()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'starter',
            'user_limit' => 2,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/planning');

        $response->assertForbidden()
            ->assertJson([
                'message' => 'Planning module not available',
                'requires_plan' => 'Team or Enterprise',
                'requires_addon' => 'Planning',
            ]);
    }

    /** @test */
    public function team_plan_without_addon_cannot_access_planning()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/planning');

        $response->assertForbidden()
            ->assertJsonFragment([
                'requires_addon' => 'Planning',
            ]);
    }

    /** @test */
    public function team_plan_with_planning_addon_can_access_planning()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['planning'],
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/planning');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function enterprise_plan_with_planning_addon_can_access_planning()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 10,
            'addons' => ['planning'],
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/planning');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function starter_plan_cannot_access_ai()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'starter',
            'user_limit' => 2,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/ai/insights');

        $response->assertForbidden()
            ->assertJson([
                'message' => 'AI module not available',
                'requires_plan' => 'Enterprise',
                'requires_addon' => 'AI',
            ]);
    }

    /** @test */
    public function team_plan_cannot_access_ai()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['ai'], // Even with addon, Team cannot access AI
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/ai/insights');

        $response->assertForbidden()
            ->assertJsonFragment([
                'requires_plan' => 'Enterprise',
            ]);
    }

    /** @test */
    public function enterprise_plan_without_ai_addon_cannot_access_ai()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 10,
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/ai/insights');

        $response->assertForbidden()
            ->assertJsonFragment([
                'requires_addon' => 'AI',
            ]);
    }

    /** @test */
    public function enterprise_plan_with_ai_addon_can_access_ai()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 10,
            'addons' => ['ai'],
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/ai/insights');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function no_subscription_blocks_all_premium_modules()
    {
        // No subscription created

        $responseTravels = $this->getJson('/api/travels');
        $responsePlanning = $this->getJson('/api/planning');
        $responseAI = $this->getJson('/api/ai/insights');

        $responseTravels->assertForbidden();
        $responsePlanning->assertForbidden();
        $responseAI->assertForbidden();
    }
}
