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
} from '@mui/material';
import {
  Warning as WarningIcon,
  CheckCircle as CheckIcon,
  Error as ErrorIcon,
} from '@mui/icons-material';
import { API_URL, getAuthHeaders } from '../../services/api';
import { useNotification } from '../../contexts/NotificationContext';

interface ResetDataDialogProps {
  open: boolean;
  onClose: () => void;
}

const ResetDataDialog: React.FC<ResetDataDialogProps> = ({ open, onClose }) => {
  const { showSuccess, showError } = useNotification();
  const [loading, setLoading] = useState(false);
  const [confirmStep, setConfirmStep] = useState(1); // 1 = warning, 2 = confirm, 3 = success

  const handleReset = async () => {
    setLoading(true);
    try {
      const response = await fetch(`${API_URL}/api/admin/reset-data`, {
        method: 'POST',
        headers: getAuthHeaders(),
      });

      if (!response.ok) {
        const error = await response.json();
        throw new Error(error.message || 'Failed to reset data');
      }

      await response.json(); // Consume response
      
      showSuccess('Tenant data has been reset successfully! Logging out...');
      setConfirmStep(3); // Show success step
      
      // Clear auth data and redirect to login after 2 seconds
      setTimeout(() => {
        localStorage.removeItem('auth_token');
        localStorage.removeItem('tenant_slug');
        window.location.href = '/';
      }, 2000);
      
    } catch (error: any) {
      console.error('Reset data error:', error);
      showError(error.message || 'Failed to reset tenant data');
      setConfirmStep(1); // Back to warning
    } finally {
      setLoading(false);
    }
  };

  const handleClose = () => {
    if (!loading) {
      setConfirmStep(1);
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
            Reset Complete
          </>
        ) : (
          <>
            <WarningIcon />
            Reset Tenant Data
          </>
        )}
      </DialogTitle>

      <DialogContent sx={{ mt: 2 }}>
        {/* Step 1: Initial Warning */}
        {confirmStep === 1 && (
          <Box>
            <Alert severity="error" sx={{ mb: 2 }}>
              <Typography variant="subtitle2" fontWeight="bold">
                DANGER: This action cannot be undone!
              </Typography>
            </Alert>

            <Typography variant="body1" gutterBottom>
              This will <strong>permanently delete all data</strong> in this tenant database, including:
            </Typography>

            <List dense sx={{ mb: 2 }}>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary="All timesheets and time entries" />
              </ListItem>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary="All expenses and receipts" />
              </ListItem>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary="All travel segments" />
              </ListItem>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary="All projects, tasks, and locations" />
              </ListItem>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary="All users (except Owner)" />
              </ListItem>
              <ListItem>
                <ListItemIcon sx={{ minWidth: 32 }}>
                  <ErrorIcon color="error" fontSize="small" />
                </ListItemIcon>
                <ListItemText primary="All permissions and role assignments" />
              </ListItem>
            </List>

            <Alert severity="info" sx={{ mb: 2 }}>
              <Typography variant="body2">
                <strong>What will be preserved:</strong>
                <br />
                • Your Owner account (email and password)
                <br />
                • Database structure (tables and migrations)
              </Typography>
            </Alert>

            <Alert severity="success">
              <Typography variant="body2">
                <strong>After reset:</strong>
                <br />
                Fresh demo data will be automatically loaded, including sample projects, tasks, and users.
              </Typography>
            </Alert>
          </Box>
        )}

        {/* Step 2: Final Confirmation */}
        {confirmStep === 2 && (
          <Box>
            <Alert severity="warning" sx={{ mb: 3 }}>
              <Typography variant="subtitle1" fontWeight="bold" gutterBottom>
                FINAL CONFIRMATION
              </Typography>
              <Typography variant="body2">
                Are you absolutely sure you want to proceed?
              </Typography>
            </Alert>

            <Typography variant="body1" color="text.secondary" align="center">
              This action will immediately delete all existing data and restore demo data.
              <br /><br />
              <strong>Click "RESET DATA NOW" to confirm.</strong>
            </Typography>
          </Box>
        )}

        {/* Step 3: Success */}
        {confirmStep === 3 && (
          <Box textAlign="center" py={3}>
            <CheckIcon color="success" sx={{ fontSize: 64, mb: 2 }} />
            <Typography variant="h6" gutterBottom>
              Data Reset Successful!
            </Typography>
            <Typography variant="body2" color="text.secondary">
              All tenant data has been reset and demo data has been loaded.
              <br />
              You will be logged out automatically in 2 seconds...
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
              Cancel
            </Button>
            <Button 
              onClick={handleProceedToConfirm}
              variant="contained"
              color="warning"
              disabled={loading}
            >
              I Understand, Proceed
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
              Go Back
            </Button>
            <Button 
              onClick={handleReset}
              variant="contained"
              color="error"
              disabled={loading}
              startIcon={loading ? <CircularProgress size={20} /> : <WarningIcon />}
            >
              {loading ? 'Resetting...' : 'RESET DATA NOW'}
            </Button>
          </>
        )}

        {confirmStep === 3 && (
          <Button 
            onClick={handleClose}
            variant="contained"
            color="success"
          >
            Close
          </Button>
        )}
      </DialogActions>
    </Dialog>
  );
};

export default ResetDataDialog;
