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
import InfoIcon from '@mui/icons-material/Info';
import { useAuth } from '../Auth/AuthContext';
import { formatTenantDate } from '../../utils/tenantFormatting';

interface ConfirmTrialExitDialogProps {
  open: boolean;
  onClose: () => void;
  onConfirm: () => void;
  targetPlan: string;
  trialEndsAt: string | null;
}

export const ConfirmTrialExitDialog: React.FC<ConfirmTrialExitDialogProps> = ({
  open,
  onClose,
  onConfirm,
  targetPlan,
  trialEndsAt,
}) => {
  const { tenantContext } = useAuth();
  const trialEndsAtLabel = trialEndsAt ? formatTenantDate(trialEndsAt, tenantContext) : 'unknown';

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>
        <Box display="flex" alignItems="center" gap={1}>
          <InfoIcon color="info" />
          <Typography variant="h6">End Trial Early?</Typography>
        </Box>
      </DialogTitle>
      <DialogContent>
        <DialogContentText component="div">
          <Box mb={2}>
            You are about to end your <strong>Enterprise Trial</strong> and switch to the{' '}
            <strong>{targetPlan.toUpperCase()}</strong> plan.
          </Box>

          <Alert severity="info" sx={{ mb: 2 }}>
            <Typography variant="body2" fontWeight="bold" gutterBottom>
              What will happen:
            </Typography>
            <Typography variant="body2" component="ul" sx={{ pl: 2, mb: 0 }}>
              <li><strong>Trial ends immediately</strong> (originally until {trialEndsAtLabel})</li>
              <li>Plan changes to <strong>{targetPlan}</strong> right away (no scheduling)</li>
              <li>Features adjust according to the new plan</li>
              <li>Your billing cycle starts today</li>
              <li>Next renewal will be in 1 month from today</li>
            </Typography>
          </Alert>

          <Typography variant="body2" color="text.secondary" sx={{ fontWeight: 500 }}>
            This action cannot be undone. Are you sure?
          </Typography>
        </DialogContentText>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} color="inherit">
          Keep Trial
        </Button>
        <Button onClick={onConfirm} variant="contained" color="primary" autoFocus>
          Confirm & Switch to {targetPlan.charAt(0).toUpperCase() + targetPlan.slice(1)}
        </Button>
      </DialogActions>
    </Dialog>
  );
};
