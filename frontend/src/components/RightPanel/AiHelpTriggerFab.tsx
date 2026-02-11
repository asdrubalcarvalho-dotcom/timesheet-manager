import React from 'react';
import SmartToyIcon from '@mui/icons-material/SmartToy';
import { useTheme } from '@mui/material';
import type { SxProps, Theme } from '@mui/material/styles';
import { useTranslation } from 'react-i18next';
import { RightPanelTrigger } from './RightPanelTrigger';
import type { RightPanelTabId } from './types';
import { useRightPanelTabToggle } from './useRightPanelTabToggle';

type Props = {
  tabId?: RightPanelTabId;
  sx?: SxProps<Theme>;
};

const AiHelpTriggerFab: React.FC<Props> = ({ tabId = 'ai-chat', sx }) => {
  const { t } = useTranslation();
  const theme = useTheme();
  const toggleAiChat = useRightPanelTabToggle(tabId);

  return (
    <RightPanelTrigger
      tabId={tabId}
      tooltip={t('rightPanel.trigger.tooltip')}
      icon={<SmartToyIcon fontSize="small" />}
      ariaLabel={{
        open: t('rightPanel.trigger.open', { tab: t('rightPanel.tabs.ai') }),
        close: t('rightPanel.trigger.close', { tab: t('rightPanel.tabs.ai') }),
      }}
      onClick={toggleAiChat}
      registerFloatingTrigger={false}
      sx={{ zIndex: theme.zIndex.drawer + 2, ...sx }}
    />
  );
};

export default AiHelpTriggerFab;