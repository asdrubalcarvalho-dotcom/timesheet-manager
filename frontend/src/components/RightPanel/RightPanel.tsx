import React, { useMemo } from 'react';
import {
  Box,
  Divider,
  Drawer,
  IconButton,
  Tab,
  Tabs,
  Typography,
  useMediaQuery,
  useTheme,
} from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';
import { useRightPanel } from './useRightPanel';
import { useTranslation } from 'react-i18next';

export const RightPanel: React.FC = () => {
  const { t } = useTranslation();
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('sm'));
  const { isOpen, close, tabs, activeTabId, setActiveTab } = useRightPanel();

  const activeTab = useMemo(() => {
    if (!activeTabId) return null;
    return tabs.find((t) => t.id === activeTabId) ?? null;
  }, [tabs, activeTabId]);

  const content = activeTab ? activeTab.render() : null;

  return (
    <Drawer
      anchor={isMobile ? 'bottom' : 'right'}
      open={isOpen}
      onClose={close}
      ModalProps={{ keepMounted: true }}
      PaperProps={{
        sx: isMobile
          ? { height: '80vh', borderTopLeftRadius: 12, borderTopRightRadius: 12 }
          : { width: 420 },
      }}
    >
      <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
        <Box
          sx={{
            px: 2,
            py: 1,
            display: 'flex',
            alignItems: 'center',
            borderBottom: '1px solid',
            borderColor: 'divider',
            gap: 1,
          }}
        >
          <Typography variant="subtitle1" sx={{ fontWeight: 800, flex: 1 }}>
            {activeTab?.label ?? t('rightPanel.panelFallback')}
          </Typography>
          <IconButton aria-label={t('rightPanel.closePanel')} onClick={close}>
            <CloseIcon />
          </IconButton>
        </Box>

        {tabs.length > 1 ? (
          <>
            <Tabs
              value={activeTabId ?? false}
              onChange={(_, next) => {
                if (typeof next === 'string') setActiveTab(next);
              }}
              variant="scrollable"
              scrollButtons="auto"
            >
              {tabs.map((t) => (
                <Tab key={t.id} value={t.id} label={t.label} />
              ))}
            </Tabs>
            <Divider />
          </>
        ) : null}

        <Box sx={{ p: 2, overflow: 'auto', flex: 1, minHeight: 0 }}>{content}</Box>
      </Box>
    </Drawer>
  );
};
