import React from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Alert,
} from '@mui/material';

interface AddCardModalProps {
  open: boolean;
  onClose: () => void;
  onSuccess?: () => void;
}

/**
 * AddCardModal - Stub (Stripe integration disabled)
 * TODO: Install @stripe/react-stripe-js and @stripe/stripe-js
 */
const AddCardModal: React.FC<AddCardModalProps> = ({ open, onClose }) => {
  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Add Payment Method</DialogTitle>
      <DialogContent>
        <Alert severity="info" sx={{ mt: 2 }}>
          Stripe integration is not available in development mode.
          Payment methods feature is disabled.
        </Alert>
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose} color="primary">
          Close
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default AddCardModal;
