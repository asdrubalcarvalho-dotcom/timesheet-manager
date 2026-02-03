import React from 'react';
import {
  Card,
  CardContent,
  Typography,
  Switch,
  Box,
  Chip,
  alpha,
  Tooltip,
} from '@mui/material';
import {
  CalendarMonth as PlanningIcon,
  SmartToy as AIIcon,
  Lock as LockIcon,
} from '@mui/icons-material';
import { useTranslation } from 'react-i18next';

interface AddonToggleProps {
  addon: 'planning' | 'ai';
  enabled: boolean;
  onToggle: () => void;
  disabled?: boolean; // Add disabled prop for Starter plan
}

/**
 * AddonToggle - Toggle switch for optional add-ons
 * 
 * Displays addon name, description, pricing, and toggle switch
 * Pricing calculation happens in backend
 */
const AddonToggle: React.FC<AddonToggleProps> = ({ addon, enabled, onToggle, disabled = false }) => {
  const { t } = useTranslation();
  const getAddonConfig = () => {
    switch (addon) {
      case 'planning':
        return {
          name: t('billing.addons.planning.name'),
          icon: <PlanningIcon sx={{ fontSize: 32 }} />,
          color: '#ff9800',
          description: t('billing.addons.planning.description'),
          tooltip: t('billing.addons.planning.tooltip'),
          addonTooltip: t('billing.addons.planning.addonTooltip', { percent: 18 }),
          priceLabel: t('billing.addons.priceOfBase', { percent: 18 }),
          features: [
            t('billing.addons.planning.features.ganttCharts'),
            t('billing.addons.planning.features.taskDependencies'),
            t('billing.addons.planning.features.resourcePlanning'),
            t('billing.addons.planning.features.timelineViews'),
          ],
        };
      case 'ai':
        return {
          name: t('billing.addons.ai.name'),
          icon: <AIIcon sx={{ fontSize: 32 }} />,
          color: '#9c27b0',
          description: t('billing.addons.ai.description'),
          tooltip: t('billing.addons.ai.tooltip'),
          addonTooltip: t('billing.addons.ai.addonTooltip', { percent: 18 }),
          priceLabel: t('billing.addons.priceOfBase', { percent: 18 }),
          features: [
            t('billing.addons.ai.features.smartSuggestions'),
            t('billing.addons.ai.features.patternAnalysis'),
            t('billing.addons.ai.features.anomalyDetection'),
            t('billing.addons.ai.features.predictiveAnalytics'),
          ],
        };
    }
  };

  const config = getAddonConfig();

  return (
    <Card
      sx={{
        position: 'relative',
        border: enabled ? `2px solid ${config.color}` : '1px solid',
        borderColor: enabled ? config.color : 'divider',
        transition: 'all 0.3s ease',
        bgcolor: enabled ? alpha(config.color, 0.05) : 'background.paper',
        opacity: disabled ? 0.6 : 1,
        cursor: disabled ? 'not-allowed' : 'default',
      }}
    >
      {disabled && (
        <Tooltip
          title={t('billing.addons.upgradeTooltip')}
          arrow
        >
          <Chip
            icon={<LockIcon />}
            label={t('billing.addons.requiresUpgrade')}
            size="small"
            color="warning"
            sx={{
              position: 'absolute',
              top: 12,
              right: 12,
              fontWeight: 600,
              zIndex: 1,
              cursor: 'help',
            }}
          />
        </Tooltip>
      )}
      <CardContent>
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start', mb: 2 }}>
          {/* Addon Info */}
          <Box sx={{ display: 'flex', gap: 2, flex: 1 }}>
            <Box
              sx={{
                width: 48,
                height: 48,
                borderRadius: 2,
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                bgcolor: alpha(config.color, 0.1),
                color: config.color,
                flexShrink: 0,
              }}
            >
              {config.icon}
            </Box>
            <Box sx={{ flex: 1 }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 0.5 }}>
                <Tooltip
                  title={
                    <Box>
                      <Typography variant="body2" sx={{ mb: 1 }}>
                        {config.tooltip}
                      </Typography>
                      {!disabled && (
                        <Typography variant="caption" display="block" sx={{ fontStyle: 'italic' }}>
                          {config.addonTooltip}
                        </Typography>
                      )}
                    </Box>
                  }
                  arrow
                >
                  <Typography variant="h6" sx={{ fontWeight: 600, cursor: 'help' }}>
                    {config.name}
                  </Typography>
                </Tooltip>
                {enabled && (
                  <Chip
                    label={t('billing.addons.active')}
                    size="small"
                    sx={{
                      bgcolor: config.color,
                      color: 'white',
                      fontWeight: 600,
                      height: 20,
                    }}
                  />
                )}
              </Box>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                {config.description}
              </Typography>
              <Typography variant="body2" sx={{ fontWeight: 600, color: config.color }}>
                {config.priceLabel}
              </Typography>
            </Box>
          </Box>

          {/* Toggle Switch */}
          <Switch
            checked={enabled}
            onChange={onToggle}
            disabled={disabled}
            color="primary"
            sx={{
              '& .MuiSwitch-switchBase.Mui-checked': {
                color: config.color,
              },
              '& .MuiSwitch-switchBase.Mui-checked + .MuiSwitch-track': {
                backgroundColor: config.color,
              },
            }}
          />
        </Box>

        {/* Features (show when enabled) */}
        {enabled && (
          <Box
            sx={{
              display: 'flex',
              flexWrap: 'wrap',
              gap: 0.5,
              mt: 2,
              pt: 2,
              borderTop: '1px solid',
              borderColor: 'divider',
            }}
          >
            {config.features.map((feature, index) => (
              <Chip
                key={index}
                label={feature}
                size="small"
                variant="outlined"
                sx={{
                  borderColor: config.color,
                  color: config.color,
                  fontSize: '0.75rem',
                }}
              />
            ))}
          </Box>
        )}
      </CardContent>
    </Card>
  );
};

export default AddonToggle;
