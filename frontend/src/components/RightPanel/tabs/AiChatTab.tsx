import React, { useMemo, useState } from 'react';
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

type ChatMessage = {
  role: 'user' | 'assistant';
  content: string;
};

export const AiChatTab: React.FC = () => {
  const { t } = useTranslation();
  const { billingSummary, tenantAiEnabled, openCheckoutForAddon } = useBilling();
  const aiState = useMemo(() => getTenantAiState(billingSummary, tenantAiEnabled), [billingSummary, tenantAiEnabled]);
  const navigate = useNavigate();

  const [question, setQuestion] = useState('');
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const suggestions = useMemo(
    () => [
      t('rightPanel.ai.suggestions.flags'),
      t('rightPanel.ai.suggestions.overtime'),
      t('rightPanel.ai.suggestions.approvals'),
      t('rightPanel.ai.suggestions.projectCosts'),
    ],
    [t]
  );

  const canSend = useMemo(
    () => question.trim().length > 0 && !loading && aiState === 'enabled',
    [question, loading, aiState]
  );

  const handleSend = async () => {
    const q = question.trim();
    if (!q || loading || aiState !== 'enabled') return;

    setError(null);
    setLoading(true);
    setQuestion('');
    setMessages((prev) => [...prev, { role: 'user', content: q }]);

    try {
      await new Promise((resolve) => setTimeout(resolve, 600));
      setMessages((prev) => [...prev, { role: 'assistant', content: t('rightPanel.ai.placeholderResponse') }]);
    } catch (e: unknown) {
      const message = e instanceof Error ? e.message : t('rightPanel.ai.errorFallback');
      setError(message);
    } finally {
      setLoading(false);
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
              <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>
                {m.content}
              </Typography>
            </Box>
          ))}
        </Stack>
      ) : (
        <Typography variant="body2" color="text.secondary">
          {t('rightPanel.ai.placeholder')}
        </Typography>
      )}

      <Stack spacing={1.5}>
        <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
          {suggestions.map((label) => (
            <Chip
              key={label}
              label={label}
              size="small"
              variant="outlined"
              onClick={() => setQuestion(label)}
            />
          ))}
        </Stack>

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
