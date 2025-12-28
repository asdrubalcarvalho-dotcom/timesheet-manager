/**
 * Billing Types
 * 
 * Re-export types from api/billing.ts and add context-specific types
 */

export type {
  BillingSummary,
  UpgradePlanRequest,
  ToggleAddonRequest,
  CheckoutSession,
  CheckoutConfirmRequest,
  CheckoutConfirmResponse,
  BillingError,
} from '../api/billing';

/**
 * Checkout Modal State
 */
export interface CheckoutState {
  open: boolean;
  paymentId?: number;
  mode: 'plan' | 'addon' | 'users' | 'licenses';
  plan?: 'starter' | 'team' | 'enterprise';
  addon?: 'planning' | 'ai';
  userLimit?: number;
  session?: CheckoutSession;
}

/**
 * BillingContext State Shape
 */
export interface BillingContextValue {
  // State
  billingSummary: BillingSummary | null;
  tenantAiEnabled: boolean;
  loading: boolean;
  initializing: boolean;
  error: string | null;
  checkoutState: CheckoutState;

  // Actions
  refreshSummary: () => Promise<void>;
  requestUpgradePlan: (plan: 'starter' | 'team' | 'enterprise') => Promise<void>;
  requestDowngrade: (plan: 'starter' | 'team' | 'enterprise', userLimit?: number) => Promise<void>;
  cancelDowngrade: () => Promise<void>;
  toggleAddon: (addon: 'planning' | 'ai') => Promise<void>;
  updateTenantAiToggle: (enabled: boolean) => Promise<void>;
  startCheckout: () => Promise<CheckoutSession>;
  confirmCheckout: (
    cardNumber: string, 
    checkoutData: { 
      mode: 'plan' | 'addon' | 'users' | 'licenses'; 
      plan?: string; 
      addon?: string; 
      userLimit?: number 
    }
  ) => Promise<void>;
  
  // Checkout Modal Actions
  openCheckoutForPlan: (plan: 'starter' | 'team' | 'enterprise', userLimit?: number) => Promise<void>;
  openCheckoutForAddon: (addon: 'planning' | 'ai') => Promise<void>;
  openCheckoutForLicenses: (newUserLimit: number) => Promise<void>;
  closeCheckoutModal: () => void;
}

/**
 * FeatureContext State Shape (Read-only computed flags)
 */
export interface FeatureContextValue {
  hasTravels: boolean;
  hasPlanning: boolean;
  hasAI: boolean;
  enabledModules: string[];
  loading: boolean;
}

import type { BillingSummary, CheckoutSession } from '../api/billing';
