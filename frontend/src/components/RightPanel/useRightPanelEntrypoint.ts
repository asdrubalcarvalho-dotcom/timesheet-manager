import { useContext, useLayoutEffect } from 'react';
import { RightPanelEntrypointContext } from './RightPanelEntrypointProvider';

export const useRightPanelEntrypoint = () => {
  const ctx = useContext(RightPanelEntrypointContext);
  if (!ctx) {
    throw new Error('useRightPanelEntrypoint must be used within a RightPanelEntrypointProvider');
  }
  return ctx;
};

export const useRegisterRightPanelFloatingTrigger = (): void => {
  const { registerFloatingTrigger } = useRightPanelEntrypoint();

  // Use layout effect to minimize header flicker (button hiding) when mounting the floating trigger.
  useLayoutEffect(() => {
    return registerFloatingTrigger();
  }, [registerFloatingTrigger]);
};
