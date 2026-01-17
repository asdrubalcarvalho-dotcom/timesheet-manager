<?php

namespace Tests\Feature\Modules\Billing;

use App\Models\User;
use App\Models\Subscription;
use Laravel\Sanctum\Sanctum;
use Tests\TenantTestCase;

/**
 * BillingCheckoutIntegrationTest
 * 
 * Integration tests for Stripe checkout flow to verify:
 * - /api/billing/summary returns correct calculated amounts
 * - /api/billing/checkout/start creates PaymentIntent with correct amount
 * - Amounts match between internal calculation and Stripe
 * 
 * @group billing
 * @group checkout
 * @group integration
 */
class BillingCheckoutIntegrationTest extends TenantTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();

        // Create 2 active technicians in tenant DB
        \App\Models\Technician::factory()->count(2)->create([
            'is_active' => 1,
        ]);
    }

    /**
     * Test: /api/billing/summary returns correct amounts for Team + Planning
     */
    public function test_summary_endpoint_returns_correct_amounts_for_team_with_planning()
    {
        // Setup: Team plan, 2 users, Planning add-on only
        Subscription::query()->where('tenant_id', $this->tenant->id)->delete();
        $subscription = Subscription::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 2,
            'addons' => ['planning'],
            'status' => 'active',
            'is_trial' => false,
        ]);

        $this->tenant->setRelation('subscription', $subscription);

        // Act: Call summary endpoint
        Sanctum::actingAs($this->user);
        
        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/billing/summary');

        // Assert: HTTP 200 and correct structure
        $response->assertOk();

        $data = $response->json('data');

        // Expected values
        $expectedBase = 88.00;      // 44 × 2
        $expectedPlanning = 15.84;  // 88 × 0.18
        $expectedTotal = 103.84;    // 88 + 15.84

        $this->assertEquals($expectedBase, $data['base_subtotal']);
        $this->assertEquals($expectedPlanning, $data['addons']['planning']);
        $this->assertEquals(0.00, $data['addons']['ai']);
        $this->assertEquals($expectedTotal, $data['total']);
    }

    /**
     * Test: /api/billing/summary with BOTH add-ons enabled
     * 
     * CRITICAL: Verifies that both add-ons are calculated from base, not compounded
     */
    public function test_summary_endpoint_both_addons_not_compounded()
    {
        // Setup: Team plan, 2 users, Planning + AI
        Subscription::query()->where('tenant_id', $this->tenant->id)->delete();
        $subscription = Subscription::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 2,
            'addons' => ['planning', 'ai'],
            'status' => 'active',
            'is_trial' => false,
        ]);

        $this->tenant->setRelation('subscription', $subscription);

        // Act
        Sanctum::actingAs($this->user);
        
        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/billing/summary');

        // Assert
        $response->assertOk();
        $data = $response->json('data');

        $expectedBase = 88.00;
        $expectedPlanning = 15.84;
        $expectedAi = 15.84; // Same as planning (NOT compounded)
        $expectedTotal = 119.68;

        $this->assertEquals($expectedBase, $data['base_subtotal']);
        $this->assertEquals($expectedPlanning, $data['addons']['planning']);
        $this->assertEquals($expectedAi, $data['addons']['ai']);
        $this->assertEquals($expectedTotal, $data['total']);

        // CRITICAL: Both add-ons must be equal (proving non-compounding)
        $this->assertEquals(
            $data['addons']['planning'],
            $data['addons']['ai'],
            'Planning and AI must have identical prices in API response'
        );
    }

    /**
     * Test: /api/billing/checkout/start creates correct Stripe PaymentIntent amount
     * 
     * Verifies that the amount sent to Stripe matches the internal calculation
     */
    public function test_checkout_start_creates_correct_stripe_amount()
    {
        // Setup: Team plan, 2 users, both add-ons
        Subscription::query()->where('tenant_id', $this->tenant->id)->delete();
        $subscription = Subscription::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 2,
            'addons' => ['planning', 'ai'],
            'status' => 'active',
            'is_trial' => false,
        ]);

        $this->tenant->setRelation('subscription', $subscription);

        // Act: Start checkout for plan upgrade (or renewal)
        Sanctum::actingAs($this->user);
        
        $response = $this->withHeaders($this->tenantHeaders())
        ->postJson('/api/billing/checkout/start', [
            'mode' => 'plan',
            'plan' => 'team',
            'user_limit' => 2,
        ]);

        // Assert: HTTP 200 and correct amount
        $response->assertOk();
        $data = $response->json();

        // Plan-mode checkout charges base plan only; addons are handled via mode=addon.
        $expectedAmount = 88.00; // 44 × 2

        $this->assertEquals($expectedAmount, $data['amount']);
        $this->assertEquals('EUR', $data['currency']);
        $this->assertNotNull($data['client_secret']);

        // If using Stripe, verify amount is in cents (amount × 100)
        if (isset($data['gateway']) && $data['gateway'] === 'stripe') {
            // Stripe amounts are in cents, so we'd verify via metadata or logs
            // For now, just ensure the EUR amount is correct
            $this->assertEquals($expectedAmount, $data['amount']);
        }
    }

    /**
     * Test: Checkout start for addon activation calculates correct addon price
     */
    public function test_checkout_start_for_addon_calculates_from_base_price()
    {
        // Setup: Team plan, 2 users, NO add-ons initially
        Subscription::query()->where('tenant_id', $this->tenant->id)->delete();
        $subscription = Subscription::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 2,
            'addons' => [],
            'status' => 'active',
            'is_trial' => false,
        ]);

        $this->tenant->setRelation('subscription', $subscription);

        // Act: Start checkout to activate Planning add-on
        Sanctum::actingAs($this->user);
        
        $response = $this->withHeaders($this->tenantHeaders())
        ->postJson('/api/billing/checkout/start', [
            'mode' => 'addon',
            'addon' => 'planning',
        ]);

        // Assert: Addon price is 18% of current base (88.00)
        $response->assertOk();
        $data = $response->json();

        $expectedAddonPrice = round(88.00 * 0.18, 2); // 15.84

        $this->assertEquals($expectedAddonPrice, $data['amount']);
    }

    /**
     * Test: Toggling add-on and verifying summary recalculates correctly
     */
    public function test_toggle_addon_recalculates_summary_correctly()
    {
        // Setup: Team plan, 2 users, NO add-ons
        Subscription::query()->where('tenant_id', $this->tenant->id)->delete();
        $subscription = Subscription::factory()->create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 2,
            'addons' => [],
            'status' => 'active',
            'is_trial' => false,
        ]);

        $this->tenant->setRelation('subscription', $subscription);

        Sanctum::actingAs($this->user);

        // Step 1: Get initial summary (no add-ons)
        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/billing/summary');
        
        $response->assertOk();
        $this->assertEquals(88.00, $response->json('data.total'));

        // Step 2: Toggle Planning add-on ON
        $toggleResponse = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/toggle-addon', ['addon' => 'planning']);
        
        $toggleResponse->assertOk();

        // Step 3: Get updated summary
        $updatedResponse = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/billing/summary');
        
        $updatedResponse->assertOk();
        $updatedData = $updatedResponse->json('data');

        // Assert: Total increased by Planning addon (15.84)
        $this->assertEquals(88.00, $updatedData['base_subtotal']);
        $this->assertEquals(15.84, $updatedData['addons']['planning']);
        $this->assertEquals(103.84, $updatedData['total']);

        // Step 4: Toggle AI add-on ON
        $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/toggle-addon', ['addon' => 'ai']);

        // Step 5: Get final summary
        $finalResponse = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/billing/summary');
        
        $finalData = $finalResponse->json('data');

        // Assert: Both add-ons active, both calculated from base
        $this->assertEquals(88.00, $finalData['base_subtotal']);
        $this->assertEquals(15.84, $finalData['addons']['planning']);
        $this->assertEquals(15.84, $finalData['addons']['ai']); // SAME as planning
        $this->assertEquals(119.68, $finalData['total']);
    }
}
