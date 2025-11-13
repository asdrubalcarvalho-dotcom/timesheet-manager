import React from 'react';
import { Box, Paper, Typography, Button } from '@mui/material';
import type { SvgIconProps } from '@mui/material';

interface EmptyStateProps {
  icon: React.ComponentType<SvgIconProps>;
  title: string;
  subtitle: string;
  actionLabel?: string;
  onAction?: () => void;
}

const EmptyState: React.FC<EmptyStateProps> = ({ 
  icon: Icon, 
  title, 
  subtitle, 
  actionLabel, 
  onAction 
}) => {
  return (
    <Paper 
      elevation={0}
      sx={{ 
        p: 6, 
        textAlign: 'center', 
        bgcolor: 'background.default',
        border: '1px dashed',
        borderColor: 'divider',
        borderRadius: 2
      }}
    >
      <Box
        sx={{
          display: 'inline-flex',
          alignItems: 'center',
          justifyContent: 'center',
          width: 80,
          height: 80,
          borderRadius: '50%',
          bgcolor: 'action.hover',
          mb: 2
        }}
      >
        <Icon sx={{ fontSize: 48, color: 'text.secondary' }} />
      </Box>
      
      <Typography variant="h6" color="text.primary" gutterBottom fontWeight={600}>
        {title}
      </Typography>
      
      <Typography variant="body2" color="text.secondary" sx={{ mb: 3, maxWidth: 400, mx: 'auto' }}>
        {subtitle}
      </Typography>
      
      {actionLabel && onAction && (
        <Button
          variant="contained"
          onClick={onAction}
          sx={{
            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            '&:hover': {
              background: 'linear-gradient(135deg, #5568d3 0%, #653a8b 100%)',
            }
          }}
        >
          {actionLabel}
        </Button>
      )}
    </Paper>
  );
};

export default EmptyState;
