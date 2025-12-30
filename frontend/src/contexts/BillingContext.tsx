/**
 * ============================================================================
 * BILLING CONTEXT — CRITICAL BUSINESS LOGIC (COMMENTS-ONLY GUARD)
 * ============================================================================
 *
 * ⚠️ DO NOT REFACTOR, SIMPLIFY, OR "CLEAN UP" THIS FILE WITHOUT FULL BILLING CONTEXT.
 *
 * This context is the SINGLE orchestration layer between:
 * - Frontend UI (BillingPage, CheckoutModal, Add-on toggles)
 * - Backend billing APIs (/api/billing/*)
 * - Stripe checkout flows (plan, addon, license increment)
 *
 * ---------------------------------------------------------------------------
 * CORE SEMANTICS (MUST NOT CHANGE)
 * ---------------------------------------------------------------------------
 *
 * 1) user_limit vs user_count
 * ---------------------------------------------------------------------------
 * - user_limit:
 *   = PURCHASED / BILLABLE LICENSES
 *   = Comes from backend subscription.user_limit
 *   = Used for ALL pricing, checkout, proration, and enforcement
 *
 * - user_count:
 *   = ACTIVE USERS (technicians.is_active = true)
 *   = DISPLAY-ONLY metric
 *   = NEVER used for pricing or billing calculations
 *
 * If you see both together, this is INTENTIONAL.
 *
 * ---------------------------------------------------------------------------
 * 2) Trial behavior (trial_enterprise)
 * ---------------------------------------------------------------------------
 * - trial_enterprise is a VIRTUAL plan (API-level only)
 * - DB stores: plan = 'enterprise', is_trial = true
 * - API returns: plan = 'trial_enterprise'
 * - Frontend must treat trial_enterprise as Enterprise-equivalent
 *
 * This transformation happens in the backend PriceCalculator and MUST
 * be mirrored correctly here.
 *
 * ---------------------------------------------------------------------------
 * 3) Add-ons vs Tenant Toggles (AI)
 * ---------------------------------------------------------------------------
 * AI has THREE layers of control:
 *
 *   a) Billing entitlement  → billingSummary.entitlements.ai
 *   b) Billing addon price  → billingSummary.addons.ai > 0
 *   c) Tenant preference   → tenant.ai_enabled (stored separately)
 *
 * FINAL availability = entitlement AND tenant toggle.
 *
 * IMPORTANT:
 * - Billing addon toggle controls PAYMENT / ENTITLEMENT
 * - Tenant AI toggle controls FEATURE USAGE
 * - One-way enforcement exists: Billing OFF → Tenant OFF
 *
 * This separation is INTENTIONAL.
 *
 * ---------------------------------------------------------------------------
 * 4) Checkout modes (DO NOT MERGE)
 * ---------------------------------------------------------------------------
 * Checkout supports multiple explicit modes:
 * - 'plan'     → plan upgrade / trial exit
 * - 'addon'    → planning / AI addon activation
 * - 'licenses' → license increment ONLY (no plan change)
 *
 * These modes MUST remain distinct.
 * Do NOT infer mode implicitly or "simplify" the API.
 *
 * ---------------------------------------------------------------------------
 * 5) Backend is the source of truth
 * ---------------------------------------------------------------------------
 * - NO pricing math is allowed here
 * - NO license math beyond clamping UI inputs
 * - ALL totals, subtotals, proration come from backend
 *
 * If logic here feels redundant, it is probably protecting billing integrity.
 *
 * ---------------------------------------------------------------------------
 * TL;DR
 * ---------------------------------------------------------------------------
 * This file is intentionally explicit, verbose, and defensive.
 * Any refactor here risks BILLING, STRIPE, or TRIAL BREAKAGE.
 *
 * ============================================================================
 */
import React, { createContext, useContext, useState, useCallback, useEffect, useRef } from 'react';
import {
  getBillingSummary,
  upgradePlan,
  toggleAddon as toggleAddonApi,
  startCheckout as startCheckoutApi,
  confirmCheckout as confirmCheckoutApi,
  scheduleDowngrade as scheduleDowngradeApi,
  cancelScheduledDowngrade as cancelScheduledDowngradeApi,
} from '../api/billing';
import { tenantApi } from '../services/api';
import type { BillingSummary, CheckoutSession, BillingError } from '../api/billing';
import type { BillingContextValue } from '../types/billing';
import { useAuth } from '../components/Auth/AuthContext'; // Correct path to AuthContext
import { useNotification } from './NotificationContext';

