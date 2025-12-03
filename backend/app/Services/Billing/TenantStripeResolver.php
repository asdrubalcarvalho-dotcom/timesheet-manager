<?php

namespace App\Services\Billing;

use App\Models\Tenant;
use Illuminate\Support\Facades\Log;
use Exception;

/**
 * TenantStripeResolver
 * 
 * Phase 10: Resolve tenant from Stripe webhook objects
 * 
 * Extracts tenant_id from Stripe metadata and resolves the Tenant model.
 */
class TenantStripeResolver
{
    /**
     * Resolve tenant from Stripe object metadata
     * 
     * @param object $stripeObject Stripe PaymentIntent, Customer, etc.
     * @return Tenant
     * @throws Exception if tenant_id missing or tenant not found
     */
    public function resolveFromStripeObject($stripeObject): Tenant
    {
        // Extract tenant_id from metadata
        $tenantId = $stripeObject->metadata->tenant_id ?? null;
        
        if (!$tenantId) {
            Log::error('[TenantStripeResolver] Missing tenant_id in Stripe metadata', [
                'object_type' => $stripeObject->object ?? 'unknown',
                'object_id' => $stripeObject->id ?? 'unknown',
            ]);
            throw new Exception('Missing tenant_id in Stripe metadata');
        }
        
        // Find tenant
        $tenant = Tenant::find($tenantId);
        
        if (!$tenant) {
            Log::error('[TenantStripeResolver] Tenant not found', [
                'tenant_id' => $tenantId,
                'stripe_object_id' => $stripeObject->id ?? 'unknown',
            ]);
            throw new Exception("Tenant not found: {$tenantId}");
        }
        
        Log::info('[TenantStripeResolver] Tenant resolved successfully', [
            'tenant_id' => $tenant->id,
            'tenant_slug' => $tenant->slug,
        ]);
        
        return $tenant;
    }
}
