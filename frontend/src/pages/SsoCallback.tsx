import { useEffect, useMemo, useState } from 'react';
import { useLocation } from 'react-router-dom';
import { Alert, Box, CircularProgress, Typography } from '@mui/material';
import { setTenantSlug } from '../services/api';

const SsoCallback = () => {
  const location = useLocation();
  const [error, setError] = useState<string | null>(null);

  const params = useMemo(() => new URLSearchParams(location.search), [location.search]);
  const token = params.get('token');
  const tenant = params.get('tenant');

  useEffect(() => {
    if (!tenant) {
      setError('Missing SSO callback parameters.');
      return;
    }

    try {
      if (token) {
        localStorage.setItem('auth_token', token);
      }
      setTenantSlug(tenant);

      // AuthContext reads localStorage only on mount, so reload into an authenticated route.
      window.location.replace('/dashboard');
    } catch {
      setError('Unable to complete SSO sign-in.');
    }
  }, [token, tenant]);

  if (error) {
    return (
      <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '100vh', p: 3 }}>
        <Box sx={{ maxWidth: 520, width: '100%' }}>
          <Alert severity="error" sx={{ mb: 2 }}>
            {error}
          </Alert>
          <Typography variant="body2" color="text.secondary">
            Please return to the login page and try again.
          </Typography>
        </Box>
      </Box>
    );
  }

  return (
    <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '100vh', flexDirection: 'column', gap: 2 }}>
      <CircularProgress size={32} />
      <Typography variant="body2" color="text.secondary">
        Completing SSO sign-in...
      </Typography>
    </Box>
  );
};

export default SsoCallback;
