import React from 'react';
import {
  Card,
  CardContent,
  Typography,
  Button,
  Box,
  Chip,
  List,
  ListItem,
  ListItemIcon,
  ListItemText,
  alpha,
} from '@mui/material';
import {
  Check as CheckIcon,
  Business as EnterpriseIcon,
  Group as TeamIcon,
  Person as StarterIcon,
} from '@mui/icons-material';

interface PlanCardProps {
  plan: 'starter' | 'team' | 'enterprise';
  isCurrentPlan: boolean;
  onUpgrade: () => void;
  onDowngrade?: () => void;
  canDowngrade?: boolean;
  hasPendingDowngrade?: boolean;
  downgradeEffectiveDate?: string | null;
}

/**
 * PlanCard - Displays plan details and upgrade/downgrade buttons
 * 
 * Shows plan name, static pricing (fixed per-user rates), features, and actions
 * These are the base prices - actual totals are calculated in PricingSummary
 */
const PlanCard: React.FC<PlanCardProps> = ({ 
  plan, 
  isCurrentPlan, 
  onUpgrade, 
  onDowngrade,
  canDowngrade = false,
  hasPendingDowngrade = false,
  downgradeEffectiveDate = null
}) => {
  // Static plan prices (fixed rates, not multiplied by user count)
  const PLAN_PRICES = {
    starter: { price: 'Free', description: 'Up to 2 users' },
    team: { price: '€44/month', description: 'Per user pricing' },
    enterprise: { price: '€59/month', description: 'Per user pricing' },
  };

  const planPrice = PLAN_PRICES[plan];

  const getPlanConfig = () => {
    switch (plan) {
      case 'starter':
        return {
          name: 'Starter',
          icon: <StarterIcon sx={{ fontSize: 40 }} />,
          color: '#4caf50',
          description: 'Perfect for small teams getting started',
          features: [
            'Up to 2 users',
            'Timesheets included',
            'Expenses included',
            'Basic reporting',
            'Email support',
            'No add-ons available',
          ],
          price: 'Free',
          priceNote: 'Up to 2 users',
        };
      case 'team':
        return {
          name: 'Team',
          icon: <TeamIcon sx={{ fontSize: 40 }} />,
          color: '#2196f3',
          description: 'For growing teams that need flexibility',
          features: [
            'Up to 50 licenses',
            'Timesheets included',
            'Expenses included',
            'Travels included',
            'Multi-stage approvals',
            'Custom reports',
            'Priority email support',
            'Optional add-on: Planning (+18%)',
            'Optional add-on: AI Assistant (+18%)',
          ],
          price: planPrice.price,
          priceNote: planPrice.description,
        };
      case 'enterprise':
        return {
          name: 'Enterprise',
          icon: <EnterpriseIcon sx={{ fontSize: 40 }} />,
          color: '#9c27b0',
          description: 'Everything included - No add-ons required',
          features: [
            'Up to 150 licenses',
            'All features enabled',
            'Timesheets, Expenses & Travels',
            'Planning included',
            'AI features included',
            'No add-ons required',
            'Dedicated account manager',
            'Custom integrations',
            'Priority support',
            'SLA guarantee',
          ],
          price: planPrice.price,
          priceNote: planPrice.description,
        };
    }
  };

  const config = getPlanConfig();

  return (
    <Card
      sx={{
        height: '100%',
        display: 'flex',
        flexDirection: 'column',
        position: 'relative',
        border: isCurrentPlan ? `3px solid ${config.color}` : '1px solid',
        borderColor: isCurrentPlan ? config.color : 'divider',
        transition: 'all 0.3s ease',
        '&:hover': !isCurrentPlan
          ? {
              transform: 'translateY(-4px)',
              boxShadow: `0 8px 24px ${alpha(config.color, 0.2)}`,
            }
          : {},
      }}
    >
      {isCurrentPlan && (
        <Chip
          label="Current Plan"
          color="primary"
          size="small"
          sx={{
            position: 'absolute',
            top: 16,
            right: 16,
            fontWeight: 600,
            bgcolor: config.color,
          }}
        />
      )}

      <CardContent sx={{ flex: 1, pt: 3 }}>
        {/* Plan Icon and Name */}
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mb: 2 }}>
          <Box
            sx={{
              width: 64,
              height: 64,
              borderRadius: 2,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              bgcolor: alpha(config.color, 0.1),
              color: config.color,
            }}
          >
            {config.icon}
          </Box>
          <Box>
            <Typography variant="h5" sx={{ fontWeight: 700, color: config.color }}>
              {config.name}
            </Typography>
            <Typography variant="body2" color="text.secondary">
              {config.description}
            </Typography>
          </Box>
        </Box>

        {/* Pricing */}
        <Box sx={{ mb: 3 }}>
          <Typography variant="h4" sx={{ fontWeight: 700, mb: 0.5 }}>
            {config.price}
          </Typography>
          <Typography variant="caption" color="text.secondary">
            {config.priceNote}
          </Typography>
        </Box>

        {/* Features List */}
        <List dense>
          {config.features.map((feature, index) => (
            <ListItem key={index} sx={{ px: 0 }}>
              <ListItemIcon sx={{ minWidth: 32 }}>
                <CheckIcon sx={{ color: config.color, fontSize: 20 }} />
              </ListItemIcon>
              <ListItemText
                primary={feature}
                primaryTypographyProps={{
                  variant: 'body2',
                  fontSize: '0.875rem',
                }}
              />
            </ListItem>
          ))}
        </List>
      </CardContent>

      {/* Action Button */}
      <Box sx={{ p: 2, pt: 0 }}>
        {isCurrentPlan ? (
          <Button
            fullWidth
            variant="outlined"
            disabled
            sx={{
              borderColor: config.color,
              color: config.color,
              fontWeight: 600,
            }}
          >
            Current Plan
          </Button>
        ) : canDowngrade && onDowngrade ? (
          <Box>
            <Button
              fullWidth
              variant="outlined"
              onClick={onDowngrade}
              disabled={hasPendingDowngrade}
              sx={{
                borderColor: config.color,
                color: config.color,
                fontWeight: 600,
                '&:hover': {
                  bgcolor: alpha(config.color, 0.1),
                },
              }}
            >
              {hasPendingDowngrade ? 'DOWNGRADE SCHEDULED' : `Downgrade to ${config.name}`}
            </Button>
            {hasPendingDowngrade && downgradeEffectiveDate && (
              <Typography 
                variant="caption" 
                sx={{ 
                  display: 'block', 
                  textAlign: 'center', 
                  mt: 1,
                  color: 'text.secondary',
                  fontStyle: 'italic'
                }}
              >
                Effective on: {new Date(downgradeEffectiveDate).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}
              </Typography>
            )}
          </Box>
        ) : (
          <Button
            fullWidth
            variant="contained"
            onClick={onUpgrade}
            sx={{
              bgcolor: config.color,
              fontWeight: 600,
              '&:hover': {
                bgcolor: alpha(config.color, 0.8),
              },
            }}
          >
            Upgrade to {config.name}
          </Button>
        )}
      </Box>
    </Card>
  );
};

export default PlanCard;
