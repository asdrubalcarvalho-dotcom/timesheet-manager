import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Stack,
  TextField,
  Typography,
} from '@mui/material';

type ChatMessage = {
  role: 'user' | 'assistant';
  content: string;
};

type Props = {
  onAsk: (question: string) => Promise<string>;
};

const ReportAIChatPanel: React.FC<Props> = ({ onAsk }) => {
  const [question, setQuestion] = useState('');
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const canSend = useMemo(() => question.trim().length > 0 && !loading, [question, loading]);

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
    } catch (e: any) {
      const message =
        typeof e?.response?.data?.message === 'string'
          ? e.response.data.message
          : typeof e?.message === 'string'
            ? e.message
            : 'Failed to get AI response';
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
          {error}
        </Alert>
      ) : null}

      {messages.length === 0 ? (
        <Alert severity="info" sx={{ mb: 2 }}>
          Ask a question about the current report and date range.
        </Alert>
      ) : (
        <Stack spacing={1.5} sx={{ mb: 2 }}>
          {messages.map((m, idx) => (
            <Box key={idx}>
              <Typography variant="caption" color="text.secondary">
                {m.role === 'user' ? 'You' : 'AI'}
              </Typography>
              <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>
                {m.content}
              </Typography>
            </Box>
          ))}
        </Stack>
      )}

      <Stack spacing={1}>
        <TextField
          label="Question"
          value={question}
          onChange={(e) => setQuestion(e.target.value)}
          multiline
          minRows={2}
          maxRows={6}
          disabled={loading}
        />
        <Button variant="contained" onClick={() => void handleSend()} disabled={!canSend}>
          {loading ? (
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <CircularProgress size={18} color="inherit" />
              <span>Thinking…</span>
            </Box>
          ) : (
            'Send'
          )}
        </Button>
      </Stack>
    </Box>
  );
};

export default ReportAIChatPanel;
