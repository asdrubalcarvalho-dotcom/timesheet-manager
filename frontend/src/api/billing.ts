import api from '../services/api';

export type FeatureFlagValue = boolean | {
  enabled: boolean;
  entitled?: boolean;
  toggle?: boolean;
};

export type FeatureEntitlements = Record<string, boolean | undefined>;
export type FeatureToggles = Record<string, boolean | undefined>;

/**
 * Billing Summary Response from Backend
 * Source: GET /api/billing/summary (PlanManager::getSubscriptionSummary)
 * 
 * All pricing/features calculated by backend PriceCalculator.
 * Frontend never does billing calculations - single source of truth.
 */
export interface BillingSummary {
  // Plan identification
  plan: 'starter' | 'team' | 'enterprise' | 'trial_enterprise';
  is_trial: boolean;
  user_count: number;
  user_limit: number;

  // Pricing (all from backend)
  base_subtotal: number;
  addons_subtotal: number;
  addons: {
    planning: number;
    ai: number;
  };
  total: number;
  requires_upgrade: boolean;

  // Feature flags (from backend PriceCalculator)
  features: {
    timesheets: boolean;
    expenses: boolean;
    travels: boolean;
    planning: boolean;
    ai: FeatureFlagValue;
  };

  // Feature entitlements & toggles (optional metadata returned by backend)
  entitlements?: FeatureEntitlements;
  toggles?: FeatureToggles;

  // Trial metadata (only present if is_trial=true)
  trial?: {
    ends_at: string | null;
  };

  // Subscription metadata (added by PlanManager)
  subscription?: {
    id: number;
    status: string;
    subscription_start_date: string | null;
    next_renewal_at: string | null;
    created_at: string;
  } | null;

  // Pending downgrade info (if scheduled)
  pending_downgrade?: {
    target_plan: string;
    target_user_limit: number;
    effective_at: string | null;
  };

  // Cancellation availability
  can_cancel_downgrade: boolean;
}

/**
 * Upgrade Plan Request Payload
 */
export interface UpgradePlanRequest {
  plan: 'starter' | 'team' | 'enterprise';
  user_limit: number;
}

/**
 * Toggle Addon Request Payload
 */
export interface ToggleAddonRequest {
  addon: 'planning' | 'ai';
}

/**
 * Checkout Session Response
 */
export interface CheckoutSession {
  session_id: string;
  amount: number;
  currency: string;
}

/**
 * Checkout Confirmation Request
 */
export interface CheckoutConfirmRequest {
  card_number: string;
  card_expiry?: string;
  card_cvc?: string;
  mode: 'plan' | 'addon' | 'users' | 'licenses';
  plan?: string;
  addon?: string;
  user_limit?: number;
}

/**
 * Checkout Confirmation Response
 */
export interface CheckoutConfirmResponse {
  success: boolean;
  payment_id?: string;
  message?: string;
}

/**
 * Normalized API Error
 */
export interface BillingError {
  message: string;
  errors?: Record<string, string[]>;
  statusCode?: number;
}

/**
 * GET /api/billing/summary
 * Fetches current billing summary including plan, modules, pricing
 */
