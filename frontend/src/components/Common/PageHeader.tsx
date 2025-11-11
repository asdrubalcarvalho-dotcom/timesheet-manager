import React from 'react';
import { Box, Card, CardContent, Typography } from '@mui/material';

interface PageHeaderProps {
  title: string;
  badges?: React.ReactNode;
  actions?: React.ReactNode;
  subtitle?: string;
}

const PageHeader: React.FC<PageHeaderProps> = ({ title, badges, actions, subtitle }) => {
  return (
    <Card 
      sx={{ 
        mb: 0,
        background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
        color: 'white',
        borderRadius: 0,
        boxShadow: 'none',
        borderBottom: '2px solid rgba(255,255,255,0.2)',
        position: 'sticky',
        top: 0,
        zIndex: 100,
        flexShrink: 0
      }}
    >
      <CardContent sx={{ p: { xs: 0.75, sm: 1 }, '&:last-child': { pb: { xs: 0.75, sm: 1 } } }}>
        <Box 
          sx={{ 
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            flexWrap: { xs: 'wrap', sm: 'nowrap' },
            gap: 1.5
          }}
        >
          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 0.5 }}>
            <Typography 
              variant="h6" 
              component="h2" 
              sx={{ 
                fontWeight: 600,
                fontSize: { xs: '1.1rem', sm: '1.25rem' },
                display: 'flex',
                alignItems: 'center',
                gap: 1
              }}
            >
              {title}
              {badges}
            </Typography>
            {subtitle && (
              <Typography 
                variant="caption" 
                sx={{ 
                  opacity: 0.9,
                  fontSize: '0.75rem'
                }}
              >
                {subtitle}
              </Typography>
            )}
          </Box>
          
          {actions && (
            <Box
              sx={{
                display: 'flex',
                flexWrap: 'wrap',
                alignItems: 'center',
                gap: 1,
                justifyContent: { xs: 'flex-start', sm: 'flex-end' }
              }}
            >
              {actions}
            </Box>
          )}
        </Box>
      </CardContent>
    </Card>
  );
};

export default PageHeader;
