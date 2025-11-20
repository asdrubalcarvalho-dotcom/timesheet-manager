import React, { createContext, useContext, useState, useEffect, ReactNode } from 'react';
import api from '../services/api';

/**
 * FeatureContext
 * 
 * Manages feature flags for the current tenant.
 * Loads enabled modules from backend and provides conditional rendering logic.
 */

interface FeatureContextType {
  enabledModules: string[];
  isLoading: boolean;
  isEnabled: (module: string) => boolean;
  isCore: (module: string) => boolean;
  refreshFeatures: () => Promise<void>;
}

const FeatureContext = createContext<FeatureContextType | undefined>(undefined);

const CORE_MODULES = ['timesheets', 'expenses'];

interface FeatureProviderProps {
  children: ReactNode;
}

export const FeatureProvider: React.FC<FeatureProviderProps> = ({ children }) => {
  const [enabledModules, setEnabledModules] = useState<string[]>([]);
  const [isLoading, setIsLoading] = useState(true);

  const fetchEnabledModules = async () => {
    try {
      setIsLoading(true);
      const response = await api.get('/api/features/enabled');
      setEnabledModules(response.data.enabled_modules || []);
    } catch (error) {
      console.error('Failed to load enabled modules:', error);
      // Fallback: enable core modules only
      setEnabledModules(CORE_MODULES);
    } finally {
      setIsLoading(false);
    }
  };

  useEffect(() => {
    fetchEnabledModules();
  }, []);

  const isEnabled = (module: string): boolean => {
    // Core modules are always enabled
    if (CORE_MODULES.includes(module)) {
      return true;
    }
    return enabledModules.includes(module);
  };

  const isCore = (module: string): boolean => {
    return CORE_MODULES.includes(module);
  };

  const refreshFeatures = async () => {
    await fetchEnabledModules();
  };

  return (
    <FeatureContext.Provider
      value={{
        enabledModules,
        isLoading,
        isEnabled,
        isCore,
        refreshFeatures,
      }}
    >
      {children}
    </FeatureContext.Provider>
  );
};

export const useFeatures = (): FeatureContextType => {
  const context = useContext(FeatureContext);
  if (!context) {
    throw new Error('useFeatures must be used within a FeatureProvider');
  }
  return context;
};
