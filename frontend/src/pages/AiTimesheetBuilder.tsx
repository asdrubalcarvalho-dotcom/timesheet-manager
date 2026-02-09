import React, { useMemo, useState } from 'react';
import { Alert, Box, Button, Stack, TextField, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { previewAiTimesheet, commitAiTimesheet } from '../services/aiTimesheet';
import type { AiTimesheetPlan } from '../types';
import { useNotification } from '../contexts/NotificationContext';
import AiAssistantMessage from '../components/AiTimesheet/AiAssistantMessage';
import AiTimesheetPreviewDetails from '../components/AiTimesheet/AiTimesheetPreviewDetails';
import {
  mapAiTimesheetMissingFields,
  mapAiTimesheetPreviewError,
} from '../utils/aiTimesheetPreview';

const AiTimesheetBuilder: React.FC = () => {
  const { t } = useTranslation();
  const { showSuccess, showError } = useNotification();
  const [prompt, setPrompt] = useState('');
  const [plan, setPlan] = useState<AiTimesheetPlan | null>(null);
  const [loading, setLoading] = useState(false);
  const [applyLoading, setApplyLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  type PreviewMessage = {
    title: string;
    message?: string;
    severity: 'info' | 'warning' | 'error';
    actions?: string[];
    content?: React.ReactNode;
  };

  const [previewMessages, setPreviewMessages] = useState<PreviewMessage[]>([]);

  const canPreview = useMemo(() => prompt.trim().length > 0 && !loading, [prompt, loading]);
  const canApply = Boolean(plan) && !applyLoading;

  const handlePreview = async () => {
    const value = prompt.trim();
    if (!value || loading) return;

    setLoading(true);
    setError(null);
    setPlan(null);
    setPreviewMessages([]);

    try {
      const response = await previewAiTimesheet(value);
      setPlan(response.plan);
      setPreviewMessages([
        {
          title: t('aiTimesheet.previewSummary.title'),
          severity: 'info',
          content: <AiTimesheetPreviewDetails plan={response.plan} />,
        },
        ...(response.warnings ?? []).map((warning): PreviewMessage => {
          const mapped = mapAiTimesheetPreviewError({ message: warning, t });
          return {
            title: `⚠️ ${mapped.title}`,
            message: mapped.message,
            severity: 'warning',
            actions: mapped.actions,
          };
        }),
      ]);
    } catch (e: unknown) {
      const responseStatus = (e as { response?: { status?: number } })?.response?.status;
      const responseData = (e as { response?: { data?: { message?: string; missing_fields?: string[] } } })?.response
        ?.data;
      const message = responseData?.message || (e instanceof Error ? e.message : t('rightPanel.ai.errorFallback'));

      if (responseStatus === 422) {
        const mapped = mapAiTimesheetPreviewError({ message, t });
        const missingFields = mapAiTimesheetMissingFields(responseData?.missing_fields ?? [], t);
        const nextMessages: PreviewMessage[] = [
          {
            title: mapped.title,
            message: mapped.message,
            severity: mapped.severity === 'warning' ? 'warning' : 'error',
            actions: mapped.actions,
          },
        ];

        if (missingFields.length > 0) {
          nextMessages.push({
            title: t('aiTimesheet.missingFields.title'),
            message: t('aiTimesheet.missingFields.message', { fields: missingFields.join(', ') }),
            severity: 'info',
          });
        }

        setPreviewMessages(nextMessages);
      } else {
        const mapped = mapAiTimesheetPreviewError({ message, t });
        setPreviewMessages([
          {
            title: mapped.title,
            message: mapped.message,
            severity: mapped.severity === 'warning' ? 'warning' : 'error',
            actions: mapped.actions,
          },
        ]);
      }
    } finally {
      setLoading(false);
    }
  };

  const handleApply = async () => {
    if (!plan || applyLoading) return;

    setApplyLoading(true);
    setError(null);

    try {
      const requestId = crypto?.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`;
      const response = await commitAiTimesheet({ requestId, plan });
      showSuccess(t('aiTimesheet.applySuccess', { count: response.summary.created_count }));
      window.dispatchEvent(new Event('timesheets:refresh'));
    } catch (e: unknown) {
      const message = e instanceof Error ? e.message : t('rightPanel.ai.errorFallback');
      setError(message);
      showError(message);
    } finally {
      setApplyLoading(false);
    }
  };

  return (
    <Box sx={{ maxWidth: 900, mx: 'auto' }}>
      <Stack spacing={2}>
        <Typography variant="h5" sx={{ fontWeight: 700 }}>
          {t('aiTimesheet.title')}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          {t('aiTimesheet.subtitle')}
        </Typography>

        {error ? <Alert severity="error">{error}</Alert> : null}

        <TextField
          label={t('aiTimesheet.promptLabel')}
          placeholder={t('aiTimesheet.promptPlaceholder')}
          value={prompt}
          onChange={(e) => setPrompt(e.target.value)}
          minRows={4}
          multiline
        />

        <Stack direction="row" spacing={2}>
          <Button variant="contained" onClick={() => void handlePreview()} disabled={!canPreview}>
            {loading ? t('common.loading') : t('aiTimesheet.previewButton')}
          </Button>
          <Button variant="outlined" onClick={() => void handleApply()} disabled={!canApply}>
            {applyLoading ? t('common.saving') : t('aiTimesheet.applyButton')}
          </Button>
        </Stack>

        {previewMessages.length > 0 ? (
          <Stack spacing={1.5}>
            {previewMessages.map((item, idx) => (
              <AiAssistantMessage
                key={`${item.title}-${idx}`}
                title={item.title}
                message={item.message}
                severity={item.severity}
                actions={item.actions}
              >
                {item.content}
              </AiAssistantMessage>
            ))}
          </Stack>
        ) : null}
      </Stack>
    </Box>
  );
};

export default AiTimesheetBuilder;
