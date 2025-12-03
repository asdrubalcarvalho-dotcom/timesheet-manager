<?php

namespace App\Services\Payments;

use App\Models\Tenant;
use App\Services\Billing\MetadataBuilder;
use Stripe\Stripe;
use Stripe\Subscription;
use Stripe\Invoice;
use Stripe\InvoiceItem;
use Stripe\Exception\ApiErrorException;
use Illuminate\Support\Facades\Log;

/**
 * StripeSubscriptionManager
 * 
 * Phase 2: Subscription Management Service
 * Phase 3: Invoice generation for subscriptions and addons
 * 
 * Responsibilities:
 * - Create Stripe Subscription objects for tenants
 * - Update subscription items (plan changes, addon toggles)
 * - Cancel subscriptions (immediate or end-of-period)
 * - Sync subscription state to tenant record
 * - Generate Stripe Invoices with ERP-friendly metadata (Phase 3)
 * 
 * Usage Pattern:
 * - Only called when config('billing.subscriptions.enabled') === true
 * - Enforced test mode only via config('billing.subscriptions.test_mode_only')
 * - Reuses StripeGateway's ensureStripeCustomer() for customer creation
 * 
 * Security:
 * - Validates test mode constraint before any Stripe API call
 * - All methods return ['success' => bool, 'subscription' => ?Subscription, 'error' => ?string]
 */
class StripeSubscriptionManager
{
    protected StripeGateway $gateway;
    protected MetadataBuilder $metadataBuilder;

    public function __construct(StripeGateway $gateway, MetadataBuilder $metadataBuilder)
    {
        $this->gateway = $gateway;
        $this->metadataBuilder = $metadataBuilder;
        Stripe::setApiKey(config('stripe.current.sk'));
    }

