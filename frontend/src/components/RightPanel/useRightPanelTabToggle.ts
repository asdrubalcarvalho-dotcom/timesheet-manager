import { useCallback } from 'react';
import { useRightPanel } from './useRightPanel';
import type { RightPanelTabId } from './types';

export const useRightPanelTabToggle = (tabId: RightPanelTabId, onBeforeOpen?: () => void) => {
  const { isOpen, activeTabId, toggle } = useRightPanel();

  return useCallback(() => {
    const isCurrentlyOpen = isOpen && activeTabId === tabId;
    if (!isCurrentlyOpen) {
      onBeforeOpen?.();
    }
    toggle(tabId);
  }, [isOpen, activeTabId, tabId, toggle, onBeforeOpen]);
};
