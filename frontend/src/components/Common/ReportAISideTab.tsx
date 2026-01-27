import React, { useMemo, useRef } from 'react';
import {
  Alert,
  Box,
  Button,
  Stack,
} from '@mui/material';
import SmartToyIcon from '@mui/icons-material/SmartToy';
import ReportAIChatPanel from './ReportAIChatPanel';
import type { TenantAiState } from './aiState';
import { useRegisterRightPanelTab } from '../RightPanel/useRegisterRightPanelTab';
import { RightPanelTrigger } from '../RightPanel/RightPanelTrigger';
import { useRightPanelTabToggle } from '../RightPanel/useRightPanelTabToggle';

type Props = {
  aiState: TenantAiState;
  insights?: React.ReactNode;
  title?: string;
  onUpgrade?: () => void;
  onOpenSettings?: () => void;
  onAsk?: (question: string) => Promise<string>;
};

const ReportAISideTab: React.FC<Props> = ({ aiState, insights, title = 'AI', onUpgrade, onOpenSettings, onAsk }) => {
  const canChat = useMemo(() => aiState === 'enabled' && typeof onAsk === 'function', [aiState, onAsk]);

  // Keep a stable Insights tab id per report page instance.
  const insightsTabIdRef = useRef<string | null>(null);
  if (!insightsTabIdRef.current) {
    insightsTabIdRef.current = typeof crypto !== 'undefined' && 'randomUUID' in crypto
      ? `report-insights-${crypto.randomUUID()}`
      : `report-insights-${Math.random().toString(16).slice(2)}`;
  }
  const insightsTabId = insightsTabIdRef.current;

  const insightsTab = useMemo(
    () => ({
      id: insightsTabId,
      label: 'Insights',
      order: -10,
      render: () => (
        <Box>
          <Stack spacing={2}>
            {insights ? <Box>{insights}</Box> : null}
            {!insights ? (
              <Alert severity="info" variant="outlined">
                No insights available for this report.
              </Alert>
            ) : null}
          </Stack>
        </Box>
      ),
    }),
    [insightsTabId, insights]
  );

  // Override the global AI tab while a report is mounted (stacked by id).
  const aiTab = useMemo(
    () => ({
      id: 'ai-chat',
      label: title,
      order: 10,
      render: () => (
        <Box>
          <Stack spacing={2}>
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
                AI is available with the AI add-on. Upgrade in Billing to unlock automated insights.
              </Alert>
            ) : null}

            {aiState === 'disabled_by_tenant' ? (
              <Alert
                severity="info"
                variant="outlined"
                action={
                  onOpenSettings ? (
                    <Button variant="outlined" size="small" onClick={onOpenSettings} sx={{ textTransform: 'none' }}>
                      Billing → Tenant Settings
                    </Button>
                  ) : undefined
                }
              >
                AI add-on is active, but disabled in tenant settings.
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
      ),
    }),
    [title, aiState, onUpgrade, onOpenSettings, canChat, onAsk]
  );

  useRegisterRightPanelTab(insightsTab);
  useRegisterRightPanelTab(aiTab);

  const toggleInsights = useRightPanelTabToggle(insightsTabId);

  return (
    <RightPanelTrigger
      tabId={insightsTabId}
      tooltip="Insights / Help / AI"
      icon={<SmartToyIcon fontSize="small" />}
      ariaLabel={{ open: 'Open Insights', close: 'Close Insights' }}
      onClick={toggleInsights}
    />
  );
};

export default ReportAISideTab;