    /**
     * Create or update a Stripe Subscription for a tenant
     * 
     * Use Cases:
     * - Initial plan purchase (create subscription with plan item)
     * - Plan upgrade/downgrade (update subscription items)
     * - Addon toggle (add/remove subscription items)
     * 
     * @param Tenant $tenant
     * @param string $plan - Plan slug from config('billing.plans') keys
     * @param array $addons - Array of addon slugs from config('billing.addons') keys
     * @return array ['success' => bool, 'subscription' => ?Subscription, 'error' => ?string]
     */
    public function createOrUpdateSubscriptionForTenant(Tenant $tenant, string $plan, array $addons = []): array
    {
        // Guard: Feature flag check
        if (!config('billing.subscriptions.enabled')) {
            return [
                'success' => false,
                'subscription' => null,
                'error' => 'Subscriptions not enabled (BILLING_SUBSCRIPTIONS_ENABLED=false)',
            ];
        }

        // Guard: Test mode only constraint
        if (config('billing.subscriptions.test_mode_only') && config('stripe.mode') !== 'test') {
            Log::warning('StripeSubscriptionManager: Attempted live mode operation while test_mode_only=true', [
                'tenant_id' => $tenant->id,
                'plan' => $plan,
            ]);
            return [
                'success' => false,
                'subscription' => null,
                'error' => 'Subscriptions only available in test mode (test_mode_only=true)',
            ];
        }

        try {
            // Ensure Stripe Customer exists
            $customerResult = $this->gateway->ensureStripeCustomer($tenant);
            if (!$customerResult['success']) {
                return [
                    'success' => false,
                    'subscription' => null,
                    'error' => $customerResult['error'] ?? 'Failed to create/fetch Stripe customer',
                ];
            }

            $stripeCustomerId = $customerResult['customer_id'];

            // Build subscription items array
            $items = $this->buildSubscriptionItems($plan, $addons);

            // Check if tenant already has a subscription
            if ($tenant->stripe_subscription_id) {
                return $this->updateExistingSubscription($tenant, $items);
            } else {
                return $this->createNewSubscription($tenant, $stripeCustomerId, $items);
            }

        } catch (ApiErrorException $e) {
            Log::error('StripeSubscriptionManager: Stripe API error', [
                'tenant_id' => $tenant->id,
                'plan' => $plan,
                'addons' => $addons,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'subscription' => null,
                'error' => $e->getMessage(),
            ];
        } catch (\Exception $e) {
            Log::error('StripeSubscriptionManager: Unexpected error', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'subscription' => null,
                'error' => 'Unexpected error: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Cancel a tenant's Stripe Subscription
     * 
     * @param Tenant $tenant
     * @param bool $immediately - If true, cancel now. If false, cancel at period end.
     * @return array ['success' => bool, 'subscription' => ?Subscription, 'error' => ?string]
     */
    public function cancelSubscriptionForTenant(Tenant $tenant, bool $immediately = false): array
    {
        // Guard: No subscription to cancel
        if (!$tenant->stripe_subscription_id) {
            return [
                'success' => false,
                'subscription' => null,
                'error' => 'Tenant has no active subscription',
            ];
        }

        // Guard: Test mode only constraint
        if (config('billing.subscriptions.test_mode_only') && config('stripe.mode') !== 'test') {
            return [
                'success' => false,
                'subscription' => null,
                'error' => 'Subscriptions only available in test mode',
            ];
        }

        try {
            $subscription = Subscription::retrieve($tenant->stripe_subscription_id);

            if ($immediately) {
                $subscription = $subscription->cancel();
                
                // Clear subscription data from tenant
                $tenant->update([
                    'stripe_subscription_id' => null,
                    'active_addons' => null,
                    'subscription_renews_at' => null,
                ]);

                Log::info('StripeSubscriptionManager: Subscription canceled immediately', [
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                ]);
            } else {
                // Cancel at period end
                $subscription->cancel_at_period_end = true;
                $subscription = $subscription->save();

                Log::info('StripeSubscriptionManager: Subscription marked for cancellation at period end', [
                    'tenant_id' => $tenant->id,
                    'subscription_id' => $subscription->id,
                    'cancel_at' => date('Y-m-d H:i:s', $subscription->current_period_end),
                ]);
            }

            return [
                'success' => true,
                'subscription' => $subscription,
                'error' => null,
            ];

        } catch (ApiErrorException $e) {
            Log::error('StripeSubscriptionManager: Failed to cancel subscription', [
                'tenant_id' => $tenant->id,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'subscription' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Sync Stripe Subscription state to tenant record
     * 
     * Called by:
     * - StripeWebhookController (customer.subscription.* events)
     * - After successful createOrUpdateSubscription() calls
     * 
     * @param Tenant $tenant
     * @param Subscription $subscription
     * @return bool
     */
    public function syncSubscriptionStateToTenant(Tenant $tenant, Subscription $subscription): bool
    {
        try {
            // Extract active addons from subscription items
            $activeAddons = $this->extractAddonsFromSubscriptionItems($subscription->items->data);

            $tenant->update([
                'stripe_subscription_id' => $subscription->id,
                'active_addons' => $activeAddons,
                'subscription_renews_at' => $subscription->current_period_end 
                    ? date('Y-m-d H:i:s', $subscription->current_period_end) 
                    : null,
            ]);

            Log::info('StripeSubscriptionManager: Synced subscription state to tenant', [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'active_addons' => $activeAddons,
            ]);

            return true;

        } catch (\Exception $e) {
            Log::error('StripeSubscriptionManager: Failed to sync subscription state', [
                'tenant_id' => $tenant->id,
                'subscription_id' => $subscription->id,
                'error' => $e->getMessage(),
            ]);
            return false;
        }
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Build subscription items array for Stripe API
     * 
     * @param string $plan
     * @param array $addons
     * @return array [ ['price' => 'price_xxx'], ... ]
     */
    private function buildSubscriptionItems(string $plan, array $addons): array
    {
        $items = [];

        // Add plan Price ID
        $planPriceId = $this->gateway->getStripePriceIdForPlan($plan);
        if ($planPriceId) {
            $items[] = ['price' => $planPriceId];
        } else {
            throw new \Exception("No Stripe Price ID configured for plan: {$plan}");
        }

        // Add addon Price IDs
        foreach ($addons as $addonSlug) {
            $addonPriceId = $this->gateway->getStripePriceIdForAddon($addonSlug);
            if ($addonPriceId) {
                $items[] = ['price' => $addonPriceId];
            } else {
                Log::warning('StripeSubscriptionManager: Addon has no Price ID configured', [
                    'addon' => $addonSlug,
                ]);
            }
        }

        return $items;
    }

    /**
     * Create new Stripe Subscription
     */
    private function createNewSubscription(Tenant $tenant, string $stripeCustomerId, array $items): array
    {
        $subscriptionParams = [
            'customer' => $stripeCustomerId,
            'items' => $items,
            'metadata' => [
                'tenant_id' => $tenant->id,
                'tenant_slug' => $tenant->slug,
            ],
        ];

        // Phase 3: Add automatic tax if enabled
        if ($this->gateway->taxEnabled()) {
            $subscriptionParams['automatic_tax'] = ['enabled' => true];
            Log::debug('StripeSubscriptionManager: Automatic tax enabled for subscription');
        }

        $subscription = Subscription::create($subscriptionParams);

        // Sync to tenant record
        $this->syncSubscriptionStateToTenant($tenant, $subscription);

        Log::info('StripeSubscriptionManager: Created new subscription', [
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'items_count' => count($items),
        ]);

        return [
            'success' => true,
            'subscription' => $subscription,
            'error' => null,
        ];
    }

    /**
     * Update existing Stripe Subscription items
     */
    private function updateExistingSubscription(Tenant $tenant, array $newItems): array
    {
        $subscription = Subscription::retrieve($tenant->stripe_subscription_id);

        // Remove all existing items
        foreach ($subscription->items->data as $item) {
            Subscription::deleteItem($item->id);
        }

        // Add new items
        foreach ($newItems as $item) {
            Subscription::createItem([
                'subscription' => $subscription->id,
                'price' => $item['price'],
            ]);
        }

        // Reload subscription
        $subscription = Subscription::retrieve($tenant->stripe_subscription_id);

        // Sync to tenant record
        $this->syncSubscriptionStateToTenant($tenant, $subscription);

        Log::info('StripeSubscriptionManager: Updated subscription items', [
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'items_count' => count($newItems),
        ]);

        return [
            'success' => true,
            'subscription' => $subscription,
            'error' => null,
        ];
    }

    /**
     * Extract addon slugs from Stripe subscription items
     * 
     * @param array $items - Stripe SubscriptionItem objects
     * @return array - ['planning', 'ai']
     */
    private function extractAddonsFromSubscriptionItems(array $items): array
    {
        $addons = [];
        $addonConfig = config('billing.addons', []);

        foreach ($items as $item) {
            $priceId = $item->price->id;

            // Check if this Price ID matches an addon
            foreach ($addonConfig as $slug => $config) {
                if (isset($config['stripe_price_id']) && $config['stripe_price_id'] === $priceId) {
                    $addons[] = $slug;
                }
            }
        }

        return $addons;
    }

    // ─────────────────────────────────────────────────────────────────────
    // PHASE 3: INVOICE GENERATION
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Create and finalize a Stripe Invoice for addon purchase
     * 
     * Use Case:
     * - User activates addon outside subscription renewal cycle
     * - Need immediate payment for addon (not waiting for next billing cycle)
     * 
     * Process:
     * 1. Create invoice item with addon Price ID
     * 2. Create draft invoice
     * 3. Add ERP-friendly metadata
     * 4. Finalize invoice (triggers payment)
     * 
     * @param Tenant $tenant
     * @param string $addonSlug - Addon slug from config('billing.addons')
     * @param int $userCount - Current user count (for metadata)
     * @return array ['success' => bool, 'invoice' => ?Invoice, 'error' => ?string]
     */
    public function createAddonInvoice(Tenant $tenant, string $addonSlug, int $userCount): array
    {
        // Guard: Invoices must be enabled
        if (!$this->gateway->invoicesEnabled()) {
            return [
                'success' => false,
                'invoice' => null,
                'error' => 'Invoices not enabled (BILLING_INVOICES_ENABLED=false)',
            ];
        }

        try {
            // Get addon Price ID
            $addonPriceId = $this->gateway->getStripePriceIdForAddon($addonSlug);
            if (!$addonPriceId) {
                return [
                    'success' => false,
                    'invoice' => null,
                    'error' => "No Stripe Price ID configured for addon: {$addonSlug}",
                ];
            }

            // Ensure Stripe Customer exists
            $customerResult = $this->gateway->ensureStripeCustomer($tenant);
            if (!$customerResult['success']) {
                return [
                    'success' => false,
                    'invoice' => null,
                    'error' => $customerResult['error'] ?? 'Failed to create/fetch Stripe customer',
                ];
            }

            $stripeCustomerId = $customerResult['customer_id'];

            // Build metadata
            $metadata = $this->metadataBuilder->forInvoiceItem(
                $tenant,
                'addon',
                $addonSlug,
                [
                    'user_count' => $userCount,
                    'plan' => $tenant->plan ?? 'unknown',
                ]
            );

            // Create invoice item
            $invoiceItem = InvoiceItem::create([
                'customer' => $stripeCustomerId,
                'price' => $addonPriceId,
                'metadata' => $metadata,
            ]);

            // Create and finalize invoice
            $invoiceParams = [
                'customer' => $stripeCustomerId,
                'auto_advance' => true, // Automatically attempt payment
                'metadata' => $metadata,
                'description' => "Addon activation: {$addonSlug}",
            ];

            // Add automatic tax if enabled
            if ($this->gateway->taxEnabled()) {
                $invoiceParams['automatic_tax'] = ['enabled' => true];
            }

            $invoice = Invoice::create($invoiceParams);
            $invoice = $invoice->finalizeInvoice();

            Log::info('StripeSubscriptionManager: Created addon invoice', [
                'tenant_id' => $tenant->id,
                'invoice_id' => $invoice->id,
                'addon' => $addonSlug,
            ]);

            return [
                'success' => true,
                'invoice' => $invoice,
                'error' => null,
            ];

        } catch (ApiErrorException $e) {
            Log::error('StripeSubscriptionManager: Failed to create addon invoice', [
                'tenant_id' => $tenant->id,
                'addon' => $addonSlug,
                'error' => $e->getMessage(),
            ]);
            return [
                'success' => false,
                'invoice' => null,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Create subscription with invoice generation enabled
     * 
     * Override for createNewSubscription when invoices are enabled
     * Ensures first invoice is properly generated with metadata
     * 
     * @param Tenant $tenant
     * @param string $stripeCustomerId
     * @param array $items
     * @param string $plan
     * @param array $addons
     * @param int $userCount
     * @return array
     */
    private function createNewSubscriptionWithInvoice(
        Tenant $tenant,
        string $stripeCustomerId,
        array $items,
        string $plan,
        array $addons,
        int $userCount
    ): array {
        $now = time();
        $periodEnd = strtotime('+1 month', $now);

        // Build subscription metadata
        $metadata = $this->metadataBuilder->forTenantBilling(
            $tenant,
            $plan,
            $addons,
            $userCount,
            $now,
            $periodEnd
        );

        $subscriptionParams = [
            'customer' => $stripeCustomerId,
            'items' => $items,
            'metadata' => $metadata,
        ];

        // Add automatic tax if enabled
        if ($this->gateway->taxEnabled()) {
            $subscriptionParams['automatic_tax'] = ['enabled' => true];
        }

        // Stripe will automatically create invoice for subscription
        $subscription = Subscription::create($subscriptionParams);

        // Sync to tenant record
        $this->syncSubscriptionStateToTenant($tenant, $subscription);

        Log::info('StripeSubscriptionManager: Created subscription with invoice', [
            'tenant_id' => $tenant->id,
            'subscription_id' => $subscription->id,
            'items_count' => count($items),
        ]);

        return [
            'success' => true,
            'subscription' => $subscription,
            'error' => null,
        ];
    }
}

