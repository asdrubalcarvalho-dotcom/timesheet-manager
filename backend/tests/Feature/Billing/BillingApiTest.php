<?php

namespace Tests\Feature\Billing;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Modules\Billing\Models\Subscription;
use Tests\TenantTestCase;
use Laravel\Sanctum\Sanctum;

/**
 * BillingApiTest
 * 
 * Tests for Billing API endpoints:
 * - GET /api/billing/summary
 * - POST /api/billing/upgrade-plan
 * - POST /api/billing/toggle-addon
 * - POST /api/billing/checkout/start
 * - POST /api/billing/checkout/confirm
 */
class BillingApiTest extends TenantTestCase
{
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        DB::connection('mysql')
            ->table('subscriptions')
            ->where('tenant_id', $this->tenant->id)
            ->delete();
        DB::connection('mysql')
            ->table('payments')
            ->where('tenant_id', $this->tenant->id)
            ->delete();

        $this->tenant->unsetRelation('subscription');
        $this->tenant->setRelation('subscription', null);
        if (tenancy()->tenant) {
            tenancy()->tenant->unsetRelation('subscription');
            tenancy()->tenant->setRelation('subscription', null);
        }

        $this->user = User::factory()->create([
            'email' => 'admin@apitest.com',
        ]);

        Sanctum::actingAs($this->user);
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function createActiveSubscription(array $overrides = []): Subscription
    {
        $subscription = Subscription::create(array_merge([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => [],
            'status' => 'active',
        ], $overrides));

        $this->tenant->unsetRelation('subscription');
        if (tenancy()->tenant) {
            tenancy()->tenant->unsetRelation('subscription');
        }

        return $subscription;
    }

    /** @test */
    public function billing_summary_returns_subscription_details()
    {
        $subscription = $this->createActiveSubscription([
            'addons' => ['planning'],
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/billing/summary');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'success',
                'data' => [
                    'plan',
                    'features',
                    'subscription',
                ],
            ]);

        $data = $response->json('data');
        $this->assertEquals('team', $data['plan']);
        $this->assertTrue($data['features']['planning']);
        $this->assertEquals($subscription->id, $data['subscription']['id']);
    }

    /** @test */
    public function billing_summary_returns_null_for_no_subscription()
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->getJson('/api/billing/summary');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.subscription', null);
    }

    /** @test */
    public function upgrade_plan_updates_subscription()
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/upgrade-plan', [
            'plan' => 'team',
            'user_limit' => 10,
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure([
                'payment_id',
                'client_secret',
                'gateway',
                'amount',
                'currency',
                'message',
            ]);
    }

    /** @test */
    public function upgrade_plan_requires_valid_plan()
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/upgrade-plan', [
            'plan' => 'invalid-plan',
            'user_limit' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);
    }

    /** @test */
    public function upgrade_plan_requires_user_limit()
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/upgrade-plan', [
            'plan' => 'team',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_limit']);
    }

    /** @test */
    public function toggle_addon_enables_addon()
    {
        $this->createActiveSubscription();

        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/toggle-addon', [
            'addon' => 'planning',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.action', 'enabled')
            ->assertJsonPath('data.addon', 'planning');

        $addons = $response->json('data.subscription.addons') ?? [];
        $this->assertContains('planning', $addons);
    }

    /** @test */
    public function toggle_addon_disables_active_addon()
    {
        $this->createActiveSubscription([
            'addons' => ['planning'],
        ]);

        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/toggle-addon', [
            'addon' => 'planning',
        ]);

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('data.action', 'disabled')
            ->assertJsonPath('data.addon', 'planning');

        $addons = $response->json('data.subscription.addons') ?? [];
        $this->assertNotContains('planning', $addons);
    }

    /** @test */
    public function toggle_addon_requires_valid_addon()
    {
        $this->createActiveSubscription();

        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/toggle-addon', [
            'addon' => 'invalid-addon',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['addon']);
    }

    /** @test */
    public function checkout_start_creates_payment_intent()
    {
        $this->createActiveSubscription();

        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/start', [
            'mode' => 'plan',
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['planning'],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'payment_id',
                'client_secret',
                'gateway',
                'amount',
                'currency',
            ]);

        $data = $response->json();
        $this->assertGreaterThanOrEqual(0, $data['amount']);
    }

    /** @test */
    public function checkout_start_requires_valid_plan()
    {
        $this->createActiveSubscription();

        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/start', [
            'mode' => 'plan',
            'plan' => 'invalid',
            'user_limit' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);
    }

    /** @test */
    public function checkout_confirm_with_valid_test_card_succeeds()
    {
        $this->createActiveSubscription([
            'plan' => 'team',
            'user_limit' => 10,
        ]);

        // Start checkout first
        $startResponse = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/start', [
            'mode' => 'plan',
            'plan' => 'enterprise',
            'user_limit' => 10,
            'addons' => ['planning', 'ai'],
        ]);

        $paymentId = $startResponse->json('payment_id');

        // Confirm with valid test card
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/confirm', [
            'payment_id' => $paymentId,
            'card_number' => '4111111111111111', // Valid test card
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'completed',
            ])
            ->assertJsonStructure([
                'payment_id',
                'message',
            ]);
    }

    /** @test */
    public function checkout_confirm_with_declined_card_fails()
    {
        $this->createActiveSubscription();

        // Start checkout
        $startResponse = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/start', [
            'mode' => 'plan',
            'plan' => 'team',
            'user_limit' => 5,
        ]);

        $paymentId = $startResponse->json('payment_id');

        // Confirm with declined test card
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/confirm', [
            'payment_id' => $paymentId,
            'card_number' => '4000000000000002', // Declined test card
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'failed',
            ]);
    }

    /** @test */
    public function checkout_confirm_with_expired_card_fails()
    {
        $this->createActiveSubscription();

        // Start checkout
        $startResponse = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/start', [
            'mode' => 'plan',
            'plan' => 'enterprise',
            'user_limit' => 20,
        ]);

        $paymentId = $startResponse->json('payment_id');

        // Confirm with insufficient funds card
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/confirm', [
            'payment_id' => $paymentId,
            'card_number' => '4000000000000069', // Expired card
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'failed',
            ]);
    }

    /** @test */
    public function checkout_confirm_requires_payment_id()
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/confirm', [
            'card_number' => '4111111111111111',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_id']);
    }

    /** @test */
    public function checkout_confirm_without_card_number_defaults_to_success()
    {
        $this->createActiveSubscription();

        $startResponse = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/start', [
            'mode' => 'plan',
            'plan' => 'team',
            'user_limit' => 5,
        ]);

        $paymentId = $startResponse->json('payment_id');

        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/confirm', [
            'payment_id' => $paymentId,
        ]);

        $response->assertOk()
            ->assertJson([
                'success' => true,
                'status' => 'completed',
            ]);
    }

    /** @test */
    public function checkout_confirm_with_invalid_payment_id_fails()
    {
        $response = $this->withHeaders($this->tenantHeaders())
            ->postJson('/api/billing/checkout/confirm', [
            'payment_id' => 999999,
            'card_number' => '4111111111111111',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['payment_id']);
    }
}
