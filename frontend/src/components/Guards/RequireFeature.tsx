import React from 'react';
import { useFeatures } from '../../contexts/FeatureContext';
import LockedModuleBanner from './LockedModuleBanner';

interface RequireFeatureProps {
  /**
   * Feature flag to check (hasTravels, hasPlanning, hasAI)
   */
  feature: 'travels' | 'planning' | 'ai';
  
  /**
   * Children to render if feature is enabled
   */
  children: React.ReactNode;
  
  /**
   * Optional custom fallback component
   * If not provided, uses LockedModuleBanner
   */
  fallback?: React.ReactNode;
}

/**
 * RequireFeature - Guards module access based on billing plan
 * 
 * Checks FeatureContext flags and renders children only if feature is enabled
 * Shows LockedModuleBanner if feature is disabled
 * 
 * @example
 * <RequireFeature feature="travels">
 *   <TravelsList />
 * </RequireFeature>
 */
const RequireFeature: React.FC<RequireFeatureProps> = ({ feature, children, fallback }) => {
  const { hasTravels, hasPlanning, hasAI, loading } = useFeatures();

  // Map feature name to flag
  const featureEnabled = (() => {
    switch (feature) {
      case 'travels':
        return hasTravels;
      case 'planning':
        return hasPlanning;
      case 'ai':
        return hasAI;
      default:
        return false;
    }
  })();

  // Show loading state while checking features
  if (loading) {
    return null; // Or could return a skeleton loader
  }

  // Feature enabled - render children
  if (featureEnabled) {
    return <>{children}</>;
  }

  // Feature disabled - show fallback or LockedModuleBanner
  if (fallback) {
    return <>{fallback}</>;
  }

  return <LockedModuleBanner feature={feature} />;
};

export default RequireFeature;