export async function getBillingSummary(): Promise<BillingSummary> {
  try {
    const response = await api.get<{ success: boolean; data: BillingSummary }>('/api/billing/summary', {
      headers: {
        'Cache-Control': 'no-cache, no-store, must-revalidate',
        'Pragma': 'no-cache',
        'Expires': '0'
      },
      // Force cache bypass with timestamp
      params: {
        _t: Date.now()
      }
    });
    return response.data.data;
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * POST /api/billing/upgrade-plan
 * Requests plan upgrade (creates pending payment)
 */
export async function upgradePlan(
  plan: 'starter' | 'team' | 'enterprise',
  userLimit: number
): Promise<void> {
  try {
    await api.post('/api/billing/upgrade-plan', { 
      plan,
      user_limit: userLimit
    });
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * POST /api/billing/toggle-addon
 * Toggles an addon (planning/ai) on or off
 */
export async function toggleAddon(addon: 'planning' | 'ai'): Promise<void> {
  try {
    await api.post('/api/billing/toggle-addon', { addon });
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * POST /api/billing/schedule-downgrade
 * Schedules a downgrade for the next billing cycle
 * SPECIAL: If subscription is_trial=true, applies immediately (trial → paid conversion)
 */
export async function scheduleDowngrade(
  plan: 'starter' | 'team' | 'enterprise',
  userLimit?: number
): Promise<{
  success: boolean;
  message: string;
  effective_at?: string | null;
  current_plan?: string;
  next_plan?: string;
  is_immediate?: boolean; // true for trial exits
  plan?: string;
  user_limit?: number;
  subscription_start_date?: string;
  next_renewal_at?: string;
  is_trial?: boolean;
}> {
  try {
    const response = await api.post('/api/billing/schedule-downgrade', {
      plan,
      user_limit: userLimit
    });
    
    // Validate success field in response (backend may return 200 with success: false)
    if (!response.data.success) {
      throw {
        response: {
          status: 400,
          data: response.data
        }
      };
    }
    
    return response.data;
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * POST /api/billing/cancel-scheduled-downgrade
 * Cancels a previously scheduled downgrade (must be >24h before renewal)
 */
export async function cancelScheduledDowngrade(): Promise<{
  success: boolean;
  message: string;
  current_plan: string;
}> {
  try {
    const response = await api.post('/api/billing/cancel-scheduled-downgrade');
    return response.data;
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * POST /api/billing/checkout/start
 * Initializes checkout session and returns session details
 * Accepts optional params to calculate NEW plan pricing instead of current plan
 */
export async function startCheckout(params?: {
  mode?: 'plan' | 'addon' | 'users' | 'licenses';
  plan?: 'starter' | 'team' | 'enterprise';
  addon?: 'planning' | 'ai';
  user_limit?: number;
}): Promise<CheckoutSession> {
  try {
    const response = await api.post<CheckoutSession>('/api/billing/checkout/start', params || {});
    return response.data;
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * POST /api/billing/checkout/confirm
 * Confirms checkout with payment details (fake payment for Phase 1.5)
 */
export async function confirmCheckout(data: CheckoutConfirmRequest): Promise<CheckoutConfirmResponse> {
  try {
    const response = await api.post<CheckoutConfirmResponse>('/api/billing/checkout/confirm', data);
    return response.data;
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * Payment Method interfaces
 */
export interface PaymentMethod {
  id: string;
  type: string;
  card: {
    brand: string;
    last4: string;
    exp_month: number;
    exp_year: number;
  };
  is_default: boolean;
}

/**
 * GET /api/billing/payment-methods
 * List all payment methods for current tenant
 */
export async function getPaymentMethods(): Promise<PaymentMethod[]> {
  try {
    const response = await api.get<{ success: boolean; data: PaymentMethod[] }>(
      '/api/billing/payment-methods'
    );
    return response.data.data;
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * POST /api/billing/payment-methods/add
 * Add a new payment method (card) to tenant
 */
export async function addPaymentMethod(paymentMethodId: string): Promise<PaymentMethod> {
  try {
    const response = await api.post<{ success: boolean; data: PaymentMethod }>(
      '/api/billing/payment-methods/add',
      { payment_method_id: paymentMethodId }
    );
    return response.data.data;
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * POST /api/billing/payment-methods/default
 * Set a payment method as default
 */
export async function setDefaultPaymentMethod(paymentMethodId: string): Promise<void> {
  try {
    await api.post('/api/billing/payment-methods/default', {
      payment_method_id: paymentMethodId
    });
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * DELETE /api/billing/payment-methods/{id}
 * Remove a payment method
 */
export async function removePaymentMethod(paymentMethodId: string): Promise<void> {
  try {
    await api.delete(`/api/billing/payment-methods/${paymentMethodId}`);
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * GET /api/billing/portal
 * Get Stripe Customer Portal URL for tenant
 * 
 * Phase 4: Customer Portal Integration
 * Returns a unique portal session URL for the tenant to manage:
 * - Subscription details
 * - Payment methods
 * - Billing history
 * - Invoice downloads
 */
export async function getCustomerPortalUrl(): Promise<string> {
  try {
    const response = await api.get<{ success: boolean; url: string }>('/api/billing/portal');
    return response.data.url;
  } catch (error: any) {
    throw normalizeBillingError(error);
  }
}

/**
 * Normalize backend errors to consistent format
 * @private
 */
function normalizeBillingError(error: any): BillingError {
  if (error.response) {
    // Backend returned an error response
    return {
      message: error.response.data?.message || 'Billing operation failed',
      errors: error.response.data?.errors,
      statusCode: error.response.status,
    };
  } else if (error.request) {
    // Request was made but no response received
    return {
      message: 'Network error - unable to reach billing server',
      statusCode: 0,
    };
  } else {
    // Something else happened
    return {
      message: error.message || 'Unknown billing error',
    };
  }
}
