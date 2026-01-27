import { useContext } from 'react';
import { RightPanelContext } from './RightPanelProvider';

export const useRightPanel = () => {
  const ctx = useContext(RightPanelContext);
  if (!ctx) {
    throw new Error('useRightPanel must be used within a RightPanelProvider');
  }
  return ctx;
};
