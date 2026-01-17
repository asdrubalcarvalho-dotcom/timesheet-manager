import React from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogContentText,
  DialogActions,
  Button,
  Box,
  Typography,
  Alert,
} from '@mui/material';
import WarningAmberIcon from '@mui/icons-material/WarningAmber';
import { useAuth } from '../Auth/AuthContext';
import { formatTenantDate } from '../../utils/tenantFormatting';

interface ConfirmDowngradeDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  currentPlan: string;
  targetPlan: string;
  effectiveDate: string | null;
}

export const ConfirmDowngradeDialog: React.FC<ConfirmDowngradeDialogProps> = ({
  open,
  onClose,
  onConfirm,
  currentPlan,
  targetPlan,
  effectiveDate,
}) => {
  const { tenantContext } = useAuth();

  const formatDate = (dateString: string | null): string => {
    if (!dateString) return 'next billing cycle';
    return formatTenantDate(dateString, tenantContext);
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>
        <Box display="flex" alignItems="center" gap={1}>
          <WarningAmberIcon color="warning" />
          <Typography variant="h6">Confirm Plan Downgrade</Typography>
        </Box>
      </DialogTitle>
      <DialogContent>
        <DialogContentText component="div">
          <Box mb={2}>
            You are about to schedule a downgrade from{' '}
            <strong>{currentPlan.toUpperCase()}</strong> to{' '}
            <strong>{targetPlan.toUpperCase()}</strong> plan.
          </Box>

          <Alert severity="warning" sx={{ mb: 2 }}>
            <Typography variant="body2" fontWeight="bold" gutterBottom>
              What will happen:
            </Typography>
            <Typography variant="body2" component="ul" sx={{ pl: 2, mb: 0 }}>
              <li><strong>No changes today</strong> - your current plan remains active</li>
              <li>Downgrade will be applied on {formatDate(effectiveDate)}</li>
              <li>Features and billing will change at that time</li>
              <li>You can cancel this downgrade up to 24 hours before renewal</li>
            </Typography>
          </Alert>

          <Typography variant="body2" color="text.secondary">
            Are you sure you want to continue with this downgrade?
          </Typography>
        </DialogContentText>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} color="inherit">
          Cancel
        </Button>
        <Button onClick={onConfirm} variant="contained" color="warning" autoFocus>
          Confirm Downgrade
        </Button>
      </DialogActions>
    </Dialog>
  );
};
