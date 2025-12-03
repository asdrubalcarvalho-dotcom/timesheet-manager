<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Services\Billing\PlanManager;
use App\Services\Billing\PriceCalculator;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Carbon\Carbon;

/**
 * BillingSummaryApiTest
 * 
 * Tests for PlanManager::getSubscriptionSummary() method.
 * Validates billing summary structure and pricing calculations.
 * 
 * Uses mocking for PriceCalculator to avoid tenant database requirements.
 * Tests the service layer directly (not HTTP API endpoints).
 */
class BillingSummaryApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected PlanManager $planManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Create test tenant in central database
        $this->tenant = Tenant::create([
            'name' => 'Summary Test Tenant',
            'slug' => 'summary-test-' . time(),
        ]);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /**
     * Mock PriceCalculator::calculateForTenant() to return specific result
     */
    protected function mockPriceCalculator(array $calculatorResult): void
    {
        $mock = Mockery::mock(PriceCalculator::class);
        $mock->shouldReceive('calculateForTenant')
            ->once()
            ->with($this->tenant)
            ->andReturn($calculatorResult);
        
        $this->app->instance(PriceCalculator::class, $mock);
        
        // Re-instantiate PlanManager with mocked calculator
        $this->planManager = $this->app->make(PlanManager::class);
    }

    /** @test */
    public function summary_returns_starter_plan_for_tenant_without_subscription()
    {
        // Mock PriceCalculator to return Starter plan result with 2 users
        $this->mockPriceCalculator([
            'plan' => 'starter',
            'is_trial' => false,
            'user_count' => 2,
            'user_limit' => 2,
            'base_subtotal' => 0.0,
            'addons_subtotal' => 0.0,
            'total' => 0.0,
            'requires_upgrade' => false,
            'addons' => [],
            'features' => [
                'timesheets' => true,
                'expenses' => true,
                'travels' => false,
                'planning' => false,
                'ai' => false,
            ],
        ]);

        // Call PlanManager directly (no API, no tenancy middleware)
        $summary = $this->planManager->getSubscriptionSummary($this->tenant);

        $this->assertEquals('starter', $summary['plan']);
        $this->assertFalse($summary['is_trial']);
        $this->assertEquals(2, $summary['user_count']);
        $this->assertEquals(0.0, $summary['base_subtotal']);
        $this->assertEquals(0.0, $summary['total']);
        $this->assertFalse($summary['requires_upgrade']);
        $this->assertEquals(2, $summary['user_limit']);
        $this->assertTrue($summary['features']['timesheets']);
        $this->assertTrue($summary['features']['expenses']);
        $this->assertFalse($summary['features']['travels']);
        $this->assertFalse($summary['features']['planning']);
        $this->assertFalse($summary['features']['ai']);
        $this->assertNull($summary['subscription']); // No subscription exists
    }

    /** @test */
    public function summary_returns_starter_with_upgrade_required_for_3_users()
    {
        // Mock PriceCalculator to return requires_upgrade=true (3 users exceeds limit)
        $this->mockPriceCalculator([
            'plan' => 'starter',
            'is_trial' => false,
            'user_count' => 3,
            'user_limit' => 2,
            'base_subtotal' => 0.0,
            'addons_subtotal' => 0.0,
            'total' => 0.0,
            'requires_upgrade' => true, // Key difference
            'addons' => [],
            'features' => [
                'timesheets' => true,
                'expenses' => true,
                'travels' => false,
                'planning' => false,
                'ai' => false,
            ],
        ]);

        $summary = $this->planManager->getSubscriptionSummary($this->tenant);

        $this->assertEquals('starter', $summary['plan']);
        $this->assertEquals(3, $summary['user_count']);
        $this->assertEquals(0.0, $summary['total']);
        $this->assertTrue($summary['requires_upgrade']);
    }

    /** @test */
    public function summary_returns_team_plan_with_base_pricing()
    {
        // Create Team subscription
        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => [],
            'status' => 'active',
        ]);

        // Mock PriceCalculator for Team plan with 5 users
        $this->mockPriceCalculator([
            'plan' => 'team',
            'is_trial' => false,
            'user_count' => 5,
            'user_limit' => 5,
            'base_subtotal' => 125.0, // 5 × 25€
            'addons_subtotal' => 0.0,
            'total' => 125.0,
            'requires_upgrade' => false,
            'addons' => [],
            'features' => [
                'timesheets' => true,
                'expenses' => true,
                'travels' => true,
                'planning' => false, // Not included in base Team
                'ai' => false,
            ],
        ]);

        $summary = $this->planManager->getSubscriptionSummary($this->tenant);

        $this->assertEquals('team', $summary['plan']);
        $this->assertEquals(5, $summary['user_count']);
        $this->assertEquals(125.0, $summary['base_subtotal']);
        $this->assertEquals(0.0, $summary['addons_subtotal']);
        $this->assertEquals(125.0, $summary['total']);
        $this->assertFalse($summary['requires_upgrade']);
        $this->assertTrue($summary['features']['travels']);
        $this->assertFalse($summary['features']['planning']);
        $this->assertFalse($summary['features']['ai']);
        $this->assertEquals($subscription->id, $summary['subscription']['id']);
    }

    /** @test */
    public function summary_returns_team_plan_with_planning_addon()
    {
        // Create Team subscription with planning addon
        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['planning'],
            'status' => 'active',
        ]);

        // Mock PriceCalculator for Team + Planning
        $this->mockPriceCalculator([
            'plan' => 'team',
            'is_trial' => false,
            'user_count' => 5,
            'user_limit' => 5,
            'base_subtotal' => 125.0,
            'addons_subtotal' => 25.0, // 5 × 5€
            'total' => 150.0,
            'requires_upgrade' => false,
            'addons' => ['planning'],
            'features' => [
                'timesheets' => true,
                'expenses' => true,
                'travels' => true,
                'planning' => true, // Enabled by addon
                'ai' => false,
            ],
        ]);

        $summary = $this->planManager->getSubscriptionSummary($this->tenant);

        $this->assertEquals('team', $summary['plan']);
        $this->assertEquals(125.0, $summary['base_subtotal']);
        $this->assertEquals(25.0, $summary['addons_subtotal']);
        $this->assertEquals(150.0, $summary['total']);
        $this->assertContains('planning', $summary['addons']);
        $this->assertTrue($summary['features']['planning']);
        $this->assertFalse($summary['features']['ai']);
    }

    /** @test */
    public function summary_returns_team_plan_with_both_addons()
    {
        // Create Team subscription with both addons
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['planning', 'ai'],
            'status' => 'active',
        ]);

        // Mock PriceCalculator for Team + Planning + AI
        $this->mockPriceCalculator([
            'plan' => 'team',
            'is_trial' => false,
            'user_count' => 5,
            'user_limit' => 5,
            'base_subtotal' => 125.0,
            'addons_subtotal' => 50.0, // 5 × (5€ + 5€)
            'total' => 175.0,
            'requires_upgrade' => false,
            'addons' => ['planning', 'ai'],
            'features' => [
                'timesheets' => true,
                'expenses' => true,
                'travels' => true,
                'planning' => true,
                'ai' => true,
            ],
        ]);

        $summary = $this->planManager->getSubscriptionSummary($this->tenant);

        $this->assertEquals('team', $summary['plan']);
        $this->assertEquals(125.0, $summary['base_subtotal']);
        $this->assertEquals(50.0, $summary['addons_subtotal']);
        $this->assertEquals(175.0, $summary['total']);
        $this->assertContains('planning', $summary['addons']);
        $this->assertContains('ai', $summary['addons']);
        $this->assertTrue($summary['features']['planning']);
        $this->assertTrue($summary['features']['ai']);
    }

    /** @test */
    public function summary_returns_enterprise_plan()
    {
        // Create Enterprise subscription
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 10,
            'addons' => [],
            'status' => 'active',
        ]);

        // Mock PriceCalculator for Enterprise plan
        $this->mockPriceCalculator([
            'plan' => 'enterprise',
            'is_trial' => false,
            'user_count' => 10,
            'user_limit' => 10,
            'base_subtotal' => 350.0, // 10 × 35€
            'addons_subtotal' => 0.0,
            'total' => 350.0,
            'requires_upgrade' => false,
            'addons' => [],
            'features' => [
                'timesheets' => true,
                'expenses' => true,
                'travels' => true,
                'planning' => true, // Included in Enterprise
                'ai' => true,        // Included in Enterprise
            ],
        ]);

        $summary = $this->planManager->getSubscriptionSummary($this->tenant);

        $this->assertEquals('enterprise', $summary['plan']);
        $this->assertFalse($summary['is_trial']);
        $this->assertEquals(10, $summary['user_count']);
        $this->assertEquals(350.0, $summary['base_subtotal']);
        $this->assertEquals(350.0, $summary['total']);
        $this->assertTrue($summary['features']['planning']);
        $this->assertTrue($summary['features']['ai']);
    }

    /** @test */
    public function summary_returns_active_trial_enterprise()
    {
        // Create trial Enterprise subscription
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 999999,
            'addons' => [],
            'status' => 'active',
            'is_trial' => true,
            'trial_ends_at' => Carbon::now()->addDays(10),
        ]);

        // Mock PriceCalculator for Trial Enterprise (free, unlimited users)
        $this->mockPriceCalculator([
            'plan' => 'trial_enterprise',
            'is_trial' => true,
            'user_count' => 15,
            'user_limit' => 999999,
            'base_subtotal' => 0.0,
            'addons_subtotal' => 0.0,
            'total' => 0.0, // Free during trial
            'requires_upgrade' => false,
            'addons' => [],
            'features' => [
                'timesheets' => true,
                'expenses' => true,
                'travels' => true,
                'planning' => true,
                'ai' => true,
            ],
        ]);

        $summary = $this->planManager->getSubscriptionSummary($this->tenant);

        $this->assertEquals('trial_enterprise', $summary['plan']);
        $this->assertTrue($summary['is_trial']);
        $this->assertEquals(15, $summary['user_count']);
        $this->assertEquals(0.0, $summary['total']); // Free
        $this->assertFalse($summary['requires_upgrade']);
        $this->assertTrue($summary['features']['planning']);
        $this->assertTrue($summary['features']['ai']);
        // trial_ends_at comes from subscription metadata added by PlanManager
        $this->assertArrayHasKey('subscription', $summary);
    }
}
