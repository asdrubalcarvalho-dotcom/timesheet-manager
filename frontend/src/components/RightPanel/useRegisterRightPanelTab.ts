import { useEffect } from 'react';
import type { RightPanelTab } from './types';
import { useRightPanel } from './useRightPanel';

export const useRegisterRightPanelTab = (tab: RightPanelTab): void => {
  const { registerTab } = useRightPanel();

  useEffect(() => {
    return registerTab(tab);
  }, [registerTab, tab]);
};
