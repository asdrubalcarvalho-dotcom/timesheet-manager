import React from 'react';
import { act, render } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { ACTIONS, EVENTS, STATUS } from 'react-joyride';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

let joyrideProps: any;

const hoisted = vi.hoisted(() => {
  const rightPanelState = {
    isOpen: false,
    activeTabId: null as string | null,
  };

  const openRightPanelMock = vi.fn();
  const closeRightPanelMock = vi.fn();

  return { rightPanelState, openRightPanelMock, closeRightPanelMock };
});

vi.mock('react-joyride', () => {
  return {
    __esModule: true,
    default: (props: any) => {
      joyrideProps = props;
      return null;
    },
    ACTIONS: {
      PREV: 'prev',
      NEXT: 'next',
      SKIP: 'skip',
    },
    EVENTS: {
      STEP_AFTER: 'step:after',
      TARGET_NOT_FOUND: 'target:notFound',
    },
    STATUS: {
      RUNNING: 'running',
      FINISHED: 'finished',
      SKIPPED: 'skipped',
    },
  };
});

// Mock the hook using a path that resolves to the same module as the one imported by TourController.
vi.mock('../../../RightPanel/useRightPanel', () => {
  return {
    useRightPanel: () => {
      return {
        isOpen: hoisted.rightPanelState.isOpen,
        activeTabId: hoisted.rightPanelState.activeTabId,
        open: hoisted.openRightPanelMock,
        close: hoisted.closeRightPanelMock,
      };
    },
  };
});

const mountRightPanelTargets = () => {
  const toggle = document.createElement('button');
  toggle.setAttribute('data-tour', 'rightpanel-toggle');
  document.body.appendChild(toggle);

  const container = document.createElement('div');
  container.setAttribute('data-tour', 'rightpanel-container');
  document.body.appendChild(container);

  const tab = document.createElement('div');
  tab.setAttribute('data-tour', 'rightpanel-tab-ai');
  document.body.appendChild(tab);
};

const renderTour = async () => {
  const aiTrigger = document.createElement('button');
  aiTrigger.setAttribute('data-tour', 'ai-trigger');
  document.body.appendChild(aiTrigger);

  const mod = await import('../TourController');
  const TourController = mod.default;

  return render(
    <MemoryRouter initialEntries={['/timesheets']}>
      <TourController />
    </MemoryRouter>
  );
};

describe('TourController', () => {
  beforeEach(() => {
    vi.useFakeTimers();
    joyrideProps = null;
    hoisted.rightPanelState.isOpen = false;
    hoisted.rightPanelState.activeTabId = null;

    hoisted.openRightPanelMock.mockReset();
    hoisted.openRightPanelMock.mockImplementation((tabId?: string) => {
      hoisted.rightPanelState.isOpen = true;
      hoisted.rightPanelState.activeTabId = tabId ?? null;
    });

    hoisted.closeRightPanelMock.mockReset();
    hoisted.closeRightPanelMock.mockImplementation(() => {
      hoisted.rightPanelState.isOpen = false;
      hoisted.rightPanelState.activeTabId = null;
    });

    localStorage.removeItem('tour.v1.completed');
  });

  afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
  });

  it('opens the right panel, waits for targets, and advances to the last step', async () => {
    await renderTour();

    await act(async () => {
      window.dispatchEvent(new Event('tour:start'));
    });

    await act(async () => {
      await Promise.resolve();
    });

    expect(joyrideProps?.run).toBe(true);

    mountRightPanelTargets();

    await act(async () => {
      joyrideProps.callback({
        status: STATUS.RUNNING,
        type: EVENTS.STEP_AFTER,
        index: 3,
        action: ACTIONS.NEXT,
      });
    });

    expect(hoisted.openRightPanelMock).toHaveBeenCalled();

    await act(async () => {
      await Promise.resolve();
    });

    await act(async () => {
      await Promise.resolve();
    });

    expect(joyrideProps.stepIndex).toBe(4);

    await act(async () => {
      joyrideProps.callback({
        status: STATUS.RUNNING,
        type: EVENTS.STEP_AFTER,
        index: 4,
        action: ACTIONS.NEXT,
      });
    });

    expect(joyrideProps.stepIndex).toBe(5);
  });
});