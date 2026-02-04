import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import Joyride, { ACTIONS, EVENTS, STATUS, type CallBackProps, type Step } from 'react-joyride';
import { useTranslation } from 'react-i18next';
import { useLocation, useNavigate } from 'react-router-dom';
import { useRightPanel } from '../../RightPanel/useRightPanel';

const TOUR_COMPLETED_KEY = 'tour.v1.completed';
const TIMESHEETS_PATH = '/timesheets';

const isTourCompleted = (): boolean => {
  try {
    return localStorage.getItem(TOUR_COMPLETED_KEY) === 'true';
  } catch {
    return false;
  }
};

const setTourCompleted = (): void => {
  try {
    localStorage.setItem(TOUR_COMPLETED_KEY, 'true');
  } catch {
    // ignore
  }
};

const clearTourCompleted = (): void => {
  try {
    localStorage.removeItem(TOUR_COMPLETED_KEY);
  } catch {
    // ignore
  }
};

export const TourController: React.FC = () => {
  const { t, i18n } = useTranslation();
  const navigate = useNavigate();
  const location = useLocation();
  const {
    isOpen: isRightPanelOpen,
    activeTabId: activeRightPanelTabId,
    open: openRightPanel,
    close: closeRightPanel,
  } = useRightPanel();

  const [run, setRun] = useState(false);
  const [stepIndex, setStepIndex] = useState(0);
  const [startRequested, setStartRequested] = useState(false);
  const [retryTick, setRetryTick] = useState(0);

  const autoStartOnceRef = useRef(false);
  const missingTargetRetriesRef = useRef<Record<number, number>>({});
  const missingTargetInFlightRef = useRef<Record<number, boolean>>({});
  const initialRightPanelStateRef = useRef<{ wasOpen: boolean; activeTabId: string | null } | null>(null);
  const tourOpenedRightPanelRef = useRef(false);
  const pendingAdvanceRef = useRef<number | null>(null);

  const RIGHT_PANEL_ANCHOR_SELECTOR = '[data-tour="rightpanel-anchor"]';
  const RIGHT_PANEL_AI_TAB_SELECTOR = '[data-tour="rightpanel-tab-ai"]';
  const RIGHT_PANEL_TARGETS = new Set([RIGHT_PANEL_AI_TAB_SELECTOR]);
  const MAX_MISSING_TARGET_RETRIES = 5;
  const MISSING_TARGET_RETRY_INTERVAL_MS = 150;

  const steps: Step[] = useMemo(
    () => [
      {
        target: '[data-tour="menu-timesheets"]',
        content: t('tour.steps.menuTimesheets'),
        placement: 'auto',
        disableBeacon: true,
      },
      {
        target: '[data-tour="timesheets-calendar"]',
        content: t('tour.steps.timesheetsCalendar'),
        placement: 'auto',
      },
      {
        target: '[data-tour="timesheets-scope"]',
        content: t('tour.steps.timesheetsScope'),
        placement: 'auto',
      },
      {
        target: '[data-tour="ai-trigger"]',
        content: t('tour.steps.aiTrigger'),
        placement: 'auto',
      },
      {
        target: RIGHT_PANEL_ANCHOR_SELECTOR,
        content: t('tour.steps.rightPanelToggle'),
        placement: 'auto',
      },
      {
        target: RIGHT_PANEL_AI_TAB_SELECTOR,
        content: t('tour.steps.aiTab'),
        placement: 'auto',
      },
    ],
    [t, retryTick]
  );

  const lastStepIndex = steps.length - 1;
  const rightPanelAnchorStepIndex = useMemo(
    () => steps.findIndex((step) => step.target === RIGHT_PANEL_ANCHOR_SELECTOR),
    [steps]
  );

  const getStepTargetSelector = useCallback(
    (index: number): string | null => {
      const target = steps[index]?.target;
      return typeof target === 'string' ? target : null;
    },
    [steps]
  );

  const isRightPanelTarget = useCallback((target: string | null): boolean => {
    return target !== null && RIGHT_PANEL_TARGETS.has(target);
  }, []);

  const waitForSelector = useCallback((selector: string, timeoutMs: number): Promise<boolean> => {
    return new Promise((resolve) => {
      if (document.querySelector(selector)) {
        resolve(true);
        return;
      }

      let timeoutId: number | null = null;
      const observer = new MutationObserver(() => {
        if (document.querySelector(selector)) {
          if (timeoutId !== null) window.clearTimeout(timeoutId);
          observer.disconnect();
          resolve(true);
        }
      });

      observer.observe(document.body, { childList: true, subtree: true });
      timeoutId = window.setTimeout(() => {
        observer.disconnect();
        resolve(false);
      }, timeoutMs);
    });
  }, []);

  const retryForSelector = useCallback(
    async (selector: string, attempts: number, delayMs: number): Promise<boolean> => {
      for (let attempt = 1; attempt <= attempts; attempt += 1) {
        if (document.querySelector(selector)) {
          return true;
        }

        await new Promise<void>((resolve) => {
          window.setTimeout(resolve, delayMs);
        });
      }

      return Boolean(document.querySelector(selector));
    },
    []
  );

  const requestStart = useCallback(
    (reset: boolean) => {
      if (reset) clearTourCompleted();
      missingTargetRetriesRef.current = {};
      missingTargetInFlightRef.current = {};
      initialRightPanelStateRef.current = null;
      tourOpenedRightPanelRef.current = false;
      setStepIndex(0);
      setRun(false);
      setStartRequested(true);
    },
    []
  );

  // Auto-start once per browser/user.
  useEffect(() => {
    if (autoStartOnceRef.current) return;
    autoStartOnceRef.current = true;

    if (!isTourCompleted()) {
      requestStart(false);
    }
  }, [requestStart]);

  useEffect(() => {
    if (import.meta.env.MODE !== 'production') {
      console.info('[TourController] mounted');
    }

    return () => {
      if (import.meta.env.MODE !== 'production') {
        console.info('[TourController] unmounted');
      }
    };
  }, []);

  // Allow manual control from anywhere in the app.
  useEffect(() => {
    const onStart = () => requestStart(false);
    const onReset = () => requestStart(true);

    window.addEventListener('tour:start', onStart);
    window.addEventListener('tour:reset', onReset);

    return () => {
      window.removeEventListener('tour:start', onStart);
      window.removeEventListener('tour:reset', onReset);
    };
  }, [requestStart]);

  // Ensure we are on /timesheets before running the tour.
  useEffect(() => {
    if (!startRequested) return;

    if (location.pathname !== TIMESHEETS_PATH) {
      navigate(TIMESHEETS_PATH);
      return;
    }

    initialRightPanelStateRef.current = {
      wasOpen: isRightPanelOpen,
      activeTabId: activeRightPanelTabId,
    };

    // Close the right panel for the initial steps.
    if (isRightPanelOpen) closeRightPanel();

    setRun(true);
    setStartRequested(false);
  }, [startRequested, location.pathname, navigate, isRightPanelOpen, closeRightPanel]);

  const advanceToStep = useCallback(
    (nextIndex: number) => {
      if (nextIndex > lastStepIndex) {
        setStepIndex(nextIndex);
        pendingAdvanceRef.current = null;
        return;
      }

      const clampedIndex = Math.max(0, nextIndex);

      if (pendingAdvanceRef.current === clampedIndex) return;
      pendingAdvanceRef.current = clampedIndex;

      const targetSelector = getStepTargetSelector(clampedIndex);
      const shouldUseRightPanel = isRightPanelTarget(targetSelector);

      const runAdvance = async () => {
        if (shouldUseRightPanel) {
          if (!isRightPanelOpen) {
            openRightPanel('ai');
            tourOpenedRightPanelRef.current = true;
          }

          if (targetSelector) {
            const found = await waitForSelector(targetSelector, 2000);
            if (found) {
              missingTargetRetriesRef.current[clampedIndex] = 0;
              setStepIndex(clampedIndex);
              pendingAdvanceRef.current = null;
              return;
            }
          }

          setRetryTick((prev) => prev + 1);
          pendingAdvanceRef.current = null;
          return;
        }

        if (isRightPanelOpen) {
          closeRightPanel();
        }

        setStepIndex(clampedIndex);
        pendingAdvanceRef.current = null;
      };

      void runAdvance();
    },
    [closeRightPanel, getStepTargetSelector, isRightPanelOpen, isRightPanelTarget, lastStepIndex, openRightPanel, waitForSelector]
  );

  const handleCallback = useCallback(
    (data: CallBackProps) => {
      const { status, type, index, action } = data;

      if (status === STATUS.FINISHED || status === STATUS.SKIPPED) {
        if (index < lastStepIndex && action !== ACTIONS.SKIP) {
          return;
        }

        setTourCompleted();
        setRun(false);
        setStepIndex(0);
        missingTargetRetriesRef.current = {};
        missingTargetInFlightRef.current = {};

        const initial = initialRightPanelStateRef.current;
        if (initial?.wasOpen) {
          openRightPanel(initial.activeTabId ?? undefined);
        } else if (tourOpenedRightPanelRef.current && isRightPanelOpen) {
          closeRightPanel();
        }

        return;
      }

      if (type === EVENTS.TARGET_NOT_FOUND) {
        if (missingTargetInFlightRef.current[index]) {
          return;
        }

        missingTargetInFlightRef.current[index] = true;

        const targetSelector = getStepTargetSelector(index);
        if (!targetSelector) {
          missingTargetInFlightRef.current[index] = false;
          advanceToStep(index + 1);
          return;
        }

        if (isRightPanelTarget(targetSelector) && !isRightPanelOpen) {
          openRightPanel('ai');
          tourOpenedRightPanelRef.current = true;
        }

        const retryMissingTarget = async () => {
          for (let attempt = 1; attempt <= MAX_MISSING_TARGET_RETRIES; attempt += 1) {
            missingTargetRetriesRef.current[index] = attempt;

            if (document.querySelector(targetSelector)) {
              missingTargetRetriesRef.current[index] = 0;
              missingTargetInFlightRef.current[index] = false;
              setRetryTick((prev) => prev + 1);
              return;
            }

            await new Promise<void>((resolve) => {
              window.setTimeout(resolve, MISSING_TARGET_RETRY_INTERVAL_MS);
            });
          }

          missingTargetInFlightRef.current[index] = false;

          if (isRightPanelTarget(targetSelector)) {
            setRetryTick((prev) => prev + 1);
            return;
          }

          advanceToStep(index + 1);
        };

        void retryMissingTarget();
        return;
      }

      if (type === EVENTS.STEP_AFTER) {
        if (index === rightPanelAnchorStepIndex && action !== ACTIONS.PREV) {
          const runAdvance = async () => {
            openRightPanel('ai');
            tourOpenedRightPanelRef.current = true;

            const found = await retryForSelector(
              RIGHT_PANEL_AI_TAB_SELECTOR,
              MAX_MISSING_TARGET_RETRIES,
              MISSING_TARGET_RETRY_INTERVAL_MS
            );

            if (found) {
              advanceToStep(index + 1);
            } else {
              setRetryTick((prev) => prev + 1);
            }
          };

          void runAdvance();
          return;
        }

        const delta = action === ACTIONS.PREV ? -1 : 1;
        advanceToStep(index + delta);
      }
    },
    [
      advanceToStep,
      closeRightPanel,
      getStepTargetSelector,
      isRightPanelOpen,
      isRightPanelTarget,
      lastStepIndex,
      openRightPanel,
      retryForSelector,
        rightPanelAnchorStepIndex,
        RIGHT_PANEL_AI_TAB_SELECTOR,
    ]
  );

  return (
    <Joyride
      key={i18n.resolvedLanguage ?? i18n.language}
      steps={steps}
      run={run}
      stepIndex={stepIndex}
      continuous
      showProgress
      showSkipButton
      disableOverlayClose
      scrollToFirstStep
      callback={handleCallback}
      floaterProps={{
        options: {
          boundary: 'viewport',
          padding: 12,
          flip: { padding: 8 },
        },
      }}
      locale={{
        back: t('tour.buttons.back'),
        close: t('tour.buttons.close'),
        last: t('tour.buttons.finish'),
        next: t('tour.buttons.next'),
        skip: t('tour.buttons.skip'),
      }}
      styles={{
        options: {
          zIndex: 30000,
          primaryColor: '#1976d2',
        },
        tooltip: {
          maxWidth: 420,
        },
      }}
    />
  );
};

export default TourController;
