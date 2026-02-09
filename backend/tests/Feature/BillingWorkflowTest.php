<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Tenant;
use App\Models\User;
use Modules\Billing\Models\Subscription;
use App\Services\Billing\PlanManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Comprehensive Billing Workflow Tests
 * 
 * Tests:
 * 1. Upgrades apply immediately with next_renewal_at = now + 30 days
 * 2. Downgrades schedule for next renewal (features stay active)
 * 3. Renewal date triggers automatic downgrade application
 * 4. Cancel scheduled downgrade (24h rule)
 * 5. Trial to paid conversion
 */
class BillingWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;
    protected PlanManager $planManager;

    protected function setUp(): void
    {
        parent::setUp();

        // Create technicians table to avoid PriceCalculator errors
        if (!Schema::hasTable('technicians')) {
            Schema::create('technicians', function (Blueprint $table) {
                $table->id();
                $table->foreignId('user_id')->constrained()->onDelete('cascade');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        // Create test tenant
        $this->tenant = Tenant::create([
            'slug' => 'test-billing-' . uniqid(),
            'name' => 'Test Billing Tenant',
        ]);

        // Create test user with technician
        $this->user = User::factory()->create([
            'email' => 'test@billing.test',
        ]);
        
        \DB::table('technicians')->insert([
            'user_id' => $this->user->id,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->planManager = app(PlanManager::class);
    }

    /** @test */
    public function test_upgrade_applies_immediately_and_sets_renewal_date()
    {
        // Given: Tenant on Starter plan
        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'starter',
            'user_limit' => 2,
            'status' => 'active',
            'is_trial' => false,
            'subscription_start_date' => Carbon::now()->subDays(10),
            'next_renewal_at' => Carbon::now()->addDays(20),
        ]);

        $beforeUpgrade = Carbon::now();

        // When: Upgrade to Team plan
        $this->planManager->updatePlan($this->tenant, 'team', 5);

        // Then: Plan changes immediately
        $subscription->refresh();
        $this->assertEquals('team', $subscription->plan);
        $this->assertEquals(5, $subscription->user_limit);

        // And: next_renewal_at is set to now + 30 days
        $this->assertNotNull($subscription->next_renewal_at);
        $expectedRenewal = $beforeUpgrade->copy()->addDays(30);
        
        // Check that renewal is approximately 30 days from now (allow 10 second variance)
        $diffInSeconds = abs($subscription->next_renewal_at->diffInSeconds($expectedRenewal));
        $this->assertLessThan(
            10,
            $diffInSeconds,
            "Expected renewal at {$expectedRenewal}, got {$subscription->next_renewal_at} (diff: {$diffInSeconds}s)"
        );

        // And: No pending downgrade
        $this->assertNull($subscription->pending_plan);
        $this->assertNull($subscription->pending_user_limit);

        echo "✅ UPGRADE TEST PASSED: Plan applied immediately, next_renewal_at = {$subscription->next_renewal_at}\n";
    }

    /** @test */
    public function test_downgrade_schedules_for_next_renewal_and_keeps_features_active()
    {
        // Given: Tenant on Enterprise plan
        $nextRenewal = Carbon::now()->addDays(15);
        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 10,
            'status' => 'active',
            'is_trial' => false,
            'subscription_start_date' => Carbon::now()->subDays(15),
            'next_renewal_at' => $nextRenewal,
        ]);

        // When: Schedule downgrade to Starter
        $result = $this->planManager->scheduleDowngrade($this->tenant, 'starter');

        // Then: Current plan unchanged
        $subscription->refresh();
        $this->assertEquals('enterprise', $subscription->plan, 'Current plan should NOT change');
        $this->assertEquals(10, $subscription->user_limit, 'User limit should NOT change');

        // And: Pending downgrade is scheduled
        $this->assertEquals('starter', $subscription->pending_plan);
        $this->assertEquals(2, $subscription->pending_user_limit);

        // And: Effective date matches next_renewal_at
        $this->assertEquals(
            $nextRenewal->toIso8601String(),
            $result['effective_at'],
            'Downgrade should be scheduled for next renewal date'
        );

        // And: Features remain active (checked via Pennant in real app)
        $this->assertTrue($subscription->hasPendingDowngrade());

        echo "✅ DOWNGRADE SCHEDULE TEST PASSED: Plan unchanged until {$nextRenewal}, pending: {$subscription->pending_plan}\n";
    }

    /** @test */
    public function test_downgrade_applies_automatically_at_renewal_date()
    {
        // Given: Tenant with pending downgrade scheduled for "today"
        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'pending_plan' => 'starter',
            'pending_user_limit' => 2,
            'status' => 'active',
            'is_trial' => false,
            'subscription_start_date' => Carbon::now()->subDays(30),
            'next_renewal_at' => Carbon::now()->subMinutes(5), // Renewal already passed
        ]);

        // When: Apply pending downgrade (simulates cron job at renewal)
        $result = $this->planManager->applyPendingDowngrade($this->tenant);

        // Then: Plan is downgraded
        $this->assertNotNull($result);
        $subscription->refresh();
        $this->assertEquals('starter', $subscription->plan, 'Plan should be downgraded');
        $this->assertEquals(2, $subscription->user_limit, 'User limit should be reduced');

        // And: Pending fields are cleared
        $this->assertNull($subscription->pending_plan);
        $this->assertNull($subscription->pending_user_limit);
        $this->assertFalse($subscription->hasPendingDowngrade());

        echo "✅ AUTOMATIC DOWNGRADE TEST PASSED: Downgrade applied at renewal, plan is now {$subscription->plan}\n";
    }

    /** @test */
    public function test_cancel_downgrade_works_with_more_than_24h_remaining()
    {
        // Given: Tenant with pending downgrade 48h away
        $nextRenewal = Carbon::now()->addHours(48);
        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 10,
            'pending_plan' => 'team',
            'pending_user_limit' => 5,
            'status' => 'active',
            'is_trial' => false,
            'next_renewal_at' => $nextRenewal,
        ]);

        // When: Cancel scheduled downgrade
        $result = $this->planManager->cancelScheduledDowngrade($this->tenant);

        // Then: Cancellation succeeds
        $this->assertTrue($result['success']);
        $this->assertEquals('enterprise', $result['current_plan']);

        // And: Pending fields are cleared
        $subscription->refresh();
        $this->assertNull($subscription->pending_plan);
        $this->assertNull($subscription->pending_user_limit);

        echo "✅ CANCEL DOWNGRADE TEST PASSED: Downgrade cancelled with 48h remaining\n";
    }

    /** @test */
    public function test_cancel_downgrade_fails_with_less_than_24h_remaining()
    {
        // Given: Tenant with pending downgrade 12h away
        $nextRenewal = Carbon::now()->addHours(12);
        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 10,
            'pending_plan' => 'team',
            'pending_user_limit' => 5,
            'status' => 'active',
            'is_trial' => false,
            'next_renewal_at' => $nextRenewal,
        ]);

        // When/Then: Cancel should throw exception
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessageMatches('/Cannot cancel.*hours/i');

        $this->planManager->cancelScheduledDowngrade($this->tenant);

        echo "✅ CANCEL DOWNGRADE 24H RULE TEST PASSED: Cannot cancel with <24h remaining\n";
    }

    /** @test */
    public function test_trial_to_paid_applies_immediately_with_renewal_date()
    {
        // Given: Tenant on trial
        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => null,
            'status' => 'active',
            'is_trial' => true,
            'trial_ends_at' => Carbon::now()->addDays(10),
        ]);

        $beforeConversion = Carbon::now();

        // When: Convert trial to Starter (via scheduleDowngrade which detects trial)
        $result = $this->planManager->scheduleDowngrade($this->tenant, 'starter');

        // Then: Conversion is immediate (not scheduled)
        $this->assertTrue($result['is_immediate']);
        $this->assertFalse($result['is_trial']);

        // And: Subscription is updated immediately
        $subscription->refresh();
        $this->assertEquals('starter', $subscription->plan);
        $this->assertEquals(2, $subscription->user_limit);
        $this->assertFalse($subscription->is_trial);
        $this->assertNull($subscription->trial_ends_at);

        // And: subscription_start_date is set
        $this->assertNotNull($subscription->subscription_start_date);

        // And: next_renewal_at is set to start_date + 30 days
        $this->assertNotNull($subscription->next_renewal_at);
        $expectedRenewal = $subscription->subscription_start_date->copy()->addMonth();
        $this->assertTrue(
            $subscription->next_renewal_at->between(
                $expectedRenewal->subMinutes(1),
                $expectedRenewal->addMinutes(1)
            )
        );

        echo "✅ TRIAL EXIT TEST PASSED: Trial ended immediately, next_renewal_at = {$subscription->next_renewal_at}\n";
    }

    /** @test */
    public function test_can_cancel_downgrade_helper_respects_24h_rule()
    {
        // Test case 1: >24h remaining
        $tenant48h = Tenant::create(['slug' => 'test-48h-' . uniqid(), 'name' => 'Test 48h']);
        $subscription48h = Subscription::create([
            'tenant_id' => $tenant48h->id,
            'plan' => 'team',
            'pending_plan' => 'starter',
            'next_renewal_at' => Carbon::now()->addHours(48),
            'status' => 'active',
        ]);
        $this->assertTrue(
            $this->planManager->canCancelDowngrade($subscription48h),
            'Should be able to cancel with 48h remaining'
        );

        // Test case 2: Exactly 25h remaining
        $tenant25h = Tenant::create(['slug' => 'test-25h-' . uniqid(), 'name' => 'Test 25h']);
        $subscription25h = Subscription::create([
            'tenant_id' => $tenant25h->id,
            'plan' => 'team',
            'pending_plan' => 'starter',
            'next_renewal_at' => Carbon::now()->addHours(25),
            'status' => 'active',
        ]);
        $this->assertTrue(
            $this->planManager->canCancelDowngrade($subscription25h),
            'Should be able to cancel with 25h remaining'
        );

        // Test case 3: <24h remaining
        $tenant12h = Tenant::create(['slug' => 'test-12h-' . uniqid(), 'name' => 'Test 12h']);
        $subscription12h = Subscription::create([
            'tenant_id' => $tenant12h->id,
            'plan' => 'team',
            'pending_plan' => 'starter',
            'next_renewal_at' => Carbon::now()->addHours(12),
            'status' => 'active',
        ]);
        $this->assertFalse(
            $this->planManager->canCancelDowngrade($subscription12h),
            'Should NOT be able to cancel with 12h remaining'
        );

        // Test case 4: No pending downgrade
        $tenantNoPending = Tenant::create(['slug' => 'test-none-' . uniqid(), 'name' => 'Test None']);
        $subscriptionNoPending = Subscription::create([
            'tenant_id' => $tenantNoPending->id,
            'plan' => 'team',
            'next_renewal_at' => Carbon::now()->addHours(48),
            'status' => 'active',
        ]);
        $this->assertFalse(
            $this->planManager->canCancelDowngrade($subscriptionNoPending),
            'Should NOT be able to cancel when no downgrade is pending'
        );

        echo "✅ 24H RULE HELPER TEST PASSED: All boundary conditions validated\n";
    }

    /** @test */
    public function test_billing_summary_includes_pending_downgrade_info()
    {
        // Given: Tenant with scheduled downgrade
        $nextRenewal = Carbon::now()->addDays(15);
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'enterprise',
            'user_limit' => 10,
            'pending_plan' => 'team',
            'pending_user_limit' => 5,
            'status' => 'active',
            'is_trial' => false,
            'subscription_start_date' => Carbon::now()->subDays(15),
            'next_renewal_at' => $nextRenewal,
        ]);

        // When: Get billing summary
        $summary = $this->planManager->getSubscriptionSummary($this->tenant);

        // Then: Summary includes pending downgrade
        $this->assertArrayHasKey('pending_downgrade', $summary);
        $this->assertEquals('team', $summary['pending_downgrade']['target_plan']);
        $this->assertEquals(5, $summary['pending_downgrade']['target_user_limit']);
        $this->assertEquals($nextRenewal->toIso8601String(), $summary['pending_downgrade']['effective_at']);

        // And: Can cancel flag is true (>24h away)
        $this->assertTrue($summary['can_cancel_downgrade']);

        echo "✅ BILLING SUMMARY TEST PASSED: Pending downgrade info correctly included\n";
    }

    /** @test */
    public function test_complete_upgrade_downgrade_cycle()
    {
        echo "\n🔄 COMPLETE CYCLE TEST:\n";

        // Step 1: Start on Starter
        $subscription = Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'starter',
            'user_limit' => 2,
            'status' => 'active',
            'is_trial' => false,
            'subscription_start_date' => Carbon::now()->subDays(5),
            'next_renewal_at' => Carbon::now()->addDays(25),
        ]);
        echo "  1️⃣ Started on Starter plan\n";

        // Step 2: Upgrade to Team (immediate)
        $this->planManager->updatePlan($this->tenant, 'team', 5);
        $subscription->refresh();
        $this->assertEquals('team', $subscription->plan);
        $upgradeRenewal = $subscription->next_renewal_at;
        echo "  2️⃣ Upgraded to Team (immediate), next_renewal_at = {$upgradeRenewal}\n";

        // Step 3: Schedule downgrade to Starter
        $result = $this->planManager->scheduleDowngrade($this->tenant, 'starter');
        $subscription->refresh();
        $this->assertEquals('team', $subscription->plan); // Still Team
        $this->assertEquals('starter', $subscription->pending_plan); // Scheduled
        echo "  3️⃣ Downgrade to Starter scheduled for {$result['effective_at']}\n";

        // Step 4: Cancel the downgrade
        $this->planManager->cancelScheduledDowngrade($this->tenant);
        $subscription->refresh();
        $this->assertNull($subscription->pending_plan);
        echo "  4️⃣ Downgrade cancelled\n";

        // Step 5: Schedule downgrade again
        $this->planManager->scheduleDowngrade($this->tenant, 'starter');
        $subscription->refresh();
        echo "  5️⃣ Downgrade re-scheduled\n";

        // Step 6: Simulate renewal date arrival (set to past)
        $subscription->next_renewal_at = Carbon::now()->subMinutes(5);
        $subscription->save();
        echo "  6️⃣ Simulated renewal date arrival\n";

        // Step 7: Apply pending downgrade
        $this->planManager->applyPendingDowngrade($this->tenant);
        $subscription->refresh();
        $this->assertEquals('starter', $subscription->plan);
        $this->assertNull($subscription->pending_plan);
        echo "  7️⃣ Downgrade applied automatically, plan is now Starter\n";

        echo "✅ COMPLETE CYCLE TEST PASSED\n\n";
    }
}
