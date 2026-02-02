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
import { useTranslation } from 'react-i18next';

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
  confirmText,
  cancelText,
  confirmColor = 'error',
  onConfirm,
  onCancel,
}) => {
  const { t } = useTranslation();

  const resolvedConfirmText = confirmText ?? t('common.confirm');
  const resolvedCancelText = cancelText ?? t('common.cancel');

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
              {t('common.recordDetails')}
            </Typography>
            <Divider sx={{ my: 1 }} />
            
            {recordDetails.code && (
              <Typography variant="body2" sx={{ mb: 0.5 }}>
                <strong>{t('common.code')}:</strong> {recordDetails.code}
              </Typography>
            )}
            
            {recordDetails.name && (
              <Typography variant="body2" sx={{ mb: 0.5 }}>
                <strong>{t('common.name')}:</strong> {recordDetails.name}
              </Typography>
            )}
            
            {recordDetails.description && (
              <Typography variant="body2" sx={{ mb: 0.5 }}>
                <strong>{t('common.description')}:</strong> {recordDetails.description}
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
          {resolvedCancelText}
        </Button>
        <Button onClick={onConfirm} color={confirmColor} variant="contained" autoFocus>
          {resolvedConfirmText}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default ConfirmationDialog;
