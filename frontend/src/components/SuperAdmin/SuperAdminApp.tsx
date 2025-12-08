/**
 * COPILOT GLOBAL RULES — DO NOT IGNORE
 * See backend/config/telemetry.php for complete rules
 * 
 * This component renders ONLY on admin subdomains (admin.localhost, etc.)
 * Provides minimal layout for SuperAdmin telemetry access
 */

import React from 'react';
import { Routes, Route, Navigate } from 'react-router-dom';
import { Box, AppBar, Toolbar, Typography, Button, Container } from '@mui/material';
import { useAuth } from '../Auth/AuthContext';
import TelemetryDashboard from './TelemetryDashboard';
import TelemetryTenantPage from './TelemetryTenantPage';

const SuperAdminApp: React.FC = () => {
  const { logout } = useAuth();

  const handleLogout = () => {
    logout();
    window.location.href = '/login';
  };

  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', minHeight: '100vh' }}>
      <AppBar position="static" color="primary">
        <Toolbar>
          <Typography variant="h6" component="div" sx={{ flexGrow: 1 }}>
            SuperAdmin Telemetry Dashboard
          </Typography>
          <Button color="inherit" onClick={handleLogout}>
            Logout
          </Button>
        </Toolbar>
      </AppBar>

      <Container maxWidth="xl" sx={{ mt: 4, mb: 4, flexGrow: 1 }}>
        <Routes>
          <Route path="/admin/telemetry" element={<TelemetryDashboard />} />
          <Route path="/admin/telemetry/tenant/:slug" element={<TelemetryTenantPage />} />
          <Route path="*" element={<Navigate to="/admin/telemetry" replace />} />
        </Routes>
      </Container>

      <Box component="footer" sx={{ py: 2, px: 2, mt: 'auto', backgroundColor: '#f5f5f5', textAlign: 'center' }}>
        <Typography variant="body2" color="text.secondary">
          SuperAdmin Access - {new Date().getFullYear()}
        </Typography>
      </Box>
    </Box>
  );
};

export default SuperAdminApp;