/**
 * BillingContext - Centralized billing state management
 * v2.0.1 - Checkout payload fix
 * 
 * CRITICAL RULES:
 * - NO price calculations in this context
 * - ALL pricing data comes from backend /api/billing/summary
 * - State is single source of truth from backend
 * - Mutations trigger auto-refresh from backend
 */

const BillingContext = createContext<BillingContextValue | undefined>(undefined);

export const BillingProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user } = useAuth(); // Get auth state
  const { showError, showSuccess } = useNotification();
  const [billingSummary, setBillingSummary] = useState<BillingSummary | null>(null);
  const [tenantAiEnabled, setTenantAiEnabled] = useState<boolean>(false);
  const [loading, setLoading] = useState<boolean>(false);
  const [initializing, setInitializing] = useState<boolean>(true);
  const [error, setError] = useState<string | null>(null);
  const [checkoutState, setCheckoutState] = useState<{
    open: boolean;
    paymentId?: number;
    mode: 'plan' | 'addon' | 'users' | 'licenses';
    plan?: 'starter' | 'team' | 'enterprise';
    addon?: 'planning' | 'ai';
    userLimit?: number;
    session?: CheckoutSession;
  }>({
    open: false,
    mode: 'plan',
  });
  const firstLoadRef = useRef(true);

  /**
   * Bootstrap AI toggle state from authenticated tenant metadata.
   * This runs whenever the tenant data in AuthContext changes and ensures
   * the toggle isn't overridden by billing summary responses.
   */
  useEffect(() => {
    if (typeof user?.tenant?.ai_enabled === 'boolean') {
      setTenantAiEnabled(Boolean(user.tenant.ai_enabled));
    }
  }, [user?.tenant?.ai_enabled]);

  /**
   * Fetch billing summary from backend
   * Called on mount and after mutations
   */
  const refreshSummary = useCallback(async () => {
    console.log('[BillingContext] 🔄 refreshSummary STARTED');
    setLoading(true);
    setError(null);

    try {
      console.log('[BillingContext] 📡 Calling getBillingSummary API...');
      const summary = await getBillingSummary();
      console.log('[BillingContext] ✅ API response received:', summary);
      // Keep local read-only marker in sync with backend source-of-truth
      if (summary?.read_only === true) {
        localStorage.setItem('tenant_read_only_mode', '1');
      } else {
        localStorage.removeItem('tenant_read_only_mode');
      }
      setBillingSummary(summary);
      console.log('[BillingContext] ✅ State updated with new summary');
    } catch (err) {
      const billingError = err as BillingError;
      setError(billingError.message);
      console.error('[BillingContext] ❌ Failed to fetch summary:', billingError);
    } finally {
      setLoading(false);
      console.log('[BillingContext] 🔄 refreshSummary COMPLETED');
      if (firstLoadRef.current) {
        firstLoadRef.current = false;
        setInitializing(false);
      }
    }
  }, []);

  /**
   * Request plan upgrade
   * Creates pending payment on backend, then refreshes summary
   */
  const requestUpgradePlan = useCallback(
    async (plan: 'starter' | 'team' | 'enterprise') => {
      console.log('[BillingContext] 🚀 requestUpgradePlan STARTED - plan:', plan);
      setLoading(true);
      setError(null);

      try {
        // CRITICAL: Always use subscription.user_limit for billing calculations
        // This is the number of purchased licenses, not active users
        const userLimit = billingSummary?.user_limit ?? 2;
        
        console.log('[BillingContext] 📊 Upgrade params - plan:', plan, 'userLimit:', userLimit);
        
        console.log('[BillingContext] 📡 Calling upgradePlan API...');
        await upgradePlan(plan, userLimit);
        console.log('[BillingContext] ✅ upgradePlan API completed successfully');
        
        // Auto-refresh to get updated summary from backend
        console.log('[BillingContext] 📡 Calling refreshSummary after upgrade...');
        await refreshSummary();
        console.log('[BillingContext] ✅ requestUpgradePlan COMPLETED successfully');
        
        showSuccess(`Plan upgraded to ${plan.charAt(0).toUpperCase() + plan.slice(1)} successfully!`);
      } catch (err) {
        const billingError = err as BillingError;
        console.error('[BillingContext] ❌ Failed to upgrade plan:', billingError);
        showError(billingError.message || 'Failed to upgrade plan. Please try again.');
        // Note: Do not throw - error is already shown via toast
        // setError is not needed since we're using showError toast
      } finally {
        setLoading(false);
        console.log('[BillingContext] 🔄 requestUpgradePlan FINISHED (loading=false)');
      }
    },
    [refreshSummary, billingSummary]
  );

  /**
   * Toggle addon (planning or ai)
   * Updates backend, then refreshes summary
   */
  const toggleAddon = useCallback(
    async (addon: 'planning' | 'ai') => {
      setLoading(true);
      setError(null);

      try {
        await toggleAddonApi(addon);
        // Auto-refresh to get updated pricing and modules_enabled
        await refreshSummary();
        showSuccess('Add-on updated successfully.');
      } catch (err) {
        const billingError = err as BillingError;
        console.error('[BillingContext] Failed to toggle addon:', billingError);
        showError(billingError.message || 'Failed to update add-on. Please try again.');
        // Note: Do not throw - error is already shown via toast
      } finally {
        setLoading(false);
      }
    },
    [refreshSummary]
  );

  const updateTenantAiToggle = useCallback(
    async (aiEnabled: boolean) => {
      const previousTenantToggle = tenantAiEnabled;

      try {
        const response = await tenantApi.updateAiToggle(aiEnabled);
        const serverToggle = Boolean(response?.tenant?.ai_enabled ?? aiEnabled);
        setTenantAiEnabled(serverToggle);
        await refreshSummary();
        showSuccess(serverToggle ? 'AI suggestions enabled for this tenant.' : 'AI suggestions disabled for this tenant.');
      } catch (error) {
        setTenantAiEnabled(previousTenantToggle);
        const message = error instanceof Error ? error.message : 'Failed to update AI preferences. Please try again.';
        console.error('[BillingContext] Failed to update tenant AI toggle:', error);
        showError(message);
        throw error;
      }
    },
    [refreshSummary, showError, showSuccess, tenantAiEnabled]
  );

  /**
   * Start checkout session
   * Returns session details without refreshing (no state change yet)
   */
  const startCheckout = useCallback(async (): Promise<CheckoutSession> => {
    setLoading(true);
    setError(null);

    try {
      const session = await startCheckoutApi();
      return session;
    } catch (err) {
      const billingError = err as BillingError;
      setError(billingError.message);
      console.error('[BillingContext] Failed to start checkout:', billingError);
      throw billingError;
    } finally {
      setLoading(false);
    }
  }, []);

  /**
   * Confirm checkout with card details
   * On success, refreshes summary to reflect new plan/modules
   */
  const confirmCheckout = useCallback(
    async (cardNumber: string, checkoutData: { mode: 'plan' | 'addon' | 'users' | 'licenses'; plan?: string; addon?: string; userLimit?: number }) => {
      setLoading(true);
      setError(null);

      try {
        const payload = { 
          card_number: cardNumber,
          mode: checkoutData.mode,
          plan: checkoutData.plan,
          addon: checkoutData.addon,
          user_limit: checkoutState.userLimit ?? checkoutData.userLimit
        };
        
        console.log('[BillingContext] confirmCheckout payload:', payload);
        
        const result = await confirmCheckoutApi(payload);
        
        if (!result.success) {
          throw new Error(result.message || 'Checkout failed');
        }

        // Auto-refresh to get updated plan and modules after successful payment
        await refreshSummary();
        showSuccess('Payment confirmed successfully!');
      } catch (err) {
        const billingError = err as BillingError;
        console.error('[BillingContext] Failed to confirm checkout:', billingError);
        showError(billingError.message || 'Payment failed. Please try again.');
        // Note: Do not throw - error is already shown via toast
      } finally {
        setLoading(false);
      }
    },
    [refreshSummary, showSuccess, showError, checkoutState.userLimit]
  );

  /**
   * Open checkout modal for plan upgrade
   * Calls startCheckout() and stores session in state
   */
  const openCheckoutForPlan = useCallback(
    async (plan: 'starter' | 'team' | 'enterprise', userLimit?: number) => {
      setLoading(true);
      try {
        const session = await startCheckoutApi({
          mode: 'plan',
          plan,
          user_limit: userLimit
        });
        setCheckoutState({
          open: true,
          mode: 'plan',
          plan,
          ...(userLimit ? { userLimit } : {}),
          session,
        });
      } catch (err) {
        const billingError = err as BillingError;
        showError(billingError.message || 'Failed to start checkout');
      } finally {
        setLoading(false);
      }
    },
    [showError]
  );

  /**
   * Open checkout modal for addon activation
   * Calls startCheckout() and stores session in state
   */
  const openCheckoutForAddon = useCallback(
    async (addon: 'planning' | 'ai') => {
      setLoading(true);
      try {
        const session = await startCheckoutApi({
          mode: 'addon',
          addon
        });
        setCheckoutState({
          open: true,
          mode: 'addon',
          addon,
          session,
        });
      } catch (err) {
        const billingError = err as BillingError;
        showError(billingError.message || 'Failed to start checkout');
      } finally {
        setLoading(false);
      }
    },
    [showError]
  );

  /**
   * Open checkout modal for license increment
   * ONLY for adding licenses - does NOT change plan
   * Calculates prorated delta charge automatically
   */
  const openCheckoutForLicenses = useCallback(
    async (newUserLimit: number) => {
      setLoading(true);
      try {
        const session = await startCheckoutApi({
          mode: 'licenses',
          user_limit: newUserLimit
        });
        setCheckoutState({
          open: true,
          mode: 'licenses',
          userLimit: newUserLimit,
          session,
        });
      } catch (err) {
        const billingError = err as BillingError;
        showError(billingError.message || 'Failed to start checkout');
      } finally {
        setLoading(false);
      }
    },
    [showError]
  );

  /**
   * Close checkout modal and reset state
   * Does NOT call backend - simply closes UI
   */
  const closeCheckoutModal = useCallback(() => {
    setCheckoutState({
      open: false,
      mode: 'plan',
    });
  }, []);

  /**
   * Schedule downgrade for next billing cycle OR immediate trial exit
   * 
   * SPECIAL: If user is in trial, triggers IMMEDIATE conversion (not scheduled)
   * Otherwise: schedules downgrade for next billing cycle (no payment required)
   */
  const scheduleDowngrade = useCallback(
    async (plan: 'starter' | 'team' | 'enterprise', userLimit?: number) => {
      setLoading(true);
      try {
        const result = await scheduleDowngradeApi(plan, userLimit);
        showSuccess(result.message);
        
        // Refresh summary to show pending downgrade OR immediate trial conversion
        await refreshSummary();
      } catch (err) {
        const billingError = err as BillingError;
        showError(billingError.message || 'Failed to schedule downgrade');
      } finally {
        setLoading(false);
      }
    },
    [refreshSummary, showSuccess, showError]
  );

  /**
   * Cancel a scheduled downgrade
   * Only allowed if >24h before next renewal
   */
  const cancelDowngrade = useCallback(
    async () => {
      setLoading(true);
      try {
        const result = await cancelScheduledDowngradeApi();
        showSuccess(result.message);
        
        // Refresh summary to remove pending downgrade indicator
        await refreshSummary();
      } catch (err) {
        const billingError = err as BillingError;
        showError(billingError.message || 'Failed to cancel downgrade');
      } finally {
        setLoading(false);
      }
    },
    [refreshSummary, showSuccess, showError]
  );

  /**
   * Fetch billing summary on mount (only when authenticated)
   */
  useEffect(() => {
    // Only fetch billing if user is authenticated
    if (user) {
      console.log('[BillingContext] User authenticated, fetching billing summary');
      firstLoadRef.current = true;
      setInitializing(true);
      refreshSummary();
    } else {
      console.log('[BillingContext] User not authenticated, skipping billing fetch');
      setBillingSummary(null);
      setTenantAiEnabled(false);
      setInitializing(false);
    }
  }, [user, refreshSummary]);


  const value: BillingContextValue = {
    // State (read-only from backend)
    billingSummary,
    tenantAiEnabled,
    loading,
    initializing,
    error,
    checkoutState,

    // Actions (all trigger backend calls + refresh)
    refreshSummary,
    requestUpgradePlan,
    requestDowngrade: scheduleDowngrade,
    cancelDowngrade,
    toggleAddon,
    updateTenantAiToggle,
    startCheckout,
    confirmCheckout,
    
    // Checkout Modal Actions
    openCheckoutForPlan,
    openCheckoutForAddon,
    openCheckoutForLicenses,
    closeCheckoutModal,
  };

  return <BillingContext.Provider value={value}>{children}</BillingContext.Provider>;
};

/**
 * Hook to access BillingContext
 * Throws error if used outside BillingProvider
 */
export const useBilling = (): BillingContextValue => {
  const context = useContext(BillingContext);
  
  if (context === undefined) {
    throw new Error('useBilling must be used within a BillingProvider');
  }
  
  return context;
};

export default BillingContext;
