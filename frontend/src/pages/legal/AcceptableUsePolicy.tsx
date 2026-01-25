import React from 'react';
import { Box, Container, Link, Typography } from '@mui/material';

const LAST_UPDATED = '2026-01-25';

const AcceptableUsePolicy: React.FC = () => {
  return (
    <Container maxWidth="md" sx={{ py: 6 }}>
      <Typography variant="h4" sx={{ mb: 1, fontWeight: 700 }}>
        Acceptable Use Policy
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
        Last updated: {LAST_UPDATED}
      </Typography>

      <Typography variant="body1" sx={{ mb: 3 }}>
        This Acceptable Use Policy ("AUP") describes prohibited behavior when using TimePerk.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        You must not
      </Typography>
      <Typography component="ul" sx={{ pl: 3, mb: 2, '& li': { mb: 1 } }}>
        <li>Use the Service for unlawful, harmful, or fraudulent activities.</li>
        <li>Upload malware, attempt to exploit vulnerabilities, or disrupt the Service.</li>
        <li>Attempt unauthorized access to accounts, systems, or data.</li>
        <li>Abuse rate limits, probe the API, or perform excessive automated requests.</li>
        <li>Infringe intellectual property or privacy rights of others.</li>
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        Enforcement
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        We may investigate suspected violations and may suspend or terminate access to protect the Service,
        other customers, or comply with law.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        Contact
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        Report abuse or security concerns: <Link href="mailto:hello@upg2ai.com">hello@upg2ai.com</Link>
      </Typography>

      <Box sx={{ mt: 4 }}>
        <Typography variant="body2" color="text.secondary">
          <Link href="/">Back to app</Link>
        </Typography>
      </Box>
    </Container>
  );
};

export default AcceptableUsePolicy;
