import React, { createContext, useCallback, useMemo, useRef, useState } from 'react';

type RightPanelEntrypointContextValue = {
  hasFloatingTrigger: boolean;
  registerFloatingTrigger: () => () => void;
};

export const RightPanelEntrypointContext = createContext<RightPanelEntrypointContextValue | undefined>(undefined);

export const RightPanelEntrypointProvider: React.FC<React.PropsWithChildren> = ({ children }) => {
  const counterRef = useRef(0);
  const [version, setVersion] = useState(0);

  const registerFloatingTrigger = useCallback(() => {
    counterRef.current += 1;
    setVersion((v) => v + 1);

    return () => {
      counterRef.current = Math.max(0, counterRef.current - 1);
      setVersion((v) => v + 1);
    };
  }, []);

  const hasFloatingTrigger = useMemo(() => counterRef.current > 0, [version]);

  const value = useMemo(
    () => ({
      hasFloatingTrigger,
      registerFloatingTrigger,
    }),
    [hasFloatingTrigger, registerFloatingTrigger]
  );

  return <RightPanelEntrypointContext.Provider value={value}>{children}</RightPanelEntrypointContext.Provider>;
};
