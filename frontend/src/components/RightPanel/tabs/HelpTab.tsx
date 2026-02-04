import React from 'react';
import { Alert, Box, Button, Link, Stack, Typography } from '@mui/material';
import { useTranslation } from 'react-i18next';
import { useLocation } from 'react-router-dom';
import { helpContentRegistry, resolveHelpContextKey } from '../helpContentRegistry';

export const HelpTab: React.FC = () => {
  const { t } = useTranslation();
  const location = useLocation();
  const contextKey = resolveHelpContextKey(location.pathname);
  const content = contextKey ? helpContentRegistry[contextKey] : null;

  return (
    <Stack spacing={2}>
      <Box>
        <Typography variant="h6" sx={{ fontWeight: 800, mb: 0.5 }}>
          {content ? t(content.titleKey) : t('rightPanel.help.fallbackTitle')}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          {content ? t(content.introKey) : t('rightPanel.help.fallbackBody')}
        </Typography>
        <Box sx={{ mt: 1 }}>
          <Button
            variant="outlined"
            size="small"
            onClick={() => window.dispatchEvent(new CustomEvent('tour:reset'))}
            sx={{ textTransform: 'none' }}
          >
            {t('tour.restart')}
          </Button>
        </Box>
      </Box>

      {content ? (
        <Alert severity="info" variant="outlined">
          {t('rightPanel.help.tip')}
        </Alert>
      ) : null}

      {content ? (
        <Stack spacing={2}>
          {content.sections.map((section) => (
            <Box key={section.titleKey}>
              <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 0.5 }}>
                {t(section.titleKey)}
              </Typography>
              <Box component="ul" sx={{ pl: 2, my: 0, color: 'text.secondary' }}>
                {section.bulletKeys.map((bulletKey) => (
                  <li key={bulletKey}>{t(bulletKey)}</li>
                ))}
              </Box>
            </Box>
          ))}
          {content.comingSoon ? (
            <Alert severity="warning" variant="outlined">
              <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 0.5 }}>
                {t(content.comingSoon.titleKey)}
              </Typography>
              <Box component="ul" sx={{ pl: 2, my: 0 }}>
                {content.comingSoon.bulletKeys.map((bulletKey) => (
                  <li key={bulletKey}>{t(bulletKey)}</li>
                ))}
              </Box>
            </Alert>
          ) : null}
        </Stack>
      ) : null}

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
