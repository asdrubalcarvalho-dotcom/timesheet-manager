<?php

namespace Tests\Unit\Services\Billing;

use App\Services\Billing\PriceCalculator;
use App\Models\Subscription;
use Tests\TenantTestCase;

/**
 * PriceCalculatorTest
 * 
 * Tests the core billing calculation logic to ensure:
 * - Each add-on is calculated as base_price × addon_percentage
 * - Add-ons are NEVER compounded (no base + addon1 used for addon2)
 * - Total = base + sum(all addons)
 * 
 * @group billing
 * @group price-calculation
 */
class PriceCalculatorTest extends TenantTestCase
{
    protected PriceCalculator $calculator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->calculator = new PriceCalculator();
    }

    /**
     * Test Case A: Team plan, 2 users, NO add-ons
     * 
     * Expected:
     * - base = 44 × 2 = 88.00
     * - addons_total = 0
     * - total = 88.00
     */
    public function test_team_plan_no_addons()
    {
        $tenant = $this->tenant;

        Subscription::query()->where('tenant_id', $tenant->id)->delete();
        
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan' => 'team',
            'user_limit' => 2,
            'addons' => [], // No add-ons enabled
            'status' => 'active',
            'is_trial' => false,
        ]);
        
        $tenant->setRelation('subscription', $subscription);

        // Mock active user count
        $this->mockActiveUserCount($tenant, 2);

        $result = $this->calculator->calculateForTenant($tenant);

        $this->assertEquals('team', $result['plan']);
        $this->assertEquals(2, $result['user_count']);
        $this->assertEquals(88.00, $result['base_subtotal']);
        $this->assertEquals(0.00, $result['addons']['planning']);
        $this->assertEquals(0.00, $result['addons']['ai']);
        $this->assertEquals(88.00, $result['total']);
        $this->assertFalse($result['is_trial']);
    }

    /**
     * Test Case B: Team plan, 2 users, ONLY Planning enabled
     * 
     * Expected:
     * - base = 88.00
     * - planning = 88.00 × 0.18 = 15.84
     * - ai = 0
     * - addons_total = 15.84
     * - total = 103.84
     */
    public function test_team_plan_only_planning_addon()
    {
        $tenant = $this->tenant;

        Subscription::query()->where('tenant_id', $tenant->id)->delete();
        
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan' => 'team',
            'user_limit' => 2,
            'addons' => ['planning'], // Only planning enabled
            'status' => 'active',
            'is_trial' => false,
        ]);
        
        $tenant->setRelation('subscription', $subscription);
        $this->mockActiveUserCount($tenant, 2);

        $result = $this->calculator->calculateForTenant($tenant);

        $this->assertEquals(88.00, $result['base_subtotal']);
        $this->assertEquals(15.84, $result['addons']['planning']);
        $this->assertEquals(0.00, $result['addons']['ai']);
        $this->assertEquals(103.84, $result['total']);
        
        // CRITICAL: Planning must be 18% of base ONLY
        $this->assertEquals(
            round(88.00 * 0.18, 2),
            $result['addons']['planning'],
            'Planning add-on must be exactly 18% of base price'
        );
    }

    /**
     * Test Case C: Team plan, 2 users, ONLY AI enabled
     * 
     * Expected:
     * - base = 88.00
     * - planning = 0
     * - ai = 88.00 × 0.18 = 15.84
     * - addons_total = 15.84
     * - total = 103.84
     */
    public function test_team_plan_only_ai_addon()
    {
        $tenant = $this->tenant;

        Subscription::query()->where('tenant_id', $tenant->id)->delete();
        
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan' => 'team',
            'user_limit' => 2,
            'addons' => ['ai'], // Only AI enabled
            'status' => 'active',
            'is_trial' => false,
        ]);
        
        $tenant->setRelation('subscription', $subscription);
        $this->mockActiveUserCount($tenant, 2);

        $result = $this->calculator->calculateForTenant($tenant);

        $this->assertEquals(88.00, $result['base_subtotal']);
        $this->assertEquals(0.00, $result['addons']['planning']);
        $this->assertEquals(15.84, $result['addons']['ai']);
        $this->assertEquals(103.84, $result['total']);
        
        // CRITICAL: AI must be 18% of base ONLY
        $this->assertEquals(
            round(88.00 * 0.18, 2),
            $result['addons']['ai'],
            'AI add-on must be exactly 18% of base price'
        );
    }

    /**
     * Test Case D: Team plan, 2 users, BOTH Planning + AI enabled
     * 
     * Expected:
     * - base = 88.00
     * - planning = 88.00 × 0.18 = 15.84
     * - ai = 88.00 × 0.18 = 15.84 (NOT compounded!)
     * - addons_total = 31.68
     * - total = 119.68
     * 
     * CRITICAL TEST: Verifies that AI is NOT calculated as (base + planning) × 0.18
     */
    public function test_team_plan_both_addons_not_compounded()
    {
        $tenant = $this->tenant;

        Subscription::query()->where('tenant_id', $tenant->id)->delete();
        
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan' => 'team',
            'user_limit' => 2,
            'addons' => ['planning', 'ai'], // Both enabled
            'status' => 'active',
            'is_trial' => false,
        ]);
        
        $tenant->setRelation('subscription', $subscription);
        $this->mockActiveUserCount($tenant, 2);

        $result = $this->calculator->calculateForTenant($tenant);

        $basePrice = 88.00;
        $expectedPlanning = round($basePrice * 0.18, 2); // 15.84
        $expectedAi = round($basePrice * 0.18, 2);       // 15.84 (NOT compounded)
        $expectedTotal = $basePrice + $expectedPlanning + $expectedAi; // 119.68

        $this->assertEquals($basePrice, $result['base_subtotal']);
        $this->assertEquals($expectedPlanning, $result['addons']['planning']);
        $this->assertEquals($expectedAi, $result['addons']['ai']);
        $this->assertEquals($expectedTotal, $result['total']);

        // CRITICAL: Both add-ons must have the SAME price (proving non-compounding)
        $this->assertEquals(
            $result['addons']['planning'],
            $result['addons']['ai'],
            'Planning and AI must have identical prices (both 18% of base, not compounded)'
        );

        // CRITICAL: Verify AI is NOT (base + planning) × 0.18
        $wrongCompoundedAi = round(($basePrice + $expectedPlanning) * 0.18, 2); // 18.69 (WRONG!)
        $this->assertNotEquals(
            $wrongCompoundedAi,
            $result['addons']['ai'],
            'AI must NOT be calculated as (base + planning) × 18%'
        );
    }

    /**
     * Test Enterprise plan (all features included, no separate add-ons)
     */
    public function test_enterprise_plan_no_addons()
    {
        $tenant = $this->tenant;

        Subscription::query()->where('tenant_id', $tenant->id)->delete();
        
        $subscription = Subscription::factory()->create([
            'tenant_id' => $tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 3,
            'addons' => [],
            'status' => 'active',
            'is_trial' => false,
        ]);
        
        $tenant->setRelation('subscription', $subscription);
        $this->mockActiveUserCount($tenant, 3);

        $result = $this->calculator->calculateForTenant($tenant);

        $expectedBase = 59.00 * 3; // 177.00

        $this->assertEquals('enterprise', $result['plan']);
        $this->assertEquals(3, $result['user_count']);
        $this->assertEquals($expectedBase, $result['base_subtotal']);
        $this->assertEquals(0.00, $result['addons']['planning']);
        $this->assertEquals(0.00, $result['addons']['ai']);
        $this->assertEquals($expectedBase, $result['total']);
    }

    /**
     * Test different user counts to verify linear scaling
     */
    public function test_addon_scales_linearly_with_user_count()
    {
        $testCases = [
            ['users' => 1, 'base' => 44.00, 'planning' => 7.92, 'ai' => 7.92, 'total' => 59.84],
            ['users' => 5, 'base' => 220.00, 'planning' => 39.60, 'ai' => 39.60, 'total' => 299.20],
            ['users' => 10, 'base' => 440.00, 'planning' => 79.20, 'ai' => 79.20, 'total' => 598.40],
        ];

        foreach ($testCases as $case) {
            $tenant = $this->tenant;

            Subscription::query()->where('tenant_id', $tenant->id)->delete();
            
            $subscription = Subscription::factory()->create([
                'tenant_id' => $tenant->id,
                'plan' => 'team',
                'user_limit' => $case['users'],
                'addons' => ['planning', 'ai'],
                'status' => 'active',
                'is_trial' => false,
            ]);
            
            $tenant->setRelation('subscription', $subscription);
            $this->mockActiveUserCount($tenant, $case['users']);

            $result = $this->calculator->calculateForTenant($tenant);

            $this->assertEquals($case['base'], $result['base_subtotal'], "Base for {$case['users']} users");
            $this->assertEquals($case['planning'], $result['addons']['planning'], "Planning for {$case['users']} users");
            $this->assertEquals($case['ai'], $result['addons']['ai'], "AI for {$case['users']} users");
            $this->assertEquals($case['total'], $result['total'], "Total for {$case['users']} users");
        }
    }

    /**
     * Mock active user count for testing
     */
    protected function mockActiveUserCount($tenant, int $count): void
    {
        \App\Models\Technician::factory()->count($count)->create([
            'is_active' => 1,
        ]);
    }
}
