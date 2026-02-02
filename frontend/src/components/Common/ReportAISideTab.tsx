import React, { useMemo, useRef, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  Stack,
} from '@mui/material';
import SmartToyIcon from '@mui/icons-material/SmartToy';
import ReportAIChatPanel from './ReportAIChatPanel';
import type { TenantAiState } from './aiState';
import { useRegisterRightPanelTab } from '../RightPanel/useRegisterRightPanelTab';
import { RightPanelTrigger } from '../RightPanel/RightPanelTrigger';
import { useRightPanelTabToggle } from '../RightPanel/useRightPanelTabToggle';
import { useRightPanel } from '../RightPanel/useRightPanel';
import { useTranslation } from 'react-i18next';

type Props = {
  aiState: TenantAiState;
  insights?: React.ReactNode;
  insightSuggestions?: string[];
  title?: string;
  onUpgrade?: () => void;
  onOpenSettings?: () => void;
  onAsk?: (question: string) => Promise<string>;
};

const ReportAISideTab: React.FC<Props> = ({
  aiState,
  insights,
  insightSuggestions = [],
  title,
  onUpgrade,
  onOpenSettings,
  onAsk,
}) => {
  const { t } = useTranslation();
  const resolvedTitle = title ?? t('rightPanel.tabs.ai');
  const { open } = useRightPanel();
  const canChat = useMemo(() => aiState === 'enabled' && typeof onAsk === 'function', [aiState, onAsk]);
  const [draftQuestion, setDraftQuestion] = useState<string>('');

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
      label: t('rightPanel.tabs.insights'),
      order: -10,
      render: () => (
        <Box>
          <Stack spacing={2}>
            {insights ? <Box>{insights}</Box> : null}
            {insightSuggestions.length > 0 ? (
              <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
                {insightSuggestions.map((label) => (
                  <Chip
                    key={label}
                    label={label}
                    size="small"
                    variant="outlined"
                    onClick={() => {
                      setDraftQuestion(label);
                      open('ai-chat');
                    }}
                  />
                ))}
              </Stack>
            ) : null}
            {!insights ? (
              <Alert severity="info" variant="outlined">
                {t('rightPanel.reports.noInsights')}
              </Alert>
            ) : null}
          </Stack>
        </Box>
      ),
    }),
    [insightsTabId, insights, insightSuggestions, open, t]
  );

  // Override the global AI tab while a report is mounted (stacked by id).
  const aiTab = useMemo(
    () => ({
      id: 'ai-chat',
      label: resolvedTitle,
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
                      {t('rightPanel.ai.viewBilling')}
                    </Button>
                  ) : undefined
                }
              >
                {t('rightPanel.ai.addonNotice')}
              </Alert>
            ) : null}

            {aiState === 'disabled_by_tenant' ? (
              <Alert
                severity="info"
                variant="outlined"
                action={
                  onOpenSettings ? (
                    <Button variant="outlined" size="small" onClick={onOpenSettings} sx={{ textTransform: 'none' }}>
                      {t('rightPanel.ai.billingSettings')}
                    </Button>
                  ) : undefined
                }
              >
                {t('rightPanel.ai.disabledTenant')}
              </Alert>
            ) : null}

            {aiState === 'not_available' ? (
              <Alert severity="info" variant="outlined">
                {t('rightPanel.ai.unavailable')}
              </Alert>
            ) : null}

            {canChat ? (
              <ReportAIChatPanel
                onAsk={onAsk!}
                suggestions={insightSuggestions}
                prefill={draftQuestion}
              />
            ) : null}
          </Stack>
        </Box>
      ),
    }),
    [resolvedTitle, aiState, onUpgrade, onOpenSettings, canChat, onAsk, insightSuggestions, draftQuestion, t]
  );

  useRegisterRightPanelTab(insightsTab);
  useRegisterRightPanelTab(aiTab);

  const toggleInsights = useRightPanelTabToggle(insightsTabId);

  return (
    <RightPanelTrigger
      tabId={insightsTabId}
      tooltip={t('rightPanel.trigger.tooltip')}
      icon={<SmartToyIcon fontSize="small" />}
      ariaLabel={{ open: t('rightPanel.trigger.open', { tab: t('rightPanel.tabs.insights') }), close: t('rightPanel.trigger.close', { tab: t('rightPanel.tabs.insights') }) }}
      onClick={toggleInsights}
    />
  );
};

export default ReportAISideTab;
