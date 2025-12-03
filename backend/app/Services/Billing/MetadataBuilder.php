<?php

namespace App\Services\Billing;

use App\Models\Tenant;

/**
 * MetadataBuilder
 * 
 * Phase 3: ERP-Friendly Metadata Generator
 * 
 * Purpose:
 * - Generate consistent metadata for ALL Stripe objects
 * - Ensure ERP systems can reconcile transactions
 * - Support Portuguese tax law compliance (invoice tracking)
 * 
 * Metadata Standard:
 * - tenant_id: ULID identifier
 * - tenant_slug: Human-readable identifier
 * - plan: Current billing plan (starter, team, enterprise)
 * - addons: Comma-separated active addons (planning, ai)
 * - user_count: Active users at time of billing
 * - billing_period_start: Unix timestamp
 * - billing_period_end: Unix timestamp
 * 
 * Usage:
 * - StripeGateway (PaymentIntents, Charges)
 * - StripeSubscriptionManager (Subscriptions, SubscriptionItems)
 * - Invoice creation flows
 */
class MetadataBuilder
{
    /**
     * Generate ERP-friendly metadata for tenant billing operations
     * 
     * @param Tenant $tenant
     * @param string $plan - Plan slug (starter, team, enterprise)
     * @param array $addons - Array of addon slugs ['planning', 'ai']
     * @param int $userCount - Active user count at billing time
     * @param int|null $periodStart - Unix timestamp (null for immediate charges)
     * @param int|null $periodEnd - Unix timestamp (null for immediate charges)
     * @return array - Stripe-compatible metadata
     */
    public function forTenantBilling(
        Tenant $tenant,
        string $plan,
        array $addons = [],
        int $userCount = 0,
        ?int $periodStart = null,
        ?int $periodEnd = null
    ): array {
        $metadata = [
            // Primary identifiers
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            
            // Billing configuration
            'plan' => $plan,
            'addons' => $this->formatAddons($addons),
            'user_count' => (string) $userCount, // Stripe requires strings
            
            // Application context
            'app' => 'TimePerk',
            'environment' => config('app.env'),
        ];

        // Add billing period if provided (for subscriptions)
        if ($periodStart !== null) {
            $metadata['billing_period_start'] = (string) $periodStart;
        }

        if ($periodEnd !== null) {
            $metadata['billing_period_end'] = (string) $periodEnd;
        }

        return $metadata;
    }

    /**
     * Generate metadata for subscription-specific objects
     * 
     * Includes subscription ID for cross-referencing
     * 
     * @param Tenant $tenant
     * @param string $subscriptionId - Stripe subscription ID
     * @param string $plan
     * @param array $addons
     * @param int $userCount
     * @param int $periodStart
     * @param int $periodEnd
     * @return array
     */
    public function forSubscriptionBilling(
        Tenant $tenant,
        string $subscriptionId,
        string $plan,
        array $addons,
        int $userCount,
        int $periodStart,
        int $periodEnd
    ): array {
        $metadata = $this->forTenantBilling(
            $tenant,
            $plan,
            $addons,
            $userCount,
            $periodStart,
            $periodEnd
        );

        $metadata['subscription_id'] = $subscriptionId;

        return $metadata;
    }

    /**
     * Generate metadata for one-time charges (PaymentIntents)
     * 
     * Used for:
     * - Initial plan purchases
     * - One-time addon fees
     * - Legacy payment flows
     * 
     * @param Tenant $tenant
     * @param string $plan
     * @param array $addons
     * @param int $userCount
     * @param string $chargeType - 'plan_purchase', 'addon_purchase', 'upgrade', 'downgrade'
     * @return array
     */
    public function forOneTimeCharge(
        Tenant $tenant,
        string $plan,
        array $addons,
        int $userCount,
        string $chargeType = 'plan_purchase'
    ): array {
        $metadata = $this->forTenantBilling($tenant, $plan, $addons, $userCount);

        $metadata['charge_type'] = $chargeType;
        $metadata['charge_timestamp'] = (string) time();

        return $metadata;
    }

    /**
     * Generate metadata for invoice items
     * 
     * Used when adding line items to invoices
     * (e.g., addon activation outside subscription)
     * 
     * @param Tenant $tenant
     * @param string $itemType - 'addon', 'adjustment', 'credit'
     * @param string $itemName - Human-readable name
     * @param array $additionalData - Extra context
     * @return array
     */
    public function forInvoiceItem(
        Tenant $tenant,
        string $itemType,
        string $itemName,
        array $additionalData = []
    ): array {
        $metadata = [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
            'item_type' => $itemType,
            'item_name' => $itemName,
            'app' => 'TimePerk',
        ];

        // Merge additional data
        foreach ($additionalData as $key => $value) {
            $metadata[$key] = is_array($value) ? json_encode($value) : (string) $value;
        }

        return $metadata;
    }

    /**
     * Extract tenant context from Stripe metadata
     * 
     * Reverse operation - parse metadata from webhook events
     * 
     * @param array $metadata - Stripe object metadata
     * @return array - ['tenant_id', 'tenant_slug', 'plan', 'addons', ...]
     */
    public function parseMetadata(array $metadata): array
    {
        return [
            'tenant_id' => $metadata['tenant_id'] ?? null,
            'tenant_slug' => $metadata['tenant_slug'] ?? null,
            'plan' => $metadata['plan'] ?? null,
            'addons' => $this->parseAddons($metadata['addons'] ?? ''),
            'user_count' => isset($metadata['user_count']) ? (int) $metadata['user_count'] : 0,
            'billing_period_start' => isset($metadata['billing_period_start']) 
                ? (int) $metadata['billing_period_start'] 
                : null,
            'billing_period_end' => isset($metadata['billing_period_end']) 
                ? (int) $metadata['billing_period_end'] 
                : null,
        ];
    }

    // ─────────────────────────────────────────────────────────────────────
    // PRIVATE HELPERS
    // ─────────────────────────────────────────────────────────────────────

    /**
     * Format addons array to comma-separated string
     * 
     * @param array $addons - ['planning', 'ai']
     * @return string - 'planning,ai' or '' if empty
     */
    private function formatAddons(array $addons): string
    {
        return empty($addons) ? '' : implode(',', $addons);
    }

    /**
     * Parse comma-separated addons string to array
     * 
     * @param string $addons - 'planning,ai'
     * @return array - ['planning', 'ai']
     */
    private function parseAddons(string $addons): array
    {
        if (empty($addons)) {
            return [];
        }

        return explode(',', $addons);
    }
}
