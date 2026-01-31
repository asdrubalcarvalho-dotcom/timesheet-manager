import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Chip,
  CircularProgress,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import { useTranslation } from 'react-i18next';

type ChatMessage = {
  role: 'user' | 'assistant';
  content: string;
};

type Props = {
  onAsk: (question: string) => Promise<string>;
  suggestions?: string[];
  prefill?: string;
};

const ReportAIChatPanel: React.FC<Props> = ({ onAsk, suggestions = [], prefill }) => {
  const { t } = useTranslation();
  const [question, setQuestion] = useState('');
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canSend = useMemo(() => question.trim().length > 0 && !loading, [question, loading]);

  useEffect(() => {
    if (prefill && !loading && question.trim().length === 0) {
      setQuestion(prefill);
    }
  }, [prefill, loading, question]);

  const handleSend = async () => {
    const q = question.trim();
    if (!q || loading) return;

    setError(null);
    setLoading(true);
    setQuestion('');
    setMessages((prev) => [...prev, { role: 'user', content: q }]);

    try {
      const answer = await onAsk(q);
      setMessages((prev) => [...prev, { role: 'assistant', content: answer }]);
    } catch (e: unknown) {
      const message =
        typeof (e as { response?: { data?: { message?: unknown } } })?.response?.data?.message === 'string'
          ? (e as { response: { data: { message: string } } }).response.data.message
          : e instanceof Error
            ? e.message
            : t('rightPanel.ai.errorFallback');
      setError(message);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    // If messages were cleared externally in future, ensure stale error/loading doesn't linger.
    if (messages.length === 0 && !loading) {
      setError(null);
    }
  }, [messages.length, loading]);

  return (
    <Box>
      {error ? (
        <Alert severity="error" sx={{ mb: 2 }}>
          {t('rightPanel.ai.errorLabel', { message: error })}
        </Alert>
      ) : null}

      {messages.length === 0 ? (
        <Alert severity="info" sx={{ mb: 2 }}>
          {t('rightPanel.reports.aiIntro')}
        </Alert>
      ) : (
        <Stack spacing={1.5} sx={{ mb: 2 }}>
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
      )}

      <Stack spacing={1}>
        {suggestions.length > 0 && (
          <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
            {suggestions.map((label) => (
              <Chip key={label} label={label} size="small" variant="outlined" onClick={() => setQuestion(label)} />
            ))}
          </Stack>
        )}
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
          maxRows={6}
          disabled={loading}
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
    </Box>
  );
};

export default ReportAIChatPanel;
