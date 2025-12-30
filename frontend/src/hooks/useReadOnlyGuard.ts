import { useCallback, useMemo } from 'react';
import { useBilling } from '../contexts/BillingContext';
import { useNotification } from '../contexts/NotificationContext';

const READ_ONLY_STORAGE_KEY = 'tenant_read_only_mode';
const WARNED_PREFIX = 'tp_read_only_action_warned:';

export interface ReadOnlyGuard {
  isReadOnly: boolean;
  /** Returns false when blocked (read-only). */
  ensureWritable: () => boolean;
  /** Wrap an action handler so it won't run when read-only. */
  guard: <TArgs extends any[], TResult>(fn: (...args: TArgs) => TResult) => (...args: TArgs) => TResult | undefined;
  /** Show warning toast (deduped) */
  warn: () => void;
}

/**
 * Global read-only guard.
 *
 * Blocks write actions when `billingSummary.read_only === true`.
 * Read operations should continue to work.
 */
export function useReadOnlyGuard(actionName: string = 'action'): ReadOnlyGuard {
  const { billingSummary } = useBilling();
  const { showWarning } = useNotification();

  const isReadOnly = useMemo(() => {
    if (billingSummary) {
      return Boolean(billingSummary.read_only);
    }
    return localStorage.getItem(READ_ONLY_STORAGE_KEY) === '1';
  }, [billingSummary]);

  const warn = useCallback(() => {
    const key = `${WARNED_PREFIX}${actionName}`;
    if (sessionStorage.getItem(key) === '1') {
      return;
    }
    sessionStorage.setItem(key, '1');
    showWarning('Read-only mode is enabled. Please upgrade to make changes.');
  }, [actionName, showWarning]);

  const ensureWritable = useCallback((): boolean => {
    if (!isReadOnly) {
      return true;
    }
    warn();
    return false;
  }, [isReadOnly, warn]);

  const guard = useCallback(
    <TArgs extends any[], TResult>(fn: (...args: TArgs) => TResult) =>
      (...args: TArgs): TResult | undefined => {
        if (!ensureWritable()) {
          return undefined;
        }
        return fn(...args);
      },
    [ensureWritable]
  );

  return { isReadOnly, ensureWritable, guard, warn };
}
