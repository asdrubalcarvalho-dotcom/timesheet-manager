import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  Divider,
  Drawer,
  IconButton,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import CloseIcon from '@mui/icons-material/Close';

type ChatMessage = {
  role: 'user' | 'assistant';
  content: string;
};

type Props = {
  open: boolean;
  onClose: () => void;
  title: string;
  onAsk: (question: string) => Promise<string>;
};

const ReportAIDrawer: React.FC<Props> = ({ open, onClose, title, onAsk }) => {
  const [question, setQuestion] = useState('');
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    if (!open) {
      setError(null);
      setLoading(false);
      setQuestion('');
    }
  }, [open]);

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

  return (
    <Drawer
      anchor="right"
      open={open}
      onClose={onClose}
      PaperProps={{ sx: { width: { xs: '100%', sm: 420 } } }}
    >
      <Box sx={{ p: 2, display: 'flex', alignItems: 'center', gap: 1 }}>
        <Typography variant="h6" sx={{ flex: 1 }}>
          {title}
        </Typography>
        <IconButton aria-label="Close" onClick={onClose}>
          <CloseIcon />
        </IconButton>
      </Box>

      <Divider />

      <Box sx={{ p: 2, flex: 1, overflow: 'auto' }}>
        {error && (
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
        )}

        {messages.length === 0 ? (
          <Alert severity="info">Ask a question about the current report and date range.</Alert>
        ) : (
          <Stack spacing={1.5}>
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
      </Box>

      <Divider />

      <Box sx={{ p: 2 }}>
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
    </Drawer>
  );
};

export default ReportAIDrawer;
