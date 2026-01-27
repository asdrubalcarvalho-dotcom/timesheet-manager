import React from 'react';
import { Alert, Box, Link, Stack, Typography } from '@mui/material';

export const HelpTab: React.FC = () => {
  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="h6" sx={{ fontWeight: 800, mb: 0.5 }}>
          Help
        </Typography>
        <Typography variant="body2" color="text.secondary">
          Quick links and guidance.
        </Typography>
      </Box>

      <Alert severity="info" variant="outlined">
        Tip: Use the menu on the left to navigate modules.
      </Alert>

      <Box>
        <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>
          Documentation
        </Typography>
        <Stack spacing={0.5}>
          <Link href="/legal/terms" underline="hover">
            Terms of Service
          </Link>
          <Link href="/legal/privacy" underline="hover">
            Privacy Policy
          </Link>
          <Link href="/legal/acceptable-use" underline="hover">
            Acceptable Use Policy
          </Link>
        </Stack>
      </Box>
    </Stack>
  );
};
