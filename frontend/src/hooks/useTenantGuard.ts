import { useEffect } from 'react';
import { useNavigate, useLocation } from 'react-router-dom';

/**
 * Guard hook to ensure tenant_slug exists in localStorage
 * Redirects to login if missing
 */
export const useTenantGuard = () => {
  const navigate = useNavigate();
  const location = useLocation();

  useEffect(() => {
    const tenantSlug = localStorage.getItem('tenant_slug');
    const authToken = localStorage.getItem('auth_token');

    // If authenticated but no tenant slug, redirect to login
    if (authToken && !tenantSlug) {
      console.warn('Tenant slug missing - redirecting to login');
      navigate('/login?reason=missing-tenant', { 
        replace: true,
        state: { from: location.pathname }
      });
    }
  }, [navigate, location]);
};
