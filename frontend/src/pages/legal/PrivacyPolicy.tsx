import React from 'react';
import { Box, Container, Link, Typography } from '@mui/material';

const LAST_UPDATED = '2026-01-25';

const PrivacyPolicy: React.FC = () => {
  return (
    <Container maxWidth="md" sx={{ py: 6 }}>
      <Typography variant="h4" sx={{ mb: 1, fontWeight: 700 }}>
        Privacy Policy
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
        Last updated: {LAST_UPDATED}
      </Typography>

      <Typography variant="body1" sx={{ mb: 3 }}>
        This Privacy Policy explains how UPG2AI ("we", "us") processes personal data when providing TimePerk.
        TimePerk is a B2B SaaS product; Customers control which users and data are added to the Service.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        1. Data we process
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        Depending on how you use the Service, we may process account details (name, email), company details,
        timesheets, expenses, approval metadata, and technical data such as IP address, device and browser
        information, and logs.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        2. Purpose and legal basis
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        We process personal data to provide and secure the Service, operate billing, provide support, comply
        with legal obligations, and improve product reliability and performance.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        3. Payments (Stripe)
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        Billing may be processed via Stripe. Payment card details are handled by Stripe and are not stored on
        our servers. Stripe may process personal data under its own privacy policy.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        4. Sharing
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        We may share data with vendors that help us operate the Service (hosting, email, analytics, customer
        support, billing). We do not sell personal data.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        5. Retention
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        We retain data for as long as needed to provide the Service, comply with legal obligations, resolve
        disputes, and enforce agreements. Customers may request export or deletion subject to legal and
        contractual requirements.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        6. Security
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        We use reasonable technical and organizational measures to protect data. No method of transmission or
        storage is 100% secure.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        7. Your rights
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        Depending on your location, you may have rights to access, correct, delete, or restrict processing of
        your personal data. For most in-product data, your employer or Customer account administrator is the
        primary point of contact.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        8. Contact
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        Privacy questions: <Link href="mailto:hello@upg2ai.com">hello@upg2ai.com</Link>
      </Typography>

      <Box sx={{ mt: 4 }}>
        <Typography variant="body2" color="text.secondary">
          <Link href="/">Back to app</Link>
        </Typography>
      </Box>
    </Container>
  );
};

export default PrivacyPolicy;
