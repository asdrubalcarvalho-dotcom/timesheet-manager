import React from 'react';
import { Box, Typography } from '@mui/material';

export type TenantContextBadgeProps = {
  region?: string | null;
  weekStart?: string | null;
};

const formatWeekStart = (weekStart?: string | null): string => {
  const raw = (weekStart ?? '').toString().trim().toLowerCase();
  if (raw === 'sunday') return 'Sunday';
  if (raw === 'monday') return 'Monday';
  return 'Monday';
};

const formatRegion = (region?: string | null): string => {
  const raw = (region ?? '').toString().trim().toUpperCase();
  if (raw === 'US') return 'US';
  if (raw === 'EU') return 'EU';
  return 'EU';
};

const TenantContextBadge: React.FC<TenantContextBadgeProps> = ({ region, weekStart }) => {
  const regionLabel = formatRegion(region);
  const weekStartLabel = formatWeekStart(weekStart);

  return (
    <Box sx={{ mt: 1 }}>
      <Typography variant="caption" sx={{ color: 'grey.400', display: 'block' }}>
        Region: {regionLabel}
      </Typography>
      <Typography variant="caption" sx={{ color: 'grey.400', display: 'block' }}>
        Week start: {weekStartLabel}
      </Typography>
    </Box>
  );
};

export default TenantContextBadge;
