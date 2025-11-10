import React from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogContentText,
  DialogActions,
  Button,
  Typography,
  Box,
  Divider
} from '@mui/material';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';

interface RecordDetails {
  code?: string;
  name?: string;
  description?: string;
  [key: string]: any;
}

interface ConfirmationDialogProps {
  open: boolean;
  title: string;
  message: string;
  recordDetails?: RecordDetails;
  confirmText?: string;
  cancelText?: string;
  confirmColor?: 'error' | 'warning' | 'primary' | 'secondary' | 'info' | 'success';
  onConfirm: () => void;
  onCancel: () => void;
}

const ConfirmationDialog: React.FC<ConfirmationDialogProps> = ({
  open,
  title,
  message,
  recordDetails,
  confirmText = 'Confirm',
  cancelText = 'Cancel',
  confirmColor = 'error',
  onConfirm,
  onCancel,
}) => {
  return (
    <Dialog
      open={open}
      onClose={onCancel}
      maxWidth="sm"
      fullWidth
    >
      <DialogTitle sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
        <WarningAmberIcon color={confirmColor} />
        {title}
      </DialogTitle>
      <DialogContent>
        <DialogContentText sx={{ mb: recordDetails ? 2 : 0 }}>
          {message}
        </DialogContentText>
        
        {recordDetails && (
          <Box sx={{ mt: 2, p: 2, bgcolor: 'grey.100', borderRadius: 1 }}>
            <Typography variant="subtitle2" color="text.secondary" gutterBottom>
              Record Details
            </Typography>
            <Divider sx={{ my: 1 }} />
            
            {recordDetails.code && (
              <Typography variant="body2" sx={{ mb: 0.5 }}>
                <strong>Code:</strong> {recordDetails.code}
              </Typography>
            )}
            
            {recordDetails.name && (
              <Typography variant="body2" sx={{ mb: 0.5 }}>
                <strong>Name:</strong> {recordDetails.name}
              </Typography>
            )}
            
            {recordDetails.description && (
              <Typography variant="body2" sx={{ mb: 0.5 }}>
                <strong>Description:</strong> {recordDetails.description}
              </Typography>
            )}
            
            {/* Additional fields */}
            {Object.entries(recordDetails)
              .filter(([key]) => !['code', 'name', 'description'].includes(key))
              .map(([key, value]) => (
                <Typography key={key} variant="body2" sx={{ mb: 0.5 }}>
                  <strong>{key.charAt(0).toUpperCase() + key.slice(1)}:</strong> {String(value)}
                </Typography>
              ))}
          </Box>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onCancel} color="inherit">
          {cancelText}
        </Button>
        <Button onClick={onConfirm} color={confirmColor} variant="contained" autoFocus>
          {confirmText}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default ConfirmationDialog;
