import React, { createContext, useCallback, useMemo, useRef, useState } from 'react';
import type { RightPanelTab, RightPanelTabId } from './types';

export type RightPanelContextValue = {
  isOpen: boolean;
  activeTabId: RightPanelTabId | null;
  tabs: RightPanelTab[];
  open: (tabId?: RightPanelTabId) => void;
  close: () => void;
  toggle: (tabId?: RightPanelTabId) => void;
  setActiveTab: (tabId: RightPanelTabId) => void;
  registerTab: (tab: RightPanelTab) => () => void;
};

export const RightPanelContext = createContext<RightPanelContextValue | undefined>(undefined);

const sortTabs = (tabs: RightPanelTab[]): RightPanelTab[] => {
  return [...tabs].sort((a, b) => {
    const ao = typeof a.order === 'number' ? a.order : 1000;
    const bo = typeof b.order === 'number' ? b.order : 1000;
    if (ao !== bo) return ao - bo;
    return a.label.localeCompare(b.label);
  });
};

const getSortedTabsFromMap = (map: Map<RightPanelTabId, RightPanelTab[]>): RightPanelTab[] => {
  const all = Array.from(map.values())
    .map((stack) => stack[stack.length - 1])
    .filter((t): t is RightPanelTab => Boolean(t));

  return sortTabs(all);
};

export const RightPanelProvider: React.FC<React.PropsWithChildren> = ({ children }) => {
  // Support contextual overrides: multiple components can register the same tab id.
  // We keep a stack per id and render the last-registered tab.
  const tabsByIdRef = useRef<Map<RightPanelTabId, RightPanelTab[]>>(new Map());
  const [tabsVersion, setTabsVersion] = useState(0);
  const [isOpen, setIsOpen] = useState(false);
  const [activeTabId, setActiveTabId] = useState<RightPanelTabId | null>(null);

  const tabs = useMemo(() => {
    return getSortedTabsFromMap(tabsByIdRef.current);
  }, [tabsVersion]);

  const ensureValidActiveTab = useCallback(
    (preferred?: RightPanelTabId | null) => {
      const map = tabsByIdRef.current;

      if (preferred && (map.get(preferred)?.length ?? 0) > 0) {
        setActiveTabId(preferred);
        return;
      }

      const current = activeTabId;
      if (current && (map.get(current)?.length ?? 0) > 0) return;

      const first = getSortedTabsFromMap(map)[0];
      setActiveTabId(first ? first.id : null);
    },
    [activeTabId]
  );

  const open = useCallback(
    (tabId?: RightPanelTabId) => {
      setIsOpen(true);
      ensureValidActiveTab(tabId ?? null);
    },
    [ensureValidActiveTab]
  );

  const close = useCallback(() => {
    setIsOpen(false);
  }, []);

  const toggle = useCallback(
    (tabId?: RightPanelTabId) => {
      const requested = tabId ?? null;

      setIsOpen((prev) => {
        const nextOpen = requested
          ? !(prev && activeTabId === requested)
          : !prev;

        if (nextOpen) {
          ensureValidActiveTab(requested);
        }

        return nextOpen;
      });
    },
    [activeTabId, ensureValidActiveTab]
  );

  const setActiveTab = useCallback((tabId: RightPanelTabId) => {
    if ((tabsByIdRef.current.get(tabId)?.length ?? 0) === 0) return;
    setActiveTabId(tabId);
  }, []);

  const registerTab = useCallback(
    (tab: RightPanelTab) => {
      const map = tabsByIdRef.current;
      const stack = map.get(tab.id) ?? [];
      map.set(tab.id, [...stack, tab]);
      setTabsVersion((v) => v + 1);
      ensureValidActiveTab(activeTabId);

      return () => {
        const map = tabsByIdRef.current;
        const stack = map.get(tab.id);
        if (!stack || stack.length === 0) return;

        const nextStack = stack.filter((t) => t !== tab);

        if (nextStack.length === 0) {
          map.delete(tab.id);
        } else {
          map.set(tab.id, nextStack);
        }

        setTabsVersion((v) => v + 1);

        setActiveTabId((current) => {
          if (current !== tab.id) return current;
          const first = getSortedTabsFromMap(map)[0];
          return first ? first.id : null;
        });
      };
    },
    [activeTabId, ensureValidActiveTab]
  );

  const value: RightPanelContextValue = useMemo(
    () => ({
      isOpen,
      activeTabId,
      tabs,
      open,
      close,
      toggle,
      setActiveTab,
      registerTab,
    }),
    [isOpen, activeTabId, tabs, open, close, toggle, setActiveTab, registerTab]
  );

  return <RightPanelContext.Provider value={value}>{children}</RightPanelContext.Provider>;
};
