import React from 'react';
import { Box, Typography, Paper, Grid, Card, CardContent, Chip } from '@mui/material';
import { useFeatures } from '../../contexts/FeatureContext';

const BillingPage: React.FC = () => {
  const { enabledModules, isLoading } = useFeatures();

  if (isLoading) {
    return (
      <Box p={3}>
        <Typography>Loading billing information...</Typography>
      </Box>
    );
  }

  return (
    <Box p={3}>
      <Typography variant="h4" gutterBottom>
        Billing & Subscriptions
      </Typography>
      <Typography variant="body2" color="text.secondary" gutterBottom>
        Manage your subscription, licenses, and enabled modules.
      </Typography>

      <Grid container spacing={3} mt={2}>
        {/* Enabled Modules */}
        <Grid item xs={12} md={6}>
          <Paper sx={{ p: 3 }}>
            <Typography variant="h6" gutterBottom>
              Enabled Modules
            </Typography>
            <Box display="flex" flexWrap="wrap" gap={1} mt={2}>
              {enabledModules.length > 0 ? (
                enabledModules.map((module) => (
                  <Chip key={module} label={module} color="primary" />
                ))
              ) : (
                <Typography variant="body2" color="text.secondary">
                  No modules enabled
                </Typography>
              )}
            </Box>
          </Paper>
        </Grid>

        {/* Coming Soon */}
        <Grid item xs={12} md={6}>
          <Paper sx={{ p: 3 }}>
            <Typography variant="h6" gutterBottom>
              License Management
            </Typography>
            <Typography variant="body2" color="text.secondary" mt={2}>
              Full billing interface coming soon...
            </Typography>
          </Paper>
        </Grid>
      </Grid>
    </Box>
  );
};

export default BillingPage;
