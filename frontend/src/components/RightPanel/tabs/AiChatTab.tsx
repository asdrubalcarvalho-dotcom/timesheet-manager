import React, { useCallback, useEffect, useMemo, useState } from 'react';
import {
  Alert,
  AlertTitle,
  Box,
  Button,
  Chip,
  CircularProgress,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import SmartToyIcon from '@mui/icons-material/SmartToy';
import { useBilling } from '../../../contexts/BillingContext';
import { getTenantAiState } from '../../Common/aiState';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { commitTimesheetPlan, previewTimesheetPlan } from '../../../services/aiTimesheet';
import type { AiTimesheetPlan, AiTimesheetPreviewResponse } from '../../../types';
import { useNotification } from '../../../contexts/NotificationContext';
import { useAuth } from '../../Auth/AuthContext';
import AiAssistantMessage from '../../AiTimesheet/AiAssistantMessage';
import AiTimesheetPreviewDetails from '../../AiTimesheet/AiTimesheetPreviewDetails';
import {
  mapAiTimesheetMissingFields,
  mapAiTimesheetPreviewError,
} from '../../../utils/aiTimesheetPreview';
import { looksLikeTimesheetBuilder } from '../../../utils/looksLikeTimesheetBuilder';

type ChatMessage = {
  role: 'user' | 'assistant';
  content: React.ReactNode;
};

export const AiChatTab: React.FC = () => {
  const { t } = useTranslation();
  const { tenantContext } = useAuth();
  const { billingSummary, tenantAiEnabled, openCheckoutForAddon } = useBilling();
  const aiState = useMemo(() => getTenantAiState(billingSummary, tenantAiEnabled), [billingSummary, tenantAiEnabled]);
  const navigate = useNavigate();
  const { showSuccess, showError } = useNotification();

  const [question, setQuestion] = useState('');
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState<AiTimesheetPreviewResponse | null>(null);
  const [previewPlan, setPreviewPlan] = useState<AiTimesheetPlan | null>(null);
  const [applyLoading, setApplyLoading] = useState(false);
  const [showExamples, setShowExamples] = useState(false);

  const isDev = typeof import.meta !== 'undefined' && !!import.meta.env?.DEV;
  const logAiTimesheet = useCallback(
    (message: string, detail?: unknown) => {
      if (!isDev) return;
      if (typeof detail === 'undefined') {
        console.info(`AI_TIMESHEET: ${message}`);
      } else {
        console.info(`AI_TIMESHEET: ${message}`, detail);
      }
    },
    [isDev]
  );

  const examplePrompts = useMemo(() => {
    const fallbackPrompts = [
      'DATE_RANGE=2026-02-10..2026-02-14\nProjeto: "Mobile Banking App"\nBloco 1: 09:00-13:00\nBloco 2: 14:00-18:00',
      'DATE_RANGE=2026-02-10..2026-02-14\nProjeto: "Mobile Banking App"\nBloco 1: 09:00–13:00\nBloco 2: 14:00–18:00',
      'DATE_RANGE=2026-02-10..2026-02-14\nProject: "Mobile Banking App"\nBlock 1: 09:00-13:00\nBlock 2: 14:00-18:00',
      'DATE_RANGE=2026-02-10..2026-02-10\nProjeto: "Mobile Banking App"\n09:00-10:00',
      'DATE_RANGE=2026-02-10..2026-02-14\nProjeto: "Mobile Banking App"\n09:00-13:00\n14:00-18:00',
      'DATE_RANGE=2026-02-10..2026-02-14\nProject: "Mobile Banking App"\n09:00-13:00\n14:00-18:00',
    ];
    const raw = t('rightPanel.ai.examples.items', { returnObjects: true });
    if (Array.isArray(raw) && raw.length > 0) {
      return raw.map((item) => String(item));
    }
    return fallbackPrompts;
  }, [t]);

  const suggestionChips = useMemo(
    () => [
      t('rightPanel.ai.suggestions.flags'),
      t('rightPanel.ai.suggestions.overtime'),
      t('rightPanel.ai.suggestions.approvals'),
      t('rightPanel.ai.suggestions.projectCosts'),
      t('rightPanel.ai.suggestions.timesheetCreate'),
    ],
    [t]
  );

  const combinedPrompts = useMemo(() => {
    const merged = [...examplePrompts, ...suggestionChips]
      .map((v) => String(v))
      .map((v) => v.trim())
      .filter((v) => v.length > 0);
    return Array.from(new Set(merged));
  }, [examplePrompts, suggestionChips]);

  useEffect(() => {
    if (messages.length > 0) {
      setShowExamples(false);
    }
  }, [messages.length]);


  const canSend = useMemo(
    () => question.trim().length > 0 && !loading && aiState === 'enabled',
    [question, loading, aiState]
  );

  const handleSend = async () => {
    // Manual verification (DevTools):
    // Example prompt (builder-style):
    // DATE_RANGE=2026-02-10..2026-02-14
    // Projeto: "Mobile Banking App"
    // Bloco 1: 09:00-13:00
    // Bloco 2: 14:00-18:00
    // 2) Expect POST http://api.localhost/api/ai/timesheet/preview (200 JSON) with Authorization + X-Tenant headers.
    // 3) Preview card appears with summary + Confirm & Create button.
    // 4) Click Confirm & Create; expect POST /api/ai/timesheet/commit (200 JSON) and calendar refresh.
    const q = question.trim();
    if (!q || loading || aiState !== 'enabled') return;

    setError(null);
    setLoading(true);
    setShowExamples(false);
    setQuestion('');
    setPreview(null);
    setPreviewPlan(null);
    setMessages((prev) => [...prev, { role: 'user', content: q }]);

    try {
      if (looksLikeTimesheetBuilder(q)) {
        // Bypass insight chat for structured builder prompts so labels/time blocks stay intact.
        logAiTimesheet('timesheet builder prompt detected');
        const timezone = tenantContext?.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        const previewPayload = { prompt: q, timezone };
        logAiTimesheet('preview payload', previewPayload);
        const response = await previewTimesheetPlan(previewPayload.prompt, previewPayload.timezone);
        setPreview(response);
        setPreviewPlan(response.plan);

        setMessages((prev) => [
          ...prev,
          {
            role: 'assistant',
            content: (
              <AiAssistantMessage severity="info" title={t('aiTimesheet.previewSummary.title')}>
                <AiTimesheetPreviewDetails plan={response.plan} />
              </AiAssistantMessage>
            ),
          },
        ]);

        if (response.warnings?.length) {
          response.warnings.forEach((warning) => {
            const mapped = mapAiTimesheetPreviewError({ message: warning, t });
            setMessages((prev) => [
              ...prev,
              {
                role: 'assistant',
                content: (
                  <AiAssistantMessage
                    severity="warning"
                    title={`⚠️ ${mapped.title}`}
                    message={mapped.message}
                    actions={mapped.actions}
                  />
                ),
              },
            ]);
          });
        }
      } else {
        logAiTimesheet('fallback to insights chat');
        await new Promise((resolve) => setTimeout(resolve, 600));
        setMessages((prev) => [
          ...prev,
          { role: 'assistant', content: t('rightPanel.ai.placeholderResponse') },
        ]);
      }
    } catch (e: unknown) {
      const responseStatus = (e as { response?: { status?: number } })?.response?.status;
      const responseData = (e as { response?: { data?: { message?: string; missing_fields?: string[] } } })?.response
        ?.data;
      logAiTimesheet('preview error', { status: responseStatus, data: responseData });
      const message = responseData?.message || (e instanceof Error ? e.message : t('rightPanel.ai.errorFallback'));

      if (responseStatus === 422) {
        const mapped = mapAiTimesheetPreviewError({ message, t });
        const missingFields = mapAiTimesheetMissingFields(responseData?.missing_fields ?? [], t);
        setMessages((prev) => [
          ...prev,
          {
            role: 'assistant',
            content: (
              <AiAssistantMessage
                severity={mapped.severity === 'warning' ? 'warning' : 'error'}
                title={mapped.title}
                message={mapped.message}
                actions={mapped.actions}
              />
            ),
          },
        ]);

        if (missingFields.length > 0) {
          setMessages((prev) => [
            ...prev,
            {
              role: 'assistant',
              content: (
                <AiAssistantMessage
                  severity="info"
                  title={t('aiTimesheet.missingFields.title')}
                  message={t('aiTimesheet.missingFields.message', { fields: missingFields.join(', ') })}
                />
              ),
            },
          ]);
        }
      } else {
        setMessages((prev) => [
          ...prev,
          {
            role: 'assistant',
            content: (
              <AiAssistantMessage
                severity="error"
                title={t('aiTimesheet.previewErrors.generic.title')}
                message={message}
              />
            ),
          },
        ]);
      }
    } finally {
      setLoading(false);
    }
  };

  const handleApply = async () => {
    if (!previewPlan || applyLoading || !preview) return;

    setApplyLoading(true);
    setError(null);

    try {
      const clientRequestId = crypto?.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`;
      const response = await commitTimesheetPlan(previewPlan, clientRequestId);

      showSuccess(`Created ${response.summary.created_count} entries as drafts.`);
      setPreview(null);
      setPreviewPlan(null);
      window.dispatchEvent(new Event('timesheets:refresh'));
    } catch (e: unknown) {
      const responseStatus = (e as { response?: { status?: number } })?.response?.status;
      const responseMessage = (e as { response?: { data?: { message?: string; errors?: string[] } } })?.response?.data;
      const message =
        responseMessage?.message ||
        (responseMessage?.errors?.length ? responseMessage.errors[0] : null) ||
        (e instanceof Error ? e.message : t('rightPanel.ai.errorFallback'));
      const finalMessage = responseStatus === 409 ? message : message;
      setError(finalMessage);
      showError(finalMessage);
    } finally {
      setApplyLoading(false);
    }
  };

  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="h6" sx={{ fontWeight: 800, display: 'flex', alignItems: 'center', gap: 1 }}>
          <SmartToyIcon fontSize="small" /> {t('rightPanel.ai.title')}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          {t('rightPanel.ai.subtitle')}
        </Typography>
      </Box>

      {aiState === 'available_as_addon' ? (
        <Alert
          severity="warning"
          variant="outlined"
          action={
            <Button variant="outlined" size="small" onClick={() => void openCheckoutForAddon('ai')} sx={{ textTransform: 'none' }}>
              {t('rightPanel.ai.viewBilling')}
            </Button>
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
            <Button variant="outlined" size="small" onClick={() => navigate('/billing')} sx={{ textTransform: 'none' }}>
              {t('rightPanel.ai.billingSettings')}
            </Button>
          }
        >
          <AlertTitle>{t('rightPanel.ai.disabledTitle')}</AlertTitle>
          {t('rightPanel.ai.disabledMessage')}
        </Alert>
      ) : null}

      {aiState === 'not_available' ? (
        <Alert severity="info" variant="outlined">
          {t('rightPanel.ai.unavailable')}
        </Alert>
      ) : null}

      {aiState === 'enabled' ? (
        <Alert severity="success" variant="outlined">
          {t('rightPanel.ai.enabledNotice')}
        </Alert>
      ) : null}

      {error ? (
        <Alert severity="error" variant="outlined">
          {t('rightPanel.ai.errorLabel', { message: error })}
        </Alert>
      ) : null}

      {messages.length > 0 ? (
        <Stack spacing={1.5}>
          {messages.map((m, idx) => (
            <Box key={idx}>
              <Typography variant="caption" color="text.secondary">
                {m.role === 'user' ? t('rightPanel.ai.you') : t('rightPanel.ai.assistant')}
              </Typography>
              {typeof m.content === 'string' ? (
                <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>
                  {m.content}
                </Typography>
              ) : (
                m.content
              )}
            </Box>
          ))}
        </Stack>
      ) : (
        <Stack spacing={1.5}>
          <Typography variant="body2" color="text.secondary">
            {t('rightPanel.ai.placeholder')}
          </Typography>
        </Stack>
      )}


      {preview && previewPlan ? (
        <Button variant="contained" onClick={() => void handleApply()} disabled={applyLoading}>
          {applyLoading ? t('common.saving') : t('aiTimesheet.confirmCreate')}
        </Button>
      ) : null}

      {combinedPrompts.length > 0 ? (
        <Stack spacing={1}>
          <Typography variant="caption" color="text.secondary">
            {t('rightPanel.ai.examples.title')}
          </Typography>
          {showExamples ? (
            <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
              {combinedPrompts.map((prompt) => (
                <Chip
                  key={prompt}
                  label={prompt}
                  size="small"
                  variant="outlined"
                  onClick={() => {
                    setQuestion(prompt);
                    setShowExamples(false);
                  }}
                />
              ))}
            </Stack>
          ) : null}
          <Button
            size="small"
            variant="text"
            onClick={() => setShowExamples((prev) => !prev)}
            sx={{ alignSelf: 'flex-start', textTransform: 'none', px: 0 }}
          >
            {showExamples ? t('rightPanel.ai.examples.less') : t('rightPanel.ai.examples.more')}
          </Button>
        </Stack>
      ) : null}

      <Stack spacing={1.5}>
        <TextField
          label={t('rightPanel.ai.inputLabel')}
          placeholder={t('rightPanel.ai.inputPlaceholder')}
          value={question}
          onChange={(e) => setQuestion(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey) {
              e.preventDefault();
              void handleSend();
            }
          }}
          multiline
          minRows={2}
          maxRows={5}
          disabled={aiState !== 'enabled' || loading}
        />

        <Button variant="contained" onClick={() => void handleSend()} disabled={!canSend}>
          {loading ? (
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <CircularProgress size={18} color="inherit" />
              <span>{t('rightPanel.ai.sending')}</span>
            </Box>
          ) : (
            t('rightPanel.ai.send')
          )}
        </Button>
      </Stack>
    </Stack>
  );
};
