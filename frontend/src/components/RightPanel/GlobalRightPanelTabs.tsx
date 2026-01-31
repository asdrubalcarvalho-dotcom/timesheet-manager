import React, { useMemo } from 'react';
import { useRegisterRightPanelTab } from './useRegisterRightPanelTab';
import { HelpTab } from './tabs/HelpTab';
import { AiChatTab } from './tabs/AiChatTab';
import { useTranslation } from 'react-i18next';

export const GlobalRightPanelTabs: React.FC = () => {
  const { t } = useTranslation();
  const helpTab = useMemo(
    () => ({
      id: 'help',
      label: t('rightPanel.tabs.help'),
      order: 0,
      render: () => <HelpTab />,
    }),
    [t]
  );

  const aiTab = useMemo(
    () => ({
      id: 'ai-chat',
      label: t('rightPanel.tabs.ai'),
      order: 10,
      render: () => <AiChatTab />,
    }),
    [t]
  );

  useRegisterRightPanelTab(helpTab);
  useRegisterRightPanelTab(aiTab);

  return null;
};
