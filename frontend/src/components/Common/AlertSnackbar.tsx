import React from 'react';
import { Snackbar, Alert, type AlertColor } from '@mui/material';

interface AlertSnackbarProps {
  open: boolean;
  message: string;
  severity?: AlertColor;
  autoHideDuration?: number;
  onClose: () => void;
}

const AlertSnackbar: React.FC<AlertSnackbarProps> = ({
  open,
  message,
  severity = 'success',
  autoHideDuration = 4000,
  onClose,
}) => {
  // Clean up informative text from backend error messages
  const cleanMessage = message
    .replace(/Only administrators can edit timesheets in this state\.\s*/gi, '')
    .replace(/Only administrators can delete timesheets in this state\.\s*/gi, '')
    .replace(/Only administrators can edit expenses in this state\.\s*/gi, '')
    .replace(/Only administrators can delete expenses in this state\.\s*/gi, '')
    .trim();

  return (
    <Snackbar
      open={open}
      autoHideDuration={autoHideDuration}
      onClose={onClose}
      anchorOrigin={{ vertical: 'top', horizontal: 'right' }}
      sx={{ mt: 8 }} // Margin top to avoid header overlap
    >
      <Alert 
        onClose={onClose} 
        severity={severity} 
        variant="filled" 
        sx={{ 
          minWidth: '280px',
          maxWidth: '400px',
          boxShadow: 3,
          '& .MuiAlert-message': {
            fontSize: '0.9rem',
            fontWeight: 500
          },
          // Custom colors
          ...(severity === 'success' && {
            backgroundColor: '#4caf50', // Green
            color: '#fff'
          }),
          ...(severity === 'warning' && {
            backgroundColor: '#ff9800', // Orange/Burnt yellow
            color: '#fff'
          }),
          ...(severity === 'info' && {
            backgroundColor: '#ff9800', // Orange/Burnt yellow (same as warning)
            color: '#fff'
          }),
          ...(severity === 'error' && {
            backgroundColor: '#f44336', // Red
            color: '#fff'
          })
        }}
      >
        {cleanMessage}
      </Alert>
    </Snackbar>
  );
};

export default AlertSnackbar;
