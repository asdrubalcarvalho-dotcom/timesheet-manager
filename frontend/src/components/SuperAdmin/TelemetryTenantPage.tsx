/**
 * TelemetryTenantPage Component
 * PART C — TENANT PROFILE PAGE
 * 
 * Route: /admin/telemetry/tenant/:slug
 * 
 * - Fetches tenants using /api/superadmin/telemetry/tenants
 * - Filters by slug on frontend (no new backend endpoints)
 * - Displays Tenant Information, Billing Summary, Usage Summary
 */
import React, { useState, useEffect } from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  Grid,
  Button,
  CircularProgress,
  Alert,
  Chip,
  Divider,
  Paper
} from '@mui/material';
import { ArrowBack } from '@mui/icons-material';
import { useParams, useNavigate } from 'react-router-dom';
import api from '../../services/api';

interface Tenant {
  id: string;
  slug: string;
  name: string;
  owner_email: string;
  status: string;
  plan: string;
  created_at: string;
  trial_ends_at: string | null;
}

interface BillingData {
  subscriptions: {
    total: number;
    active: number;
    trial: number;
    by_plan: Record<string, number>;
  };
  payments: {
    total: number;
    completed: number;
    total_revenue: number;
    currency: string;
  };
}

interface UsageData {
  timesheets: { total: number; today: number };
  expenses: { total: number; today: number };
  users: { total: number; active: number };
}

