import React from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Typography,
  Box,
  Chip,
} from '@mui/material';
import LockIcon from '@mui/icons-material/Lock';
import RocketLaunchIcon from '@mui/icons-material/RocketLaunch';

interface UpgradeModalProps {
  open: boolean;
  onClose: () => void;
  moduleName: string;
}

const UpgradeModal: React.FC<UpgradeModalProps> = ({ open, onClose, moduleName }) => {
  const handleUpgrade = () => {
    // TODO: Navigate to billing page
    window.location.href = '/billing';
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth="sm" fullWidth>
      <DialogTitle>
        <Box display="flex" alignItems="center" gap={1}>
          <LockIcon color="warning" />
          <Typography variant="h6">Upgrade Required</Typography>
        </Box>
      </DialogTitle>
      <DialogContent>
        <Box display="flex" flexDirection="column" gap={2} py={1}>
          <Typography variant="body1">
            The <strong>{moduleName}</strong> module is not enabled for your subscription.
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Unlock powerful features to enhance your workflow:
          </Typography>
          <Box display="flex" flexWrap="wrap" gap={1}>
            {moduleName === 'Planning & Gantt' && (
              <>
                <Chip label="Gantt Charts" size="small" color="primary" variant="outlined" />
                <Chip label="Task Dependencies" size="small" color="primary" variant="outlined" />
                <Chip label="Resource Planning" size="small" color="primary" variant="outlined" />
                <Chip label="Timeline Views" size="small" color="primary" variant="outlined" />
              </>
            )}
            {moduleName === 'Travel Management' && (
              <>
                <Chip label="Travel Tracking" size="small" color="primary" variant="outlined" />
                <Chip label="Route Optimization" size="small" color="primary" variant="outlined" />
                <Chip label="Expense Integration" size="small" color="primary" variant="outlined" />
                <Chip label="AI Suggestions" size="small" color="primary" variant="outlined" />
              </>
            )}
            {moduleName === 'Reporting' && (
              <>
                <Chip label="Advanced Analytics" size="small" color="primary" variant="outlined" />
                <Chip label="Custom Reports" size="small" color="primary" variant="outlined" />
                <Chip label="Data Export" size="small" color="primary" variant="outlined" />
                <Chip label="Insights Dashboard" size="small" color="primary" variant="outlined" />
              </>
            )}
          </Box>
          <Box
            sx={{
              backgroundColor: 'primary.lighter',
              borderRadius: 1,
              p: 2,
              mt: 1,
            }}
          >
            <Typography variant="body2" fontWeight={600} color="primary.main">
              Start your free 14-day trial today!
            </Typography>
            <Typography variant="caption" color="text.secondary">
              No credit card required. Cancel anytime.
            </Typography>
          </Box>
        </Box>
      </DialogContent>
      <DialogActions sx={{ p: 2 }}>
        <Button onClick={onClose} color="inherit">
          Maybe Later
        </Button>
        <Button
          variant="contained"
          color="primary"
          startIcon={<RocketLaunchIcon />}
          onClick={handleUpgrade}
        >
          Upgrade Now
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default UpgradeModal;
