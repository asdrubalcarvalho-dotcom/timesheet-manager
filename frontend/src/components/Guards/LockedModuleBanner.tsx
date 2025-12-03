import React from 'react';
import {
  Box,
  Container,
  Paper,
  Typography,
  Button,
  Stack,
  alpha,
} from '@mui/material';
import {
  Lock as LockIcon,
  Flight as TravelsIcon,
  CalendarMonth as PlanningIcon,
  SmartToy as AIIcon,
  Upgrade as UpgradeIcon,
} from '@mui/icons-material';

interface LockedModuleBannerProps {
  /**
   * Feature that is locked
   */
  feature: 'travels' | 'planning' | 'ai';
}

/**
 * LockedModuleBanner - Displayed when user tries to access disabled module
 * 
 * Shows feature icon, description, and upgrade call-to-action
 * Redirects to billing page for plan upgrade
 */
const LockedModuleBanner: React.FC<LockedModuleBannerProps> = ({ feature }) => {
  const getFeatureConfig = () => {
    switch (feature) {
      case 'travels':
        return {
          name: 'Travels Module',
          icon: <TravelsIcon sx={{ fontSize: 80 }} />,
          color: '#2196f3',
          description: 'Track business trips, travel segments, and related expenses',
          benefits: [
            'Track travel segments with origin/destination',
            'Link travels to projects and timesheets',
            'AI-powered travel suggestions',
            'Comprehensive travel reporting',
          ],
          requiredPlan: 'Team or Enterprise plan required',
        };
      case 'planning':
        return {
          name: 'Planning Module',
          icon: <PlanningIcon sx={{ fontSize: 80 }} />,
          color: '#ff9800',
          description: 'Advanced project planning with Gantt charts and resource allocation',
          benefits: [
            'Interactive Gantt charts',
            'Task dependencies and milestones',
            'Resource allocation and workload planning',
            'Project timeline visualization',
          ],
          requiredPlan: 'Available as +18% addon',
        };
      case 'ai':
        return {
          name: 'AI Assistant',
          icon: <AIIcon sx={{ fontSize: 80 }} />,
          color: '#9c27b0',
          description: 'AI-powered insights, predictions, and intelligent automation',
          benefits: [
            'Smart timesheet suggestions',
            'Pattern analysis and anomaly detection',
            'Predictive analytics for project timelines',
            'Automated workflow recommendations',
          ],
          requiredPlan: 'Available as +18% addon',
        };
    }
  };

  const config = getFeatureConfig();

  const handleUpgrade = () => {
    window.location.href = '/billing'; // Navigate to billing page
  };

  return (
    <Box
      sx={{
        p: 0,
        width: '100%',
        maxWidth: '100%',
        height: '100vh',
        display: 'flex',
        flexDirection: 'column',
        alignItems: 'center',
        justifyContent: 'center',
        bgcolor: '#f5f5f5',
      }}
    >
      <Container maxWidth="md">
        <Paper
          elevation={0}
          sx={{
            p: 6,
            textAlign: 'center',
            border: '2px dashed',
            borderColor: 'divider',
            borderRadius: 3,
            bgcolor: 'background.paper',
          }}
        >
          {/* Locked Icon */}
          <Box
            sx={{
              display: 'inline-flex',
              alignItems: 'center',
              justifyContent: 'center',
              width: 120,
              height: 120,
              borderRadius: '50%',
              bgcolor: alpha(config.color, 0.1),
              mb: 3,
              position: 'relative',
            }}
          >
            {config.icon}
            <Box
              sx={{
                position: 'absolute',
                bottom: 0,
                right: 0,
                width: 40,
                height: 40,
                borderRadius: '50%',
                bgcolor: 'error.main',
                display: 'flex',
                alignItems: 'center',
                justifyContent: 'center',
                border: '3px solid white',
              }}
            >
              <LockIcon sx={{ color: 'white', fontSize: 20 }} />
            </Box>
          </Box>

          {/* Title and Description */}
          <Typography variant="h4" sx={{ fontWeight: 700, mb: 2, color: config.color }}>
            {config.name}
          </Typography>
          
          <Typography variant="body1" color="text.secondary" sx={{ mb: 1 }}>
            {config.description}
          </Typography>

          <Typography variant="body2" sx={{ fontWeight: 600, color: 'warning.main', mb: 4 }}>
            🔒 {config.requiredPlan}
          </Typography>

          {/* Benefits List */}
          <Stack spacing={1} sx={{ mb: 4, textAlign: 'left', maxWidth: 500, mx: 'auto' }}>
            {config.benefits.map((benefit, index) => (
              <Box key={index} sx={{ display: 'flex', alignItems: 'flex-start', gap: 1 }}>
                <Box
                  sx={{
                    width: 6,
                    height: 6,
                    borderRadius: '50%',
                    bgcolor: config.color,
                    mt: 1,
                    flexShrink: 0,
                  }}
                />
                <Typography variant="body2" color="text.secondary">
                  {benefit}
                </Typography>
              </Box>
            ))}
          </Stack>

          {/* Upgrade Button */}
          <Button
            variant="contained"
            size="large"
            startIcon={<UpgradeIcon />}
            onClick={handleUpgrade}
            sx={{
              px: 4,
              py: 1.5,
              fontSize: '1rem',
              fontWeight: 600,
              background: `linear-gradient(135deg, ${config.color} 0%, ${alpha(config.color, 0.8)} 100%)`,
              '&:hover': {
                background: `linear-gradient(135deg, ${alpha(config.color, 0.9)} 0%, ${alpha(config.color, 0.7)} 100%)`,
              },
            }}
          >
            Upgrade Your Plan
          </Button>

          <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 2 }}>
            Unlock this feature and more with a plan upgrade
          </Typography>
        </Paper>
      </Container>
    </Box>
  );
};

export default LockedModuleBanner;
