import React from 'react';
import {
  Badge,
  Box,
  Button,
  Card,
  CardContent,
  Chip,
  Collapse,
  IconButton,
  Typography,
} from '@mui/material';
import { Clear, ExpandLess, ExpandMore, FilterList } from '@mui/icons-material';
import { useTranslation } from 'react-i18next';

type Props = {
  expanded: boolean;
  onToggleExpanded: () => void;
  activeFiltersCount: number;
  onClearAll?: () => void;
  resultsLabel?: string;
  children: React.ReactNode;
};

const ReportFiltersCard: React.FC<Props> = ({
  expanded,
  onToggleExpanded,
  activeFiltersCount,
  onClearAll,
  resultsLabel,
  children,
}) => {
  const { t } = useTranslation();

  return (
    <Card sx={{ mb: 1 }}>
      <CardContent sx={{ py: 1.25 }}>
        <Box
          sx={{
            display: 'flex',
            alignItems: 'center',
            justifyContent: 'space-between',
            mb: expanded ? 1 : 0,
          }}
        >
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <Badge badgeContent={activeFiltersCount} color="primary">
              <FilterList />
            </Badge>
            <Typography variant="h6" sx={{ fontWeight: 600 }}>
              {t('common.filters')}
            </Typography>
            {activeFiltersCount > 0 && resultsLabel ? (
              <Chip label={resultsLabel} size="small" color="primary" variant="outlined" />
            ) : null}
          </Box>
          <Box sx={{ display: 'flex', gap: 1 }}>
            {activeFiltersCount > 0 && onClearAll ? (
              <Button
                size="small"
                startIcon={<Clear />}
                onClick={onClearAll}
                sx={{ textTransform: 'none' }}
              >
                {t('common.clearAll')}
              </Button>
            ) : null}
            <IconButton size="small" onClick={onToggleExpanded}>
              {expanded ? <ExpandLess /> : <ExpandMore />}
            </IconButton>
          </Box>
        </Box>

        <Collapse in={expanded}>{children}</Collapse>
      </CardContent>
    </Card>
  );
};

export default ReportFiltersCard;
