import React, { useMemo } from 'react';
import { Alert, AlertTitle, Box, Button, Stack, Typography } from '@mui/material';
import SmartToyIcon from '@mui/icons-material/SmartToy';
import { useBilling } from '../../../contexts/BillingContext';
import { getTenantAiState } from '../../Common/aiState';
import { useNavigate } from 'react-router-dom';

export const AiChatTab: React.FC = () => {
  const { billingSummary, tenantAiEnabled, openCheckoutForAddon } = useBilling();
  const aiState = useMemo(() => getTenantAiState(billingSummary, tenantAiEnabled), [billingSummary, tenantAiEnabled]);
  const navigate = useNavigate();

  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="h6" sx={{ fontWeight: 800, display: 'flex', alignItems: 'center', gap: 1 }}>
          <SmartToyIcon fontSize="small" /> AI Chat
        </Typography>
        <Typography variant="body2" color="text.secondary">
          Ask questions about approvals, spend, and trends.
        </Typography>
      </Box>

      {aiState === 'available_as_addon' ? (
        <Alert
          severity="warning"
          variant="outlined"
          action={
            <Button variant="outlined" size="small" onClick={() => void openCheckoutForAddon('ai')} sx={{ textTransform: 'none' }}>
              View billing options
            </Button>
          }
        >
          AI is available as an add-on on your plan.
        </Alert>
      ) : null}

      {aiState === 'disabled_by_tenant' ? (
        <Alert
          severity="info"
          variant="outlined"
          action={
            <Button variant="outlined" size="small" onClick={() => navigate('/billing')} sx={{ textTransform: 'none' }}>
              Billing → Tenant Settings
            </Button>
          }
        >
          <AlertTitle>AI is disabled</AlertTitle>
          Ask an administrator to enable AI in Billing → Tenant Settings.
        </Alert>
      ) : null}

      {aiState === 'not_available' ? (
        <Alert severity="info" variant="outlined">
          AI is not available on your plan.
        </Alert>
      ) : null}

      {aiState === 'enabled' ? (
        <Alert severity="success" variant="outlined">
          AI is enabled. Phase 1 ships the global panel; conversational chat wiring comes next.
        </Alert>
      ) : null}

      <Typography variant="body2" color="text.secondary">
        Coming soon: a unified AI chat experience across Timesheets, Expenses, and Approvals.
      </Typography>
    </Stack>
  );
};
