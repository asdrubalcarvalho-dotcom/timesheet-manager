<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Services\Billing\PriceCalculator;
use App\Services\Billing\PlanManager;
use App\Services\TenantFeatures;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

/**
 * BillingTest
 * 
 * Tests for billing system functionality:
 * - Starter plan restrictions
 * - Team/Enterprise access
 * - Addon pricing calculations
 * - User limit triggers
 * - Pennant feature flag scoping
 */
class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected PriceCalculator $priceCalculator;
    protected PlanManager $planManager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->priceCalculator = new PriceCalculator();
        $this->planManager = app(PlanManager::class);

        // Create test tenant
        $this->tenant = Tenant::create([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function starter_plan_costs_35_euros_flat()
    {
        $pricing = $this->priceCalculator->calculate('starter', 1, []);

        $this->assertEquals(35.00, $pricing['base_subtotal']);
        $this->assertEquals(35.00, $pricing['total']);
        $this->assertEquals('EUR', $pricing['currency']);
        $this->assertFalse($pricing['requires_upgrade']);
    }

    /** @test */
    public function starter_plan_with_2_users_does_not_require_upgrade()
    {
        $pricing = $this->priceCalculator->calculate('starter', 2, []);

        $this->assertEquals(35.00, $pricing['total']);
        $this->assertFalse($pricing['requires_upgrade']);
    }

    /** @test */
    public function starter_plan_with_3_users_requires_upgrade()
    {
        $pricing = $this->priceCalculator->calculate('starter', 3, []);

        $this->assertEquals(35.00, $pricing['base_subtotal']);
        $this->assertTrue($pricing['requires_upgrade']);
    }

    /** @test */
    public function team_plan_costs_35_euros_per_user()
    {
        $pricing = $this->priceCalculator->calculate('team', 5, []);

        $this->assertEquals(175.00, $pricing['base_subtotal']); // 5 * 35
        $this->assertEquals(175.00, $pricing['total']);
        $this->assertFalse($pricing['requires_upgrade']);
    }

    /** @test */
    public function enterprise_plan_costs_35_euros_per_user()
    {
        $pricing = $this->priceCalculator->calculate('enterprise', 10, []);

        $this->assertEquals(350.00, $pricing['base_subtotal']); // 10 * 35
        $this->assertEquals(350.00, $pricing['total']);
    }

    /** @test */
    public function planning_addon_adds_18_percent_markup()
    {
        $pricing = $this->priceCalculator->calculate('team', 5, ['planning']);

        $baseSubtotal = 175.00; // 5 * 35
        $expectedPlanning = $baseSubtotal * 0.18; // 31.50
        $expectedTotal = $baseSubtotal + $expectedPlanning; // 206.50

        $this->assertEquals($baseSubtotal, $pricing['base_subtotal']);
        $this->assertEquals($expectedPlanning, $pricing['addons']['planning']);
        $this->assertEquals($expectedTotal, $pricing['total']);
    }

    /** @test */
    public function ai_addon_adds_18_percent_over_base_plus_planning()
    {
        $pricing = $this->priceCalculator->calculate('enterprise', 10, ['planning', 'ai']);

        $baseSubtotal = 350.00; // 10 * 35
        $planningPrice = $baseSubtotal * 0.18; // 63.00
        $aiPrice = ($baseSubtotal + $planningPrice) * 0.18; // 74.34
        $expectedTotal = $baseSubtotal + $planningPrice + $aiPrice; // 487.34

        $this->assertEquals($baseSubtotal, $pricing['base_subtotal']);
        $this->assertEquals($planningPrice, $pricing['addons']['planning']);
        $this->assertEquals($aiPrice, $pricing['addons']['ai']);
        $this->assertEquals($expectedTotal, $pricing['total']);
    }

    /** @test */
    public function starter_plan_does_not_allow_addons()
    {
        $pricing = $this->priceCalculator->calculate('starter', 2, ['planning']);

        // Starter doesn't support addons - should be 0
        $this->assertEquals(0, $pricing['addons']['planning']);
        $this->assertEquals(35.00, $pricing['total']);
    }

    /** @test */
    public function ai_addon_only_available_on_enterprise()
    {
        // Team plan with AI addon - should not apply
        $pricingTeam = $this->priceCalculator->calculate('team', 5, ['ai']);
        $this->assertEquals(0, $pricingTeam['addons']['ai']);

        // Enterprise plan with AI addon - should apply
        $pricingEnterprise = $this->priceCalculator->calculate('enterprise', 5, ['ai']);
        $this->assertGreaterThan(0, $pricingEnterprise['addons']['ai']);
    }

    /** @test */
    public function default_features_initialized_for_new_tenant()
    {
        TenantFeatures::initializeDefaults($this->tenant);

        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::TIMESHEETS));
        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::EXPENSES));
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::TRAVELS));
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::PLANNING));
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::AI));
    }

    /** @test */
    public function starter_subscription_enables_core_features_only()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'starter',
            'user_limit' => 2,
            'status' => 'active',
        ]);

        TenantFeatures::syncFromSubscription($this->tenant);

        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::TIMESHEETS));
        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::EXPENSES));
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::TRAVELS));
    }

    /** @test */
    public function starter_with_more_than_2_users_unlocks_travels()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'starter',
            'user_limit' => 3,
            'status' => 'active',
        ]);

        TenantFeatures::syncFromSubscription($this->tenant);

        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::TRAVELS));
    }

    /** @test */
    public function team_plan_enables_travels_automatically()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 1,
            'status' => 'active',
        ]);

        TenantFeatures::syncFromSubscription($this->tenant);

        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::TRAVELS));
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::PLANNING));
    }

    /** @test */
    public function team_with_planning_addon_enables_planning_feature()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['planning'],
            'status' => 'active',
        ]);

        TenantFeatures::syncFromSubscription($this->tenant);

        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::PLANNING));
    }

    /** @test */
    public function enterprise_with_ai_addon_enables_ai_feature()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 10,
            'addons' => ['ai'],
            'status' => 'active',
        ]);

        TenantFeatures::syncFromSubscription($this->tenant);

        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::AI));
    }

    /** @test */
    public function team_plan_cannot_have_ai_addon()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['ai'],
            'status' => 'active',
        ]);

        TenantFeatures::syncFromSubscription($this->tenant);

        // AI should NOT be enabled on Team plan
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::AI));
    }

    /** @test */
    public function feature_flags_are_tenant_scoped()
    {
        $tenant2 = Tenant::create([
            'name' => 'Second Tenant',
            'slug' => 'second-tenant',
        ]);

        // Enable travels for first tenant
        TenantFeatures::enable($this->tenant, TenantFeatures::TRAVELS);

        // Verify isolation
        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::TRAVELS));
        $this->assertFalse(TenantFeatures::active($tenant2, TenantFeatures::TRAVELS));
    }

    /** @test */
    public function plan_manager_updates_subscription_and_syncs_features()
    {
        $subscription = $this->planManager->updatePlan($this->tenant, 'team', 5);

        $this->assertEquals('team', $subscription->plan);
        $this->assertEquals(5, $subscription->user_limit);
        $this->assertEquals('active', $subscription->status);

        // Verify features synced
        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::TRAVELS));
    }

    /** @test */
    public function toggle_addon_enables_and_disables_feature()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'status' => 'active',
        ]);

        // Enable planning addon
        $result = $this->planManager->toggleAddon($this->tenant, 'planning');

        $this->assertEquals('added', $result['action']);
        $this->assertTrue($result['enabled']);
        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::PLANNING));

        // Disable planning addon
        $result = $this->planManager->toggleAddon($this->tenant, 'planning');

        $this->assertEquals('removed', $result['action']);
        $this->assertFalse($result['enabled']);
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::PLANNING));
    }

    /** @test */
    public function cannot_toggle_incompatible_addon()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'starter',
            'user_limit' => 2,
            'status' => 'active',
        ]);

        $this->expectException(\InvalidArgumentException::class);
        $this->planManager->toggleAddon($this->tenant, 'planning');
    }
}
