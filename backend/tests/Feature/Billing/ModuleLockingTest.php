<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use App\Services\TenantFeatures;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Subscription;
use Spatie\Permission\PermissionRegistrar;
use Tests\TenantTestCase;
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
class ModuleLockingTest extends TenantTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'user@moduletest.com',
        ]);

        $this->seed(RolesAndPermissionsSeeder::class);
        $this->user->givePermissionTo('view-timesheets');
        $this->user->givePermissionTo('view-planning');
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        Sanctum::actingAs($this->user);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createSubscription(array $overrides = []): Subscription
    {
        DB::connection('mysql')
            ->table('subscriptions')
            ->where('tenant_id', $this->tenant->id)
            ->delete();

        $this->tenant->unsetRelation('subscription');

        $subscription = Subscription::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'plan' => 'starter',
            'user_limit' => 2,
            'status' => 'active',
            'addons' => [],
        ], $overrides));

        TenantFeatures::syncFromSubscription($this->tenant);
        return $subscription;
    }

    private function clearSubscription(): void
    {
        DB::connection('mysql')
            ->table('subscriptions')
            ->where('tenant_id', $this->tenant->id)
            ->delete();

        $this->tenant->unsetRelation('subscription');

        TenantFeatures::syncFromSubscription($this->tenant);
    }

    /** @test */
    public function starter_plan_with_1_user_cannot_access_travels()
    {
        $this->createSubscription([
            'plan' => 'starter',
            'user_limit' => 1,
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/travels');

        $response->assertForbidden()
            ->assertJsonPath('module', 'travels');
    }

    /** @test */
    public function starter_plan_with_3_users_can_access_travels()
    {
        $this->createSubscription([
            'plan' => 'starter',
            'user_limit' => 3,
        ]);

        // This should not return 403 (might return 404 or other, but not 403)
        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/travels');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function team_plan_can_access_travels()
    {
        $this->createSubscription([
            'plan' => 'team',
            'user_limit' => 1,
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/travels');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function enterprise_plan_can_access_travels()
    {
        $this->createSubscription([
            'plan' => 'enterprise',
            'user_limit' => 10,
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/travels');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function starter_plan_cannot_access_planning()
    {
        $this->createSubscription([
            'plan' => 'starter',
            'user_limit' => 2,
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/planning/projects');

        $response->assertForbidden()
            ->assertJsonPath('module', 'planning');
    }

    /** @test */
    public function team_plan_without_addon_cannot_access_planning()
    {
        $this->createSubscription([
            'plan' => 'team',
            'user_limit' => 5,
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/planning/projects');

        $response->assertForbidden()
            ->assertJsonPath('module', 'planning');
    }

    /** @test */
    public function team_plan_with_planning_addon_can_access_planning()
    {
        $this->createSubscription([
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['planning'],
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/planning/projects');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function enterprise_plan_with_planning_addon_can_access_planning()
    {
        $this->createSubscription([
            'plan' => 'enterprise',
            'user_limit' => 10,
            'addons' => ['planning'],
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/planning/projects');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function starter_plan_cannot_access_ai()
    {
        $this->tenant->ai_enabled = true;
        $this->tenant->save();

        $this->createSubscription([
            'plan' => 'starter',
            'user_limit' => 2,
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/ai/suggestions/timesheet');

        $response->assertForbidden()
            ->assertJsonPath('module', 'ai');
    }

    /** @test */
    public function team_plan_cannot_access_ai()
    {
        $this->tenant->ai_enabled = true;
        $this->tenant->save();

        $this->createSubscription([
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['ai'], // Even with addon, Team cannot access AI
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/ai/suggestions/timesheet');

        $response->assertForbidden()
            ->assertJsonPath('module', 'ai');
    }

    /** @test */
    public function enterprise_plan_without_ai_addon_cannot_access_ai()
    {
        $this->tenant->ai_enabled = true;
        $this->tenant->save();

        $this->createSubscription([
            'plan' => 'enterprise',
            'user_limit' => 10,
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/ai/suggestions/timesheet');

        $response->assertForbidden()
            ->assertJsonPath('module', 'ai');
    }

    /** @test */
    public function enterprise_plan_with_ai_addon_can_access_ai()
    {
        $this->tenant->ai_enabled = true;
        $this->tenant->save();

        $this->createSubscription([
            'plan' => 'enterprise',
            'user_limit' => 10,
            'addons' => ['ai'],
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/ai/suggestions/timesheet');

        $this->assertNotEquals(403, $response->status());
    }

    /** @test */
    public function no_subscription_blocks_all_premium_modules()
    {
        // No subscription created
        $this->clearSubscription();

        $responseTravels = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/travels');
        $responsePlanning = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/planning/projects');
        $responseAI = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/ai/suggestions/timesheet');

        $responseTravels->assertForbidden();
        $responsePlanning->assertForbidden();
        $responseAI->assertForbidden();
    }
}
