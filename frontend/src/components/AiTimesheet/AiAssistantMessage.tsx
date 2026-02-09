import React from 'react';
import { Box, Stack, Typography } from '@mui/material';

export type AiAssistantMessageProps = {
  title?: string;
  severity?: 'info' | 'warning' | 'error';
  message?: string;
  actions?: string[];
  children?: React.ReactNode;
};

const getSeverityStyles = (severity: 'info' | 'warning' | 'error') => {
  switch (severity) {
    case 'warning':
      return { borderColor: 'warning.main', backgroundColor: 'warning.light' };
    case 'error':
      return { borderColor: 'error.main', backgroundColor: 'error.light' };
    default:
      return { borderColor: 'divider', backgroundColor: 'grey.50' };
  }
};

const AiAssistantMessage: React.FC<AiAssistantMessageProps> = ({
  title,
  severity = 'info',
  message,
  actions,
  children,
}) => {
  const styles = getSeverityStyles(severity);

  return (
    <Box
      sx={{
        borderRadius: 2,
        border: 1,
        p: 1.5,
        ...styles,
      }}
    >
      <Stack spacing={1}>
        {title ? (
          <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
            {title}
          </Typography>
        ) : null}
        {message ? (
          <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>
            {message}
          </Typography>
        ) : null}
        {children}
        {actions && actions.length > 0 ? (
          <Stack spacing={0.5}>
            {actions.map((action) => (
              <Typography key={action} variant="caption" color="text.secondary">
                {action}
              </Typography>
            ))}
          </Stack>
        ) : null}
      </Stack>
    </Box>
  );
};

export default AiAssistantMessage;
