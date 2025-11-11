import React from 'react';
import {
  Box,
  Container,
  Typography,
  Card,
  CardContent
} from '@mui/material';
import { AdminPanelSettings as AdminIcon } from '@mui/icons-material';

interface AdminLayoutProps {
  children: React.ReactNode;
  title: string;
}

export const AdminLayout: React.FC<AdminLayoutProps> = ({ children, title }) => {
  return (
    <Box sx={{ 
      p: 0,
      width: '100%',
      maxWidth: '100%',
      height: '100vh',
      display: 'flex',
      flexDirection: 'column',
      overflow: 'hidden',
      bgcolor: '#f5f5f5'
    }}>
      {/* Header - Sticky */}
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
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, px: 2 }}>
            <AdminIcon sx={{ fontSize: { xs: 24, sm: 28 } }} />
            <Typography 
              variant="h6" 
              sx={{ 
                fontWeight: 600,
                fontSize: { xs: '1.1rem', sm: '1.25rem' }
              }}
            >
              {title}
            </Typography>
          </Box>
        </CardContent>
      </Card>

      {/* Content - Scrollable */}
      <Box sx={{ flex: 1, overflow: 'auto' }}>
        <Container maxWidth="xl" sx={{ py: 2 }}>
          {children}
        </Container>
      </Box>
    </Box>
  );
};

export default AdminLayout;
