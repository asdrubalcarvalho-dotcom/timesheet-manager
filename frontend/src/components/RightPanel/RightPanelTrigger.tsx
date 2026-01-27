import React from 'react';
import { Badge, Box, IconButton, Tooltip, useTheme } from '@mui/material';
import type { SxProps, Theme } from '@mui/material/styles';
import { useRightPanel } from './useRightPanel';
import type { RightPanelTabId } from './types';
import { useRegisterRightPanelFloatingTrigger } from './useRightPanelEntrypoint';

export type RightPanelTriggerBadge =
  | { variant: 'dot'; show: boolean }
  | { variant: 'count'; count: number };

type Props = {
  tabId: RightPanelTabId;
  tooltip: string;
  icon: React.ReactNode;
  badge?: RightPanelTriggerBadge;
  sx?: SxProps<Theme>;
  ariaLabel?: { open: string; close: string };
  onClick?: () => void;
};

export const RightPanelTrigger: React.FC<Props> = ({ tabId, tooltip, icon, badge, sx, ariaLabel, onClick }) => {
  const theme = useTheme();
  const { isOpen, activeTabId, toggle } = useRightPanel();

  // When this component is mounted on a page, we consider that page to have a floating entrypoint.
  // The layout can hide any redundant top-right "Help/AI" button accordingly.
  useRegisterRightPanelFloatingTrigger();

  const openForThisTab = isOpen && activeTabId === tabId;

  const iconNode = (() => {
    if (!badge) return icon;

    if (badge.variant === 'count') {
      const count = Number.isFinite(badge.count) ? badge.count : 0;
      return (
        <Badge
          color="error"
          badgeContent={count}
          overlap="circular"
          sx={{ '& .MuiBadge-badge': { fontSize: '0.65rem', minWidth: 16, height: 16 } }}
        >
          {icon}
        </Badge>
      );
    }

    return (
      <Badge
        variant="dot"
        color="error"
        overlap="circular"
        invisible={!badge.show}
        sx={{ '& .MuiBadge-badge': { width: 10, height: 10, borderRadius: 999 } }}
      >
        {icon}
      </Badge>
    );
  })();

  return (
    <Box
      sx={{
        position: 'fixed',
        right: 0,
        top: '50%',
        transform: 'translateY(-50%)',
        zIndex: theme.zIndex.drawer + 1,
        ...sx,
      }}
    >
      <Tooltip title={tooltip} placement="left">
        <IconButton
          aria-label={openForThisTab ? (ariaLabel?.close ?? `Close ${tooltip}`) : (ariaLabel?.open ?? `Open ${tooltip}`)}
          onClick={() => (onClick ? onClick() : toggle(tabId))}
          sx={{
            borderRadius: '8px 0 0 8px',
            bgcolor: openForThisTab ? 'primary.dark' : 'primary.main',
            color: 'primary.contrastText',
            boxShadow: openForThisTab ? 6 : 3,
            '&:hover': {
              bgcolor: openForThisTab ? 'primary.main' : 'primary.dark',
            },
          }}
        >
          {iconNode}
        </IconButton>
      </Tooltip>
    </Box>
  );
};
