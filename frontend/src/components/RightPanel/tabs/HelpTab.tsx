import React from 'react';
import { Alert, Box, Link, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';

export const HelpTab: React.FC = () => {
  const { t } = useTranslation();

  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="h6" sx={{ fontWeight: 800, mb: 0.5 }}>
          {t('rightPanel.help.title')}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          {t('rightPanel.help.subtitle')}
        </Typography>
      </Box>

      <Alert severity="info" variant="outlined">
        {t('rightPanel.help.tip')}
      </Alert>

      <Box>
        <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>
          {t('rightPanel.help.docsTitle')}
        </Typography>
        <Stack spacing={0.5}>
          <Link href="/legal/terms" underline="hover">
            {t('rightPanel.help.terms')}
          </Link>
          <Link href="/legal/privacy" underline="hover">
            {t('rightPanel.help.privacy')}
          </Link>
          <Link href="/legal/acceptable-use" underline="hover">
            {t('rightPanel.help.acceptableUse')}
          </Link>
        </Stack>
      </Box>
    </Stack>
  );
};
