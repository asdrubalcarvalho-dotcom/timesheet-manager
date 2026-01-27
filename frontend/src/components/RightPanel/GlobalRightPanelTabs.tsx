import React, { useMemo } from 'react';
import { useRegisterRightPanelTab } from './useRegisterRightPanelTab';
import { HelpTab } from './tabs/HelpTab';
import { AiChatTab } from './tabs/AiChatTab';

export const GlobalRightPanelTabs: React.FC = () => {
  const helpTab = useMemo(
    () => ({
      id: 'help',
      label: 'Help',
      order: 0,
      render: () => <HelpTab />,
    }),
    []
  );

  const aiTab = useMemo(
    () => ({
      id: 'ai-chat',
      label: 'AI',
      order: 10,
      render: () => <AiChatTab />,
    }),
    []
  );

  useRegisterRightPanelTab(helpTab);
  useRegisterRightPanelTab(aiTab);

  return null;
};
