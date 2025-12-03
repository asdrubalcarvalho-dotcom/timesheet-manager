<?php

namespace Tests\Feature\Billing;

use App\Models\Tenant;
use App\Models\User;
use Modules\Billing\Models\Subscription;
use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
class BillingApiTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tenant = Tenant::create([
            'name' => 'API Test Tenant',
            'slug' => 'api-test',
        ]);

        $this->user = User::factory()->create([
            'email' => 'admin@apitest.com',
        ]);

        Sanctum::actingAs($this->user);
    }

    /** @test */
    public function billing_summary_returns_subscription_details()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['planning'],
            'status' => 'active',
        ]);

        $response = $this->getJson('/api/billing/summary');

        $response->assertOk()
            ->assertJsonStructure([
                'subscription' => [
                    'id',
                    'plan',
                    'user_limit',
                    'addons',
                    'status',
                ],
                'features' => [
                    'timesheets',
                    'expenses',
                    'travels',
                    'planning',
                    'ai',
                ],
                'pricing' => [
                    'base_subtotal',
                    'addons',
                    'total',
                    'currency',
                ],
            ]);

        $data = $response->json();
        $this->assertEquals('team', $data['subscription']['plan']);
        $this->assertContains('planning', $data['subscription']['addons']);
        $this->assertTrue($data['features']['planning']);
    }

    /** @test */
    public function billing_summary_returns_null_for_no_subscription()
    {
        $response = $this->getJson('/api/billing/summary');

        $response->assertOk()
            ->assertJson([
                'subscription' => null,
            ]);
    }

    /** @test */
    public function upgrade_plan_updates_subscription()
    {
        $response = $this->postJson('/api/billing/upgrade-plan', [
            'plan' => 'team',
            'user_limit' => 10,
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'subscription',
                'features',
                'pricing',
                'message',
            ]);

        $data = $response->json();
        $this->assertEquals('team', $data['subscription']['plan']);
        $this->assertEquals(10, $data['subscription']['user_limit']);
        $this->assertTrue($data['features']['travels']);
    }

    /** @test */
    public function upgrade_plan_requires_valid_plan()
    {
        $response = $this->postJson('/api/billing/upgrade-plan', [
            'plan' => 'invalid-plan',
            'user_limit' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);
    }

    /** @test */
    public function upgrade_plan_requires_user_limit()
    {
        $response = $this->postJson('/api/billing/upgrade-plan', [
            'plan' => 'team',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['user_limit']);
    }

    /** @test */
    public function toggle_addon_enables_addon()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/billing/toggle-addon', [
            'addon' => 'planning',
        ]);

        $response->assertOk()
            ->assertJson([
                'action' => 'added',
                'enabled' => true,
            ]);

        $data = $response->json();
        $this->assertContains('planning', $data['subscription']['addons']);
    }

    /** @test */
    public function toggle_addon_disables_active_addon()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['planning'],
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/billing/toggle-addon', [
            'addon' => 'planning',
        ]);

        $response->assertOk()
            ->assertJson([
                'action' => 'removed',
                'enabled' => false,
            ]);

        $data = $response->json();
        $this->assertNotContains('planning', $data['subscription']['addons']);
    }

    /** @test */
    public function toggle_addon_requires_valid_addon()
    {
        Subscription::create([
            'tenant_id' => $this->tenant->id,
            'plan' => 'team',
            'user_limit' => 5,
            'status' => 'active',
        ]);

        $response = $this->postJson('/api/billing/toggle-addon', [
            'addon' => 'invalid-addon',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['addon']);
    }

    /** @test */
    public function checkout_start_creates_payment_intent()
    {
        $response = $this->postJson('/api/billing/checkout/start', [
            'plan' => 'team',
            'user_limit' => 5,
            'addons' => ['planning'],
        ]);

        $response->assertOk()
            ->assertJsonStructure([
                'transaction_id',
                'amount',
                'currency',
                'status',
                'test_cards',
            ]);

        $data = $response->json();
        $this->assertEquals('pending', $data['status']);
        $this->assertGreaterThan(0, $data['amount']);
    }

    /** @test */
    public function checkout_start_requires_valid_plan()
    {
        $response = $this->postJson('/api/billing/checkout/start', [
            'plan' => 'invalid',
            'user_limit' => 5,
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['plan']);
    }

    /** @test */
    public function checkout_confirm_with_valid_test_card_succeeds()
    {
        // Start checkout first
        $startResponse = $this->postJson('/api/billing/checkout/start', [
            'plan' => 'enterprise',
            'user_limit' => 10,
            'addons' => ['planning', 'ai'],
        ]);

        $transactionId = $startResponse->json('transaction_id');

        // Confirm with valid test card
        $response = $this->postJson('/api/billing/checkout/confirm', [
            'transaction_id' => $transactionId,
            'card_number' => '4111111111111111', // Valid test card
        ]);

        $response->assertOk()
            ->assertJson([
                'status' => 'succeeded',
            ])
            ->assertJsonStructure([
                'subscription',
                'payment',
                'message',
            ]);

        $data = $response->json();
        $this->assertEquals('enterprise', $data['subscription']['plan']);
        $this->assertContains('planning', $data['subscription']['addons']);
        $this->assertContains('ai', $data['subscription']['addons']);
    }

    /** @test */
    public function checkout_confirm_with_declined_card_fails()
    {
        // Start checkout
        $startResponse = $this->postJson('/api/billing/checkout/start', [
            'plan' => 'team',
            'user_limit' => 5,
        ]);

        $transactionId = $startResponse->json('transaction_id');

        // Confirm with declined test card
        $response = $this->postJson('/api/billing/checkout/confirm', [
            'transaction_id' => $transactionId,
            'card_number' => '4000000000000002', // Declined test card
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status' => 'failed',
                'message' => 'Card declined',
            ]);
    }

    /** @test */
    public function checkout_confirm_with_insufficient_funds_fails()
    {
        // Start checkout
        $startResponse = $this->postJson('/api/billing/checkout/start', [
            'plan' => 'enterprise',
            'user_limit' => 20,
        ]);

        $transactionId = $startResponse->json('transaction_id');

        // Confirm with insufficient funds card
        $response = $this->postJson('/api/billing/checkout/confirm', [
            'transaction_id' => $transactionId,
            'card_number' => '4000000000009995', // Insufficient funds
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status' => 'failed',
                'message' => 'Insufficient funds',
            ]);
    }

    /** @test */
    public function checkout_confirm_requires_transaction_id()
    {
        $response = $this->postJson('/api/billing/checkout/confirm', [
            'card_number' => '4111111111111111',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['transaction_id']);
    }

    /** @test */
    public function checkout_confirm_requires_card_number()
    {
        $response = $this->postJson('/api/billing/checkout/confirm', [
            'transaction_id' => 'txn_12345',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['card_number']);
    }

    /** @test */
    public function checkout_confirm_with_invalid_transaction_fails()
    {
        $response = $this->postJson('/api/billing/checkout/confirm', [
            'transaction_id' => 'invalid-transaction-id',
            'card_number' => '4111111111111111',
        ]);

        $response->assertStatus(400)
            ->assertJson([
                'status' => 'failed',
                'message' => 'Transaction not found',
            ]);
    }
}
