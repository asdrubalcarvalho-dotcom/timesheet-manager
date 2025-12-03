import React, { createContext, useContext, useMemo } from 'react';
import { useBilling } from './BillingContext';

/**
 * FeatureContext - Read-only computed feature flags
 * 
 * Derives module availability from BillingContext.billingSummary.features
 * This context is READ-ONLY - all mutations happen via BillingContext
 * 
 * Architecture:
 * - BillingContext = State + Mutations (plan, addons, checkout)
 * - FeatureContext = Computed Flags (hasTravels, hasPlanning, hasAI)
 * - Single Source of Truth = Backend /api/billing/summary
 */

export interface FeatureContextValue {
  /**
   * Whether Travels module is enabled for current tenant
   * Computed from billingSummary.features.travels
   */
  hasTravels: boolean;

  /**
   * Whether Planning module is enabled for current tenant
   * Computed from billingSummary.features.planning
   */
  hasPlanning: boolean;

  /**
   * Whether AI module is enabled for current tenant
   * Computed from billingSummary.features.ai
   */
  hasAI: boolean;

  /**
   * Raw modules array from backend (for debugging/inspection)
   */
  enabledModules: string[];

  /**
   * Loading state from BillingContext
   */
  loading: boolean;
}

const FeatureContext = createContext<FeatureContextValue | undefined>(undefined);

export const FeatureProvider: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { billingSummary, loading } = useBilling();

  // Compute feature flags from billingSummary.features
  const value: FeatureContextValue = useMemo(() => {
    const features = billingSummary?.features || {
      timesheets: false,
      expenses: false,
      travels: false,
      planning: false,
      ai: false,
    };

    return {
      hasTravels: !!features.travels,
      hasPlanning: !!features.planning,
      hasAI: !!features.ai,
      enabledModules: Object.keys(features).filter((f) => features[f as keyof typeof features] === true),
      loading,
    };
  }, [billingSummary?.features, loading]);

  return <FeatureContext.Provider value={value}>{children}</FeatureContext.Provider>;
};

/**
 * Hook to access feature flags
 * 
 * @throws Error if used outside FeatureProvider
 * 
 * @example
 * const { hasTravels, hasPlanning, hasAI } = useFeatures();
 * 
 * // In components:
 * if (hasTravels) {
 *   return <TravelsPage />;
 * }
 * 
 * // In sidebar:
 * {hasTravels && <MenuItem>Travels</MenuItem>}
 */
export const useFeatures = (): FeatureContextValue => {
  const context = useContext(FeatureContext);
  if (context === undefined) {
    throw new Error('useFeatures must be used within a FeatureProvider');
  }
  return context;
};

/**
 * @deprecated Old feature flag patterns - use useFeatures() instead
 * 
 * Migration guide:
 * - hasPermission('view-travels') → useFeatures().hasTravels
 * - hasPermission('view-planning') → useFeatures().hasPlanning
 * - hasPermission('view-ai') → useFeatures().hasAI
 * 
 * Note: Permission checks still required for CRUD operations,
 * but module visibility should use FeatureContext flags
 */
export const DeprecatedFeatureFlags = {
  __MIGRATION_GUIDE__: 'Use useFeatures() hook instead of hardcoded permission checks for module visibility',
};
