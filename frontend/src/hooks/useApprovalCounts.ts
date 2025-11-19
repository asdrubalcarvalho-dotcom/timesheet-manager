import { useState, useEffect, useCallback } from 'react';
import { timesheetsApi } from '../services/api';
import { useAuth } from '../components/Auth/AuthContext';

interface ApprovalCounts {
  timesheets: number;
  expenses: number;
  total: number;
}

/**
 * Hook to fetch pending approval counts without impacting performance
 * Uses lightweight count-only endpoint and caches results for 30 seconds
 */
export const useApprovalCounts = (refreshInterval: number = 30000) => {
  const { hasPermission, user } = useAuth();
  const [counts, setCounts] = useState<ApprovalCounts>({
    timesheets: 0,
    expenses: 0,
    total: 0
  });
  const [loading, setLoading] = useState(false);

  const canApprove = hasPermission('approve-timesheets') || hasPermission('approve-expenses');

  const fetchCounts = useCallback(async () => {
    // Don't fetch if user is not authenticated or doesn't have permission
    if (!user || !canApprove) {
      setCounts({ timesheets: 0, expenses: 0, total: 0 });
      return;
    }

    try {
      setLoading(true);
      
      // Use optimized endpoint that only returns counts
      const response = await timesheetsApi.getPendingCounts();
      
      setCounts({
        timesheets: response.timesheets || 0,
        expenses: response.expenses || 0,
        total: response.total || 0
      });
    } catch (error) {
      console.error('Failed to fetch approval counts:', error);
      // Keep previous counts on error to avoid flickering
    } finally {
      setLoading(false);
    }
  }, [canApprove, user]);

  useEffect(() => {
    // Initial fetch
    fetchCounts();

    // Set up polling if user can approve
    if (user && canApprove && refreshInterval > 0) {
      const interval = setInterval(fetchCounts, refreshInterval);
      return () => clearInterval(interval);
    }
  }, [fetchCounts, canApprove, refreshInterval, user]);

  return { counts, loading, refresh: fetchCounts };
};
