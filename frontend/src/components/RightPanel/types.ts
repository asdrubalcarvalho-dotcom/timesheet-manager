import type React from 'react';

export type RightPanelTabId = string;

export type RightPanelTab = {
  id: RightPanelTabId;
  label: string;
  order?: number;
  render: () => React.ReactNode;
};
