import React, { useState } from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Typography,
  Box,
  Alert,
  CircularProgress,
  List,
  ListItem,
  ListItemIcon,
  ListItemText,
  RadioGroup,
  FormControlLabel,
  Radio,
  Paper,
} from '@mui/material';
import {
  Warning as WarningIcon,
  CheckCircle as CheckIcon,
  Error as ErrorIcon,
  Delete as DeleteIcon,
  Science as ScienceIcon,
} from '@mui/icons-material';
import { API_URL, getAuthHeaders } from '../../services/api';
import { useNotification } from '../../contexts/NotificationContext';
import { useTranslation } from 'react-i18next';

interface ResetDataDialogProps {
  open: boolean;
  onClose: () => void;
}

const ResetDataDialog: React.FC<ResetDataDialogProps> = ({ open, onClose }) => {
  const { t } = useTranslation();
  const { showSuccess, showError } = useNotification();
  const [loading, setLoading] = useState(false);
  const [confirmStep, setConfirmStep] = useState(1); // 1 = warning, 2 = confirm, 3 = success
  const [resetOption, setResetOption] = useState<'clean' | 'demo'>('demo'); // Default to demo data

  const handleReset = async () => {
    setLoading(true);
    try {
      const response = await fetch(`${API_URL}/api/admin/reset-data`, {
        method: 'POST',
        headers: {
          ...getAuthHeaders(),
          'Content-Type': 'application/json',
        },
        body: JSON.stringify({
          with_demo_data: resetOption === 'demo'
        }),
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || t('admin.resetDataDialog.notifications.resetFailed'));
      }

      await response.json(); // Consume response
      
      showSuccess(t('admin.resetDataDialog.notifications.resetSuccessLoggingOut'));
      setConfirmStep(3); // Show success step
      
      // Clear auth data and redirect to login after 2 seconds
      setTimeout(() => {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('tenant_slug');
        window.location.href = '/';
      }, 2000);
      
    } catch (error: any) {
      console.error('Reset data error:', error);
      showError(error.message || t('admin.resetDataDialog.notifications.resetTenantFailed'));
      setConfirmStep(1); // Back to warning
    } finally {
      setLoading(false);
    }
  };

  const handleClose = () => {
    if (!loading) {
      setConfirmStep(1);
      setResetOption('demo'); // Reset to default
      onClose();
    }
  };

  const handleProceedToConfirm = () => {
    setConfirmStep(2);
  };

  const handleBackToWarning = () => {
    setConfirmStep(1);
  };

  return (
    <Dialog 
      open={open} 
      onClose={handleClose}
      maxWidth="sm"
      fullWidth
      disableEscapeKeyDown={loading}
    >
      <DialogTitle sx={{ 
        bgcolor: confirmStep === 3 ? 'success.main' : 'error.main', 
        color: 'white',
        display: 'flex',
        alignItems: 'center',
        gap: 1
      }}>
        {confirmStep === 3 ? (
          <>
            <CheckIcon />
            {t('admin.resetDataDialog.title.success')}
          </>
        ) : (
          <>
            <WarningIcon />
            {t('admin.resetDataDialog.title.default')}
          </>
        )}
      </DialogTitle>

      <DialogContent sx={{ mt: 2 }}>
        {/* Step 1: Initial Warning */}
        {confirmStep === 1 && (
          <Box>
            <Alert severity="error" sx={{ mb: 2 }}>
              <Typography variant="subtitle2" fontWeight="bold">
                {t('admin.resetDataDialog.step1.danger')}
              </Typography>
            </Alert>

            <Typography variant="body1" gutterBottom>
              {t('admin.resetDataDialog.step1.willDeletePrefix')}{' '}
              <strong>{t('admin.resetDataDialog.step1.willDeleteEmphasis')}</strong>{' '}
              {t('admin.resetDataDialog.step1.willDeleteSuffix')}
            </Typography>

            <List dense sx={{ mb: 2 }}>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary={t('admin.resetDataDialog.step1.items.timesheets')} />
              </ListItem>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary={t('admin.resetDataDialog.step1.items.expenses')} />
              </ListItem>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary={t('admin.resetDataDialog.step1.items.travels')} />
              </ListItem>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary={t('admin.resetDataDialog.step1.items.projects')} />
              </ListItem>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary={t('admin.resetDataDialog.step1.items.users')} />
              </ListItem>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary={t('admin.resetDataDialog.step1.items.permissions')} />
              </ListItem>
            </List>

            <Alert severity="info" sx={{ mb: 2 }}>
              <Typography variant="body2">
                <strong>{t('admin.resetDataDialog.step1.preserved.title')}</strong>
                <br />
                • {t('admin.resetDataDialog.step1.preserved.ownerAccount')}
                <br />
                • {t('admin.resetDataDialog.step1.preserved.dbStructure')}
              </Typography>
            </Alert>

            <Alert severity="warning" sx={{ mb: 2 }}>
              <Typography variant="body2" fontWeight="medium" gutterBottom>
                {t('admin.resetDataDialog.step1.importantTitle')}
              </Typography>
              <Typography variant="body2" component="div">
                • {t('admin.resetDataDialog.step1.important.sessionClosed')}
                <br />
                • {t('admin.resetDataDialog.step1.important.loginAgain')}
                <br />
                • {t('admin.resetDataDialog.step1.important.chooseOption')}
              </Typography>
            </Alert>

            <Paper variant="outlined" sx={{ p: 2, mb: 2, bgcolor: 'background.default' }}>
              <Typography variant="subtitle2" fontWeight="bold" gutterBottom>
                {t('admin.resetDataDialog.step1.preferenceTitle')}
              </Typography>
              
              <RadioGroup
                value={resetOption}
                onChange={(e) => setResetOption(e.target.value as 'clean' | 'demo')}
              >
                <FormControlLabel
                  value="demo"
                  control={<Radio />}
                  label={
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <ScienceIcon fontSize="small" color="primary" />
                      <Box>
                        <Typography variant="body2" fontWeight="medium">
                          {t('admin.resetDataDialog.step1.options.demo.title')}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {t('admin.resetDataDialog.step1.options.demo.description')}
                        </Typography>
                      </Box>
                    </Box>
                  }
                />
                <FormControlLabel
                  value="clean"
                  control={<Radio />}
                  label={
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <DeleteIcon fontSize="small" color="action" />
                      <Box>
                        <Typography variant="body2" fontWeight="medium">
                          {t('admin.resetDataDialog.step1.options.clean.title')}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {t('admin.resetDataDialog.step1.options.clean.description')}
                        </Typography>
                      </Box>
                    </Box>
                  }
                />
              </RadioGroup>
            </Paper>
          </Box>
        )}

        {/* Step 2: Final Confirmation */}
        {confirmStep === 2 && (
          <Box>
            <Alert severity="warning" sx={{ mb: 3 }}>
              <Typography variant="subtitle1" fontWeight="bold" gutterBottom>
                {t('admin.resetDataDialog.step2.title')}
              </Typography>
              <Typography variant="body2">
                {t('admin.resetDataDialog.step2.subtitle')}
              </Typography>
            </Alert>

            <Alert severity="info" sx={{ mb: 2 }}>
              <Typography variant="body2" fontWeight="medium">
                {t('admin.resetDataDialog.step2.selectedOptionLabel')}
              </Typography>
              <Typography variant="body2">
                {resetOption === 'demo' 
                  ? t('admin.resetDataDialog.step2.selectedOption.demo')
                  : t('admin.resetDataDialog.step2.selectedOption.clean')}
              </Typography>
            </Alert>

            <Typography variant="body1" color="text.secondary" align="center">
              {t('admin.resetDataDialog.step2.notice')}
              <br /><br />
              <strong>{t('admin.resetDataDialog.step2.confirmHint')}</strong>
            </Typography>
          </Box>
        )}

        {/* Step 3: Success */}
        {confirmStep === 3 && (
          <Box textAlign="center" py={3}>
            <CheckIcon color="success" sx={{ fontSize: 64, mb: 2 }} />
            <Typography variant="h6" gutterBottom>
              {t('admin.resetDataDialog.step3.title')}
            </Typography>
            <Typography variant="body2" color="text.secondary">
              {resetOption === 'demo' 
                ? t('admin.resetDataDialog.step3.message.demo')
                : t('admin.resetDataDialog.step3.message.clean')}
              <br />
              {t('admin.resetDataDialog.step3.logoutHint')}
            </Typography>
          </Box>
        )}
      </DialogContent>

      <DialogActions sx={{ p: 2, gap: 1 }}>
        {confirmStep === 1 && (
          <>
            <Button 
              onClick={handleClose} 
              variant="outlined"
              disabled={loading}
            >
              {t('common.cancel')}
            </Button>
            <Button 
              onClick={handleProceedToConfirm}
              variant="contained"
              color="warning"
              disabled={loading}
            >
              {t('admin.resetDataDialog.actions.proceed')}
            </Button>
          </>
        )}

        {confirmStep === 2 && (
          <>
            <Button 
              onClick={handleBackToWarning}
              variant="outlined"
              disabled={loading}
            >
              {t('admin.resetDataDialog.actions.goBack')}
            </Button>
            <Button 
              onClick={handleReset}
              variant="contained"
              color="error"
              disabled={loading}
              startIcon={loading ? <CircularProgress size={20} /> : <WarningIcon />}
            >
              {loading ? t('admin.resetDataDialog.actions.resetting') : t('admin.resetDataDialog.actions.resetNow')}
            </Button>
          </>
        )}

        {confirmStep === 3 && (
          <Button 
            onClick={handleClose}
            variant="contained"
            color="success"
          >
            {t('common.close')}
          </Button>
        )}
      </DialogActions>
    </Dialog>
  );
};

export default ResetDataDialog;
