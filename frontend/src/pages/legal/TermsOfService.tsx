import React from 'react';
import { Box, Container, Link, Typography } from '@mui/material';

const LAST_UPDATED = '2026-01-25';

const TermsOfService: React.FC = () => {
  return (
    <Container maxWidth="md" sx={{ py: 6 }}>
      <Typography variant="h4" sx={{ mb: 1, fontWeight: 700 }}>
        Terms of Service
      </Typography>
      <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
        Last updated: {LAST_UPDATED}
      </Typography>

      <Typography variant="body1" sx={{ mb: 3 }}>
        These Terms of Service ("Terms") govern your use of TimePerk (the "Service") provided by UPG2AI
        ("we", "us", "our") for business customers ("Customer", "you"). By using the Service, you agree to
        these Terms.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        1. Accounts and access
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        You are responsible for all activity under your account. You must ensure that users you invite are
        authorized and that credentials and API tokens are kept secure.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        2. Subscription, billing, and taxes
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        The Service is provided on a subscription basis. Payments may be processed by Stripe. Prices are shown
        in the app and may vary by plan and add-ons. Taxes may apply depending on your location; where
        applicable, VAT may be charged. For EU B2B customers, reverse-charge may apply when a valid VAT ID is
        provided.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        3. Upgrades, cancellations, refunds
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        You can upgrade or cancel your subscription from the billing area (or by contacting support if that
        option is not available). Unless required by law, fees are non-refundable, and cancellations take effect
        at the end of the current billing period.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        4. Acceptable use
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        You must follow our Acceptable Use Policy. You may not misuse the Service, interfere with its
        operation, attempt unauthorized access, or use it to process unlawful content.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        5. Customer data
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        You retain ownership of your content and business data. We process your data to provide the Service as
        described in our Privacy Policy.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        6. Availability and changes
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        We aim to keep the Service available, but it may be unavailable due to maintenance, upgrades, or
        factors outside our control. We may update the Service and these Terms from time to time.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        7. Disclaimers and limitation of liability
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        The Service is provided on an "as is" and "as available" basis. To the maximum extent permitted by law,
        we disclaim all warranties and will not be liable for indirect, incidental, special, consequential, or
        punitive damages, or any loss of profits, revenue, data, or goodwill.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        8. Support
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        Support channels and response targets may depend on your plan.
      </Typography>

      <Typography variant="h6" sx={{ mt: 3, mb: 1 }}>
        9. Contact
      </Typography>
      <Typography variant="body1" sx={{ mb: 2 }}>
        Questions about these Terms: <Link href="mailto:hello@upg2ai.com">hello@upg2ai.com</Link>
      </Typography>

      <Box sx={{ mt: 4 }}>
        <Typography variant="body2" color="text.secondary">
          <Link href="/">Back to app</Link>
        </Typography>
      </Box>
    </Container>
  );
};

export default TermsOfService;
