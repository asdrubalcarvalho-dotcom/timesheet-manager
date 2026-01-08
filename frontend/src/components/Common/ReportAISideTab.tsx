import React, { useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Divider,
  Drawer,
  IconButton,
  Stack,
  Tooltip,
  Typography,
  useTheme,
} from '@mui/material';
import SmartToyIcon from '@mui/icons-material/SmartToy';
import CloseIcon from '@mui/icons-material/Close';
import ReportAIChatPanel from './ReportAIChatPanel';
import type { TenantAiState } from './aiState';

type Props = {
  aiState: TenantAiState;
  insights?: React.ReactNode;
  title?: string;
  onUpgrade?: () => void;
  onOpenSettings?: () => void;
  onAsk?: (question: string) => Promise<string>;
};

const ReportAISideTab: React.FC<Props> = ({ aiState, insights, title = 'AI', onUpgrade, onOpenSettings, onAsk }) => {
  const theme = useTheme();
  const [open, setOpen] = useState(false);

  const canChat = useMemo(() => aiState === 'enabled' && typeof onAsk === 'function', [aiState, onAsk]);

  return (
    <>
      <Box
        sx={{
          position: 'fixed',
          right: 0,
          top: '50%',
          transform: 'translateY(-50%)',
          zIndex: theme.zIndex.drawer + 1,
        }}
      >
        <Tooltip title="AI" placement="left">
          <IconButton
            aria-label={open ? 'Close AI' : 'Open AI'}
            onClick={() => setOpen((prev) => !prev)}
            sx={{
              borderRadius: '8px 0 0 8px',
              bgcolor: open ? 'primary.dark' : 'primary.main',
              color: 'primary.contrastText',
              boxShadow: open ? 6 : 3,
              '&:hover': {
                bgcolor: open ? 'primary.main' : 'primary.dark',
              },
            }}
          >
            <SmartToyIcon fontSize="small" />
          </IconButton>
        </Tooltip>
      </Box>

      <Drawer
        anchor="right"
        open={open}
        onClose={() => setOpen(false)}
        PaperProps={{ sx: { width: { xs: '100%', sm: 420 } } }}
      >
        <Box sx={{ p: 2, display: 'flex', alignItems: 'center', gap: 1 }}>
          <Typography variant="subtitle1" sx={{ flex: 1, fontWeight: 700 }}>
            {title}
          </Typography>
          <IconButton aria-label="Close" onClick={() => setOpen(false)}>
            <CloseIcon />
          </IconButton>
        </Box>

        <Divider />

        <Box sx={{ p: 2, overflow: 'auto' }}>
          <Stack spacing={2}>
            {insights ? <Box>{insights}</Box> : null}

            {aiState === 'available_as_addon' ? (
              <Alert
                severity="warning"
                variant="outlined"
                action={
                  onUpgrade ? (
                    <Button variant="outlined" size="small" onClick={onUpgrade} sx={{ textTransform: 'none' }}>
                      View billing options
                    </Button>
                  ) : undefined
                }
              >
                AI Suggestions are available with the AI add-on. Upgrade in Billing to unlock automated planning insights.
              </Alert>
            ) : null}

            {aiState === 'disabled_by_tenant' ? (
              <Alert
                severity="info"
                variant="outlined"
                action={
                  onOpenSettings ? (
                    <Button variant="outlined" size="small" onClick={onOpenSettings} sx={{ textTransform: 'none' }}>
                      Manage AI preferences
                    </Button>
                  ) : undefined
                }
              >
                AI add-on is active, but suggestions are disabled in tenant settings. Ask an administrator to re-enable the AI toggle in Billing → Tenant Settings.
              </Alert>
            ) : null}

            {aiState === 'not_available' ? (
              <Alert severity="info" variant="outlined">
                AI is not available on your plan.
              </Alert>
            ) : null}

            {canChat ? <ReportAIChatPanel onAsk={onAsk!} /> : null}
          </Stack>
        </Box>
      </Drawer>
    </>
  );
};

export default ReportAISideTab;
