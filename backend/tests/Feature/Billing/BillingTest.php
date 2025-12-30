<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Services\Billing\PriceCalculator;
use App\Services\Billing\PlanManager;
use App\Services\Billing\PaymentSnapshot as PaymentSnapshotService;
use App\Services\TenantFeatures;
use Illuminate\Support\Str;
use Illuminate\Http\Request;
use Modules\Billing\Models\Payment;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Http\Middleware\EnsureSubscriptionWriteAccess;

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
    protected array $userCounts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->userCounts = [];

        $userCountResolver = fn (Tenant $tenant): int => $this->getSeededUserCount($tenant);

        $this->priceCalculator = new class($userCountResolver) extends PriceCalculator {
            public function __construct(private \Closure $userCountResolver)
            {
            }

            protected function getActiveUserCount(Tenant $tenant): int
            {
                return ($this->userCountResolver)($tenant);
            }
        };

        // Ensure PlanManager uses the test calculator (avoids querying tenant DB tables).
        $this->app->instance(PriceCalculator::class, $this->priceCalculator);

        $this->planManager = app(PlanManager::class);

        // Create test tenant with deterministic slug for legacy expectations
        $this->tenant = $this->makeTenant([
            'name' => 'Test Tenant',
            'slug' => 'test-tenant',
        ]);
    }

    /** @test */
    public function starter_plan_costs_35_euros_flat()
    {
        $tenant = $this->makeTenant();
        $this->seedActiveTechnicians($tenant, 1);

        $pricing = $this->priceCalculator->calculate($tenant);

        $this->assertEquals('starter', $pricing['plan']);
        $this->assertEquals(1, $pricing['user_count']);
        $this->assertEquals(0.0, $pricing['base_subtotal']);
        $this->assertEquals(0.0, $pricing['total']);
        $this->assertFalse($pricing['requires_upgrade']);
    }

    /** @test */
    public function starter_plan_with_2_users_does_not_require_upgrade()
    {
        $tenant = $this->makeTenant();
        $this->seedActiveTechnicians($tenant, 2);

        $pricing = $this->priceCalculator->calculate($tenant);

        $this->assertEquals(2, $pricing['user_count']);
        $this->assertFalse($pricing['requires_upgrade']);
    }

    /** @test */
    public function starter_plan_with_3_users_requires_upgrade()
    {
        $tenant = $this->makeTenant();
        $this->seedActiveTechnicians($tenant, 3);

        $pricing = $this->priceCalculator->calculate($tenant);

        $this->assertEquals(3, $pricing['user_count']);
        $this->assertTrue($pricing['requires_upgrade']);
    }

    /** @test */
    public function team_plan_costs_35_euros_per_user()
    {
        $tenant = $this->createTenantWithSubscription('team', 5);
        $this->seedActiveTechnicians($tenant, 5);

        $pricing = $this->priceCalculator->calculate($tenant);

        $this->assertEquals('team', $pricing['plan']);
        $this->assertEquals(220.00, $pricing['base_subtotal']);
        $this->assertEquals(220.00, $pricing['total']);
        $this->assertFalse($pricing['requires_upgrade']);
    }

    /** @test */
    public function enterprise_plan_costs_35_euros_per_user()
    {
        $tenant = $this->createTenantWithSubscription('enterprise', 10);
        $this->seedActiveTechnicians($tenant, 10);

        $pricing = $this->priceCalculator->calculate($tenant);

        $this->assertEquals('enterprise', $pricing['plan']);
        $this->assertEquals(590.00, $pricing['base_subtotal']);
        $this->assertEquals(590.00, $pricing['total']);
    }

    /** @test */
    public function planning_addon_adds_18_percent_markup()
    {
        $tenant = $this->createTenantWithSubscription('team', 5, ['planning']);
        $this->seedActiveTechnicians($tenant, 5);

        $pricing = $this->priceCalculator->calculate($tenant);

        $baseSubtotal = 220.00; // 5 × 44
        $expectedPlanning = round($baseSubtotal * 0.18, 2);
        $expectedTotal = $baseSubtotal + $expectedPlanning;

        $this->assertEquals($baseSubtotal, $pricing['base_subtotal']);
        $this->assertEquals($expectedPlanning, $pricing['addons']['planning']);
        $this->assertEquals($expectedTotal, $pricing['total']);
    }

    /** @test */
    public function ai_addon_adds_18_percent_over_base_plus_planning()
    {
        $tenant = $this->createTenantWithSubscription('team', 10, ['planning', 'ai']);
        $this->seedActiveTechnicians($tenant, 10);

        $pricing = $this->priceCalculator->calculate($tenant);

        $baseSubtotal = 440.00; // 10 × 44
        $expectedAddon = round($baseSubtotal * 0.18, 2);
        $expectedTotal = $baseSubtotal + ($expectedAddon * 2);

        $this->assertEquals($baseSubtotal, $pricing['base_subtotal']);
        $this->assertEquals($expectedAddon, $pricing['addons']['planning']);
        $this->assertEquals($expectedAddon, $pricing['addons']['ai']);
        $this->assertEquals($expectedTotal, $pricing['total']);
    }

    /** @test */
    public function starter_plan_does_not_allow_addons()
    {
        $tenant = $this->createTenantWithSubscription('starter', 2, ['planning']);
        $this->seedActiveTechnicians($tenant, 2);

        $pricing = $this->priceCalculator->calculate($tenant);

        $this->assertEquals(0.0, $pricing['addons']['planning']);
        $this->assertEquals(0.0, $pricing['total']);
    }

    /** @test */
    public function ai_addon_only_available_on_enterprise()
    {
        $teamTenant = $this->createTenantWithSubscription('team', 5, ['ai']);
        $enterpriseTenant = $this->createTenantWithSubscription('enterprise', 5);

        $this->seedActiveTechnicians($teamTenant, 5);
        $this->seedActiveTechnicians($enterpriseTenant, 5);

        $teamPricing = $this->priceCalculator->calculate($teamTenant);
        $enterprisePricing = $this->priceCalculator->calculate($enterpriseTenant);

        $this->assertGreaterThan(0, $teamPricing['addons']['ai']);
        $this->assertEquals(0.0, $enterprisePricing['addons']['ai']);
    }

    /** @test */
    public function default_features_initialized_for_new_tenant()
    {
        $tenant = $this->makeTenant();

        $this->assertTrue(TenantFeatures::active($tenant, TenantFeatures::TIMESHEETS));
        $this->assertTrue(TenantFeatures::active($tenant, TenantFeatures::EXPENSES));
        $this->assertFalse(TenantFeatures::active($tenant, TenantFeatures::TRAVELS));
        $this->assertFalse(TenantFeatures::active($tenant, TenantFeatures::PLANNING));
        $this->assertFalse(TenantFeatures::active($tenant, TenantFeatures::AI));
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

        $this->tenant->update(['ai_enabled' => true]);

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

        // Without tenant toggle, AI stays disabled
        TenantFeatures::syncFromSubscription($this->tenant);
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::AI));

        // Once the tenant enables AI, the feature becomes active
        $this->tenant->update(['ai_enabled' => true]);
        TenantFeatures::syncFromSubscription($this->tenant);
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::AI));
    }

    /** @test */
    public function feature_flags_are_tenant_scoped()
    {
        $firstTenant = $this->makeTenant();
        $secondTenant = $this->makeTenant();

        $subscription = Subscription::create([
            'tenant_id' => $firstTenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'status' => 'active',
        ]);

        $firstTenant->setRelation('subscription', $subscription);

        TenantFeatures::syncFromSubscription($firstTenant);

        $this->assertTrue(TenantFeatures::active($firstTenant, TenantFeatures::TRAVELS));
        $this->assertFalse(TenantFeatures::active($secondTenant, TenantFeatures::TRAVELS));
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
    public function active_trial_is_not_read_only()
    {
        $tenant = $this->makeTenant([
            'trial_ends_at' => now()->addDays(7),
        ]);

        $summary = app(PlanManager::class)->getSubscriptionSummary($tenant);

        $this->assertEquals('trial', $summary['subscription_state']);
        $this->assertFalse($summary['read_only']);
    }

    /** @test */
    public function expired_trial_is_read_only()
    {
        $tenant = $this->makeTenant([
            'trial_ends_at' => now()->subDay(),
        ]);

        $summary = app(PlanManager::class)->getSubscriptionSummary($tenant);

        $this->assertNotEquals('trial', $summary['subscription_state']);
        $this->assertTrue($summary['read_only']);
    }

    /** @test */
    public function expiring_enterprise_trial_subscription_sets_tenant_state_to_expired()
    {
        $tenant = $this->makeTenant([
            'trial_ends_at' => now()->subDay(),
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan' => 'enterprise',
            'user_limit' => null,
            'status' => 'active',
            'is_trial' => true,
            'trial_ends_at' => now()->subDay(),
        ]);

        $summary = app(PlanManager::class)->getSubscriptionSummary($tenant);

        $tenant->refresh();

        $this->assertEquals('expired', $summary['subscription_state']);
        $this->assertTrue($summary['read_only']);
        $this->assertEquals('expired', $tenant->subscription_state);
    }

    /** @test */
    public function active_subscription_is_not_read_only_even_if_trial_expired()
    {
        $tenant = $this->makeTenant([
            'trial_ends_at' => now()->subDays(10),
        ]);

        Subscription::create([
            'tenant_id' => $tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'status' => 'active',
        ]);

        $summary = app(PlanManager::class)->getSubscriptionSummary($tenant);

        $this->assertEquals('active', $summary['subscription_state']);
        $this->assertFalse($summary['read_only']);
    }

    /** @test */
    public function upgrading_to_paid_plan_clears_stale_billing_period_end_and_unblocks_writes()
    {
        // Start from an expired state with a stale billing period end in the past.
        $this->tenant->update([
            'subscription_state' => 'expired',
        ]);

        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 5,
            'status' => 'active',
            'is_trial' => false,
            'trial_ends_at' => null,
            'billing_period_ends_at' => now()->subDay(),
        ]);

        // Upgrade to TEAM (immediate upgrade path)
        $subscription = $this->planManager->updatePlan($this->tenant->fresh(), 'team', 5);
        $subscription->refresh();

        $this->assertEquals('team', $subscription->plan);
        $this->assertEquals('active', $subscription->status);
        $this->assertNotNull($subscription->billing_period_ends_at);
        $this->assertTrue($subscription->billing_period_ends_at->gte(now()));

        $summary = $this->planManager->getSubscriptionSummary($this->tenant->fresh());
        $this->assertEquals('active', $summary['subscription_state']);
        $this->assertFalse($summary['read_only']);

        // Middleware should allow writes after upgrade.
        tenancy()->initialize($this->tenant->fresh()->load('subscription'));
        $middleware = new EnsureSubscriptionWriteAccess();
        $request = Request::create('/api/timesheets', 'POST');

        $response = $middleware->handle($request, fn () => response()->json(['ok' => true], 200));
        $this->assertEquals(200, $response->getStatusCode());
    }

    /** @test */
    public function applying_paid_checkout_snapshot_ends_trial_and_updates_summary()
    {
        $this->seedActiveTechnicians($this->tenant, 3);

        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => null,
            'status' => 'active',
            'is_trial' => true,
            'trial_ends_at' => now()->addDays(10),
        ]);

        $payment = Payment::create([
            'tenant_id' => $this->tenant->id,
            'amount' => 132.00,
            'currency' => 'EUR',
            'status' => 'completed',
            'gateway' => 'stripe',
            'gateway_reference' => 'pi_test_123',
            'plan' => 'team',
            'user_limit' => 3,
            'addons' => [],
            'cycle_start' => now()->startOfDay(),
            'cycle_end' => now()->addMonth()->startOfDay(),
            'stripe_payment_intent_id' => 'pi_test_123',
            'metadata' => [
                'mode' => 'plan',
                'plan' => 'team',
                'user_limit' => 3,
            ],
        ]);

        app(PaymentSnapshotService::class)->applySnapshot($payment, $subscription);

        $subscription->refresh();
        $this->assertFalse($subscription->is_trial);
        $this->assertNull($subscription->trial_ends_at);
        $this->assertEquals('team', $subscription->plan);
        $this->assertEquals(3, $subscription->user_limit);
        $this->assertNotNull($subscription->billing_period_started_at);
        $this->assertNotNull($subscription->billing_period_ends_at);

        $summary = $this->planManager->getSubscriptionSummary($this->tenant->fresh()->load('subscription'));

        $this->assertEquals('team', $summary['plan']);
        $this->assertFalse($summary['is_trial']);
        $this->assertEquals(132.00, $summary['total']);
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

        $this->assertEquals('enabled', $result['action']);
        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::PLANNING));

        // Disable planning addon
        $result = $this->planManager->toggleAddon($this->tenant, 'planning');

        $this->assertEquals('disabled', $result['action']);
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::PLANNING));
    }

    /** @test */
    public function toggling_ai_addon_syncs_tenant_toggle()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'status' => 'active',
        ]);

        $this->assertFalse((bool) $this->tenant->ai_enabled);

        $result = $this->planManager->toggleAddon($this->tenant, 'ai');

        $this->assertEquals('enabled', $result['action']);
        $this->assertTrue($this->tenant->fresh()->ai_enabled);
        $this->assertTrue(TenantFeatures::active($this->tenant, TenantFeatures::AI));

        $result = $this->planManager->toggleAddon($this->tenant, 'ai');

        $this->assertEquals('disabled', $result['action']);
        $this->assertFalse($this->tenant->fresh()->ai_enabled);
        $this->assertFalse(TenantFeatures::active($this->tenant, TenantFeatures::AI));
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

    protected function createTenantWithSubscription(string $plan, int $userLimit, array $addons = []): Tenant
    {
        $tenant = $this->makeTenant();

        $subscription = Subscription::create([
            'tenant_id' => $tenant->id,
            'plan' => $plan,
            'user_limit' => $userLimit,
            'addons' => $addons,
            'status' => 'active',
            'is_trial' => false,
        ]);

        $tenant->setRelation('subscription', $subscription);

        return $tenant;
    }

    protected function seedActiveTechnicians(Tenant $tenant, int $count): void
    {
        $this->userCounts[$tenant->id] = max(0, $count);
    }

    protected function getSeededUserCount(Tenant $tenant): int
    {
        return $this->userCounts[$tenant->id] ?? 0;
    }

    protected function makeTenant(array $attributes = []): Tenant
    {
        $defaults = [
            'name' => 'Tenant ' . Str::random(6),
            'slug' => 'tenant-' . Str::random(6),
        ];

        return Tenant::create(array_merge($defaults, $attributes));
    }
}