const TelemetryTenantPage: React.FC = () => {
  const { slug } = useParams<{ slug: string }>();
  const navigate = useNavigate();
  
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [billing, setBilling] = useState<BillingData | null>(null);
  const [usage, setUsage] = useState<UsageData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  useEffect(() => {
    const fetchData = async () => {
      try {
        setLoading(true);
        setError(null);

        // Fetch all tenants and filter by slug (frontend filtering per RULE 1)
        const [tenantsRes, billingRes, usageRes] = await Promise.all([
          api.get('/api/superadmin/telemetry/tenants'),
          api.get('/api/superadmin/telemetry/billing'),
          api.get('/api/superadmin/telemetry/usage'),
        ]);

        const allTenants = tenantsRes.data.data.tenants || [];
        const foundTenant = allTenants.find((t: Tenant) => t.slug === slug);

        if (!foundTenant) {
          setError(`Tenant with slug "${slug}" not found`);
          return;
        }

        setTenant(foundTenant);
        setBilling(billingRes.data.data);
        setUsage(usageRes.data.data);
      } catch (err: any) {
        setError(err.response?.data?.message || err.message || 'Failed to fetch tenant data');
      } finally {
        setLoading(false);
      }
    };

    fetchData();
  }, [slug]);

  if (loading) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <CircularProgress />
      </Box>
    );
  }

  if (error || !tenant) {
    return (
      <Box>
        <Button startIcon={<ArrowBack />} onClick={() => navigate('/admin/telemetry')} sx={{ mb: 2 }}>
          Back to Dashboard
        </Button>
        <Alert severity="error">{error || 'Tenant not found'}</Alert>
      </Box>
    );
  }

  return (
    <Box>
      <Button startIcon={<ArrowBack />} onClick={() => navigate('/admin/telemetry')} sx={{ mb: 3 }}>
        Back to Dashboard
      </Button>

      <Typography variant="h4" gutterBottom>
        Tenant Profile: {tenant.name || tenant.slug}
      </Typography>

      <Grid container spacing={3}>
        {/* Section 1: Tenant Information */}
        <Grid item xs={12} md={6}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>Tenant Information</Typography>
              <Divider sx={{ mb: 2 }} />
              <Box display="flex" flexDirection="column" gap={1}>
                <Typography variant="body2">
                  <strong>ID:</strong> {tenant.id}
                </Typography>
                <Typography variant="body2">
                  <strong>Slug:</strong> {tenant.slug}
                </Typography>
                <Typography variant="body2">
                  <strong>Name:</strong> {tenant.name}
                </Typography>
                <Typography variant="body2">
                  <strong>Owner Email:</strong> {tenant.owner_email}
                </Typography>
                <Typography variant="body2">
                  <strong>Status:</strong>{' '}
                  <Chip
                    label={tenant.status}
                    color={tenant.status === 'active' ? 'success' : tenant.status === 'suspended' ? 'error' : 'warning'}
                    size="small"
                  />
                </Typography>
                <Typography variant="body2">
                  <strong>Plan:</strong>{' '}
                  <Chip label={tenant.plan} color="primary" size="small" />
                </Typography>
                <Typography variant="body2">
                  <strong>Created:</strong> {new Date(tenant.created_at).toLocaleString()}
                </Typography>
                <Typography variant="body2">
                  <strong>Trial Ends:</strong>{' '}
                  {tenant.trial_ends_at ? new Date(tenant.trial_ends_at).toLocaleString() : 'N/A'}
                </Typography>
              </Box>
            </CardContent>
          </Card>
        </Grid>

        {/* Section 2: Billing Summary */}
        <Grid item xs={12} md={6}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>Billing Summary</Typography>
              <Divider sx={{ mb: 2 }} />
              {billing && (
                <Box display="flex" flexDirection="column" gap={1}>
                  <Typography variant="body2">
                    <strong>Total Subscriptions:</strong> {billing.subscriptions.total}
                  </Typography>
                  <Typography variant="body2">
                    <strong>Active Subscriptions:</strong> {billing.subscriptions.active}
                  </Typography>
                  <Typography variant="body2">
                    <strong>Trial Subscriptions:</strong> {billing.subscriptions.trial}
                  </Typography>
                  <Typography variant="body2">
                    <strong>Total Payments:</strong> {billing.payments.total}
                  </Typography>
                  <Typography variant="body2">
                    <strong>Completed Payments:</strong> {billing.payments.completed}
                  </Typography>
                  <Typography variant="body2">
                    <strong>Total Revenue:</strong> {billing.payments.currency}{billing.payments.total_revenue.toFixed(2)}
                  </Typography>
                  
                  {/* Plans breakdown */}
                  <Divider sx={{ my: 1 }} />
                  <Typography variant="subtitle2" gutterBottom>Subscriptions by Plan:</Typography>
                  {Object.keys(billing.subscriptions.by_plan).length > 0 ? (
                    Object.entries(billing.subscriptions.by_plan).map(([plan, count]) => (
                      <Typography key={plan} variant="body2">
                        <strong>{plan}:</strong> {count}
                      </Typography>
                    ))
                  ) : (
                    <Typography variant="body2" color="text.secondary">No plan data available</Typography>
                  )}
                </Box>
              )}
            </CardContent>
          </Card>
        </Grid>

        {/* Section 3: Usage Summary */}
        <Grid item xs={12}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>Usage Summary</Typography>
              <Divider sx={{ mb: 2 }} />
              {usage && (
                <Grid container spacing={3}>
                  <Grid item xs={12} md={4}>
                    <Paper sx={{ p: 2, bgcolor: 'primary.light', textAlign: 'center' }}>
                      <Typography variant="h4" color="primary.contrastText">
                        {usage.timesheets.total}
                      </Typography>
                      <Typography variant="body2" color="primary.contrastText">
                        Total Timesheets
                      </Typography>
                      <Typography variant="caption" color="primary.contrastText">
                        Today: {usage.timesheets.today}
                      </Typography>
                    </Paper>
                  </Grid>
                  
                  <Grid item xs={12} md={4}>
                    <Paper sx={{ p: 2, bgcolor: 'secondary.light', textAlign: 'center' }}>
                      <Typography variant="h4" color="secondary.contrastText">
                        {usage.expenses.total}
                      </Typography>
                      <Typography variant="body2" color="secondary.contrastText">
                        Total Expenses
                      </Typography>
                      <Typography variant="caption" color="secondary.contrastText">
                        Today: {usage.expenses.today}
                      </Typography>
                    </Paper>
                  </Grid>
                  
                  <Grid item xs={12} md={4}>
                    <Paper sx={{ p: 2, bgcolor: 'success.light', textAlign: 'center' }}>
                      <Typography variant="h4" color="success.contrastText">
                        {usage.users.total}
                      </Typography>
                      <Typography variant="body2" color="success.contrastText">
                        Total Users
                      </Typography>
                      <Typography variant="caption" color="success.contrastText">
                        Active: {usage.users.active}
                      </Typography>
                    </Paper>
                  </Grid>
                </Grid>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </Box>
  );
};

export default TelemetryTenantPage;
