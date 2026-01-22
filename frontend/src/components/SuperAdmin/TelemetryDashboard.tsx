/**
 * COPILOT GLOBAL RULES — DO NOT IGNORE
 * See COPILOT_TELEMETRY_RULES.md for complete rules
 * 
 * This component is the MAIN TELEMETRY DASHBOARD implementing:
 * PART A - Advanced Health System (badges, system banner, disk alerts)
 * PART B - Error Log Analyzer (integrated via ErrorLogTable component)
 * PART D - Performance Monitor (integrated via PerformanceMonitor component)
 * 
 * ONLY calls /api/superadmin/telemetry/* endpoints
 * NEVER calls /api/admin/telemetry/* (internal API) directly
 */
import React, { useState, useEffect } from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  Grid,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
  Chip,
  Button,
  CircularProgress,
  Alert,
  Switch,
  FormControlLabel,
  AlertTitle,
  Link
} from '@mui/material';
import { Refresh as RefreshIcon, CheckCircle, Warning, Error as ErrorIcon } from '@mui/icons-material';
import { useNavigate } from 'react-router-dom';
import api from '../../services/api';
import ErrorLogTable from './ErrorLogTable';
import PerformanceMonitor from './PerformanceMonitor';
import { useAuth } from '../Auth/AuthContext';
import { formatTenantDate } from '../../utils/tenantFormatting';

interface SystemInfo {
  app_name: string;
  laravel_version: string;
  php_version: string;
  app_env: string;
  app_debug: boolean;
  timezone: string;
  database_connection: string;
}

interface Tenant {
  id: string;
  slug: string;
  owner_email: string;
  name: string;
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

interface HealthData {
  app: string;
  database: string;
  redis: string;
  disk_free_mb: number;
}

interface ErrorLogEntry {
  timestamp: string;
  level: string;
  message: string;
}

const TelemetryDashboard: React.FC = () => {
  const navigate = useNavigate();
  const { tenantContext } = useAuth();
  const [info, setInfo] = useState<SystemInfo | null>(null);
  const [tenants, setTenants] = useState<Tenant[]>([]);
  const [billing, setBilling] = useState<BillingData | null>(null);
  const [usage, setUsage] = useState<UsageData | null>(null);
  const [health, setHealth] = useState<HealthData | null>(null);
  const [errors, setErrors] = useState<ErrorLogEntry[]>([]);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);
  const [autoRefresh, setAutoRefresh] = useState(false);

  const fetchData = async () => {
    try {
      setLoading(true);
      setError(null);

      const [infoRes, tenantsRes, billingRes, usageRes, healthRes, errorsRes] = await Promise.all([
        api.get('/api/superadmin/telemetry/info'),
        api.get('/api/superadmin/telemetry/tenants'),
        api.get('/api/superadmin/telemetry/billing'),
        api.get('/api/superadmin/telemetry/usage'),
        api.get('/api/superadmin/telemetry/health'),
        api.get('/api/superadmin/telemetry/errors'),
      ]);

      setInfo(infoRes.data.data);
      setTenants(tenantsRes.data.data.tenants || []);
      setBilling(billingRes.data.data);
      setUsage(usageRes.data.data);
      setHealth(healthRes.data.data);
      setErrors(errorsRes.data.data || []);
    } catch (err: any) {
      setError(err.response?.data?.message || err.message || 'Failed to fetch telemetry data');
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchData();
  }, []);

  useEffect(() => {
    if (!autoRefresh) return;

    const interval = setInterval(fetchData, 60000); // 60s
    return () => clearInterval(interval);
  }, [autoRefresh]);

  // PART A1 — System-wide status calculation
  const getSystemStatus = (): 'ok' | 'warning' | 'critical' => {
    if (!health) return 'warning';
    
    // Critical if any core service is down
    if (health.app !== 'ok' || health.database !== 'ok') {
      return 'critical';
    }
    
    // Critical if disk space < 2048 MB
    if (health.disk_free_mb < 2048) {
      return 'critical';
    }
    
    // Warning if Redis down or disk < 10240 MB
    if (health.redis === 'down' || health.disk_free_mb < 10240) {
      return 'warning';
    }
    
    return 'ok';
  };

  const systemStatus = getSystemStatus();

  // PART A2 — Disk space alert helper
  const getDiskSpaceAlert = (): { show: boolean; severity: 'error' | 'warning'; message: string } | null => {
    if (!health) return null;
    
    if (health.disk_free_mb < 2048) {
      return {
        show: true,
        severity: 'error',
        message: `Critical disk space: ${health.disk_free_mb.toFixed(2)} MB remaining`
      };
    }
    
    if (health.disk_free_mb < 10240) {
      return {
        show: true,
        severity: 'warning',
        message: `Low disk space: ${health.disk_free_mb.toFixed(2)} MB remaining`
      };
    }
    
    return null;
  };

  const diskAlert = getDiskSpaceAlert();

  if (loading && !info) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight="400px">
        <CircularProgress />
      </Box>
    );
  }

  if (error) {
    return (
      <Alert severity="error" action={
        <Button color="inherit" size="small" onClick={fetchData}>
          Retry
        </Button>
      }>
        {error}
      </Alert>
    );
  }

  return (
    <Box>
      {/* PART A1 — System-wide status banner */}
      {systemStatus === 'critical' && (
        <Alert severity="error" icon={<ErrorIcon />} sx={{ mb: 3 }}>
          <AlertTitle>System Unstable</AlertTitle>
          Critical issues detected. Please check health status immediately.
        </Alert>
      )}
      {systemStatus === 'warning' && (
        <Alert severity="warning" icon={<Warning />} sx={{ mb: 3 }}>
          <AlertTitle>System Requires Attention</AlertTitle>
          Some services are degraded or resources are low.
        </Alert>
      )}
      {systemStatus === 'ok' && (
        <Alert severity="success" icon={<CheckCircle />} sx={{ mb: 3 }}>
          <AlertTitle>All Systems Operational</AlertTitle>
          All services are running normally.
        </Alert>
      )}

      {/* PART A2 — Disk space alert */}
      {diskAlert && diskAlert.show && (
        <Alert severity={diskAlert.severity} sx={{ mb: 3 }}>
          {diskAlert.message}
        </Alert>
      )}

      <Box display="flex" justifyContent="space-between" alignItems="center" mb={3}>
        <Typography variant="h4">Telemetry Dashboard</Typography>
        <Box display="flex" gap={2} alignItems="center">
          <FormControlLabel
            control={<Switch checked={autoRefresh} onChange={(e) => setAutoRefresh(e.target.checked)} />}
            label="Auto-refresh (60s)"
          />
          <Button
            variant="contained"
            startIcon={loading ? <CircularProgress size={20} color="inherit" /> : <RefreshIcon />}
            onClick={fetchData}
            disabled={loading}
          >
            Refresh
          </Button>
        </Box>
      </Box>

      <Grid container spacing={3}>
        {/* System Info */}
        <Grid item xs={12} md={3}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>System Info</Typography>
              {info && (
                <Box>
                  <Typography variant="body2"><strong>App:</strong> {info.app_name}</Typography>
                  <Typography variant="body2"><strong>Laravel:</strong> {info.laravel_version}</Typography>
                  <Typography variant="body2"><strong>PHP:</strong> {info.php_version}</Typography>
                  <Typography variant="body2"><strong>Env:</strong> {info.app_env}</Typography>
                  <Typography variant="body2"><strong>Debug:</strong> {info.app_debug ? 'ON' : 'OFF'}</Typography>
                  <Typography variant="body2"><strong>Timezone:</strong> {info.timezone}</Typography>
                </Box>
              )}
            </CardContent>
          </Card>
        </Grid>

        {/* Billing */}
        <Grid item xs={12} md={3}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>Billing</Typography>
              {billing && (
                <Box>
                  <Typography variant="body2"><strong>Subscriptions:</strong> {billing.subscriptions.total}</Typography>
                  <Typography variant="body2"><strong>Active:</strong> {billing.subscriptions.active}</Typography>
                  <Typography variant="body2"><strong>Trial:</strong> {billing.subscriptions.trial}</Typography>
                  <Typography variant="body2"><strong>Payments:</strong> {billing.payments.total}</Typography>
                  <Typography variant="body2">
                    <strong>Revenue:</strong> {billing.payments.currency}{billing.payments.total_revenue.toFixed(2)}
                  </Typography>
                </Box>
              )}
            </CardContent>
          </Card>
        </Grid>

        {/* Usage */}
        <Grid item xs={12} md={3}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>Usage</Typography>
              {usage && (
                <Box>
                  <Typography variant="body2"><strong>Timesheets:</strong> {usage.timesheets.total} (today: {usage.timesheets.today})</Typography>
                  <Typography variant="body2"><strong>Expenses:</strong> {usage.expenses.total} (today: {usage.expenses.today})</Typography>
                  <Typography variant="body2"><strong>Users:</strong> {usage.users.total} (active: {usage.users.active})</Typography>
                </Box>
              )}
            </CardContent>
          </Card>
        </Grid>

        {/* Tenants Count */}
        <Grid item xs={12} md={3}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>Tenants</Typography>
              <Typography variant="h3">{tenants.length}</Typography>
            </CardContent>
          </Card>
        </Grid>

        {/* PART A1 — Enhanced System Health with badges */}
        <Grid item xs={12} md={6}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>System Health</Typography>
              {health && (
                <Box>
                  <Box display="flex" alignItems="center" gap={1} mb={1}>
                    <Typography variant="body2"><strong>App:</strong></Typography>
                    <Chip 
                      label={health.app} 
                      color={health.app === 'ok' ? 'success' : health.app === 'warning' ? 'warning' : 'error'} 
                      size="small" 
                    />
                  </Box>
                  <Box display="flex" alignItems="center" gap={1} mb={1}>
                    <Typography variant="body2"><strong>Database:</strong></Typography>
                    <Chip 
                      label={health.database} 
                      color={health.database === 'ok' ? 'success' : health.database === 'warning' ? 'warning' : 'error'} 
                      size="small" 
                    />
                  </Box>
                  <Box display="flex" alignItems="center" gap={1} mb={1}>
                    <Typography variant="body2"><strong>Redis:</strong></Typography>
                    <Chip 
                      label={health.redis} 
                      color={health.redis === 'ok' ? 'success' : health.redis === 'disabled' ? 'default' : health.redis === 'warning' ? 'warning' : 'error'} 
                      size="small" 
                    />
                  </Box>
                  <Box display="flex" alignItems="center" gap={1}>
                    <Typography variant="body2"><strong>Disk Free:</strong></Typography>
                    <Chip 
                      label={`${health.disk_free_mb.toFixed(2)} MB`}
                      color={health.disk_free_mb < 2048 ? 'error' : health.disk_free_mb < 10240 ? 'warning' : 'success'}
                      size="small"
                    />
                  </Box>
                </Box>
              )}
            </CardContent>
          </Card>
        </Grid>

        {/* Recent Errors Summary */}
        <Grid item xs={12} md={6}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>Recent Errors ({errors.length})</Typography>
              {errors.length === 0 ? (
                <Typography variant="body2" color="text.secondary">No errors logged</Typography>
              ) : (
                <Box maxHeight={200} overflow="auto">
                  {errors.slice(0, 5).map((err, idx) => (
                    <Box key={idx} mb={1} p={1} bgcolor="error.light" borderRadius={1}>
                      <Typography variant="caption" display="block">
                        <strong>{err.level}</strong> - {err.timestamp}
                      </Typography>
                      <Typography variant="body2" fontSize="0.75rem">
                        {err.message.substring(0, 100)}{err.message.length > 100 ? '...' : ''}
                      </Typography>
                    </Box>
                  ))}
                </Box>
              )}
            </CardContent>
          </Card>
        </Grid>

        {/* PART C2 — Enhanced Tenants Table with links */}
        <Grid item xs={12}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>Tenants List</Typography>
              <TableContainer component={Paper}>
                <Table>
                  <TableHead>
                    <TableRow>
                      <TableCell>Slug</TableCell>
                      <TableCell>Name</TableCell>
                      <TableCell>Owner Email</TableCell>
                      <TableCell>Status</TableCell>
                      <TableCell>Plan</TableCell>
                      <TableCell>Created</TableCell>
                      <TableCell>Trial Ends</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {tenants.map((tenant) => (
                      <TableRow key={tenant.id} hover sx={{ cursor: 'pointer' }}>
                        <TableCell>
                          <Link
                            component="button"
                            variant="body2"
                            onClick={() => navigate(`/admin/telemetry/tenant/${tenant.slug}`)}
                          >
                            {tenant.slug}
                          </Link>
                        </TableCell>
                        <TableCell>{tenant.name}</TableCell>
                        <TableCell>{tenant.owner_email}</TableCell>
                        <TableCell>
                          <Chip
                            label={tenant.status}
                            color={tenant.status === 'active' ? 'success' : tenant.status === 'suspended' ? 'error' : 'warning'}
                            size="small"
                          />
                        </TableCell>
                        <TableCell>
                          <Chip label={tenant.plan} color="primary" size="small" />
                        </TableCell>
                        <TableCell>{formatTenantDate(tenant.created_at, tenantContext)}</TableCell>
                        <TableCell>
                          {tenant.trial_ends_at ? formatTenantDate(tenant.trial_ends_at, tenantContext) : '-'}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
            </CardContent>
          </Card>
        </Grid>

        {/* PART B — Error Log Analyzer (full table with modal) */}
        <Grid item xs={12}>
          <Typography variant="h5" gutterBottom>Error Log Analyzer</Typography>
          <ErrorLogTable errors={errors} />
        </Grid>

        {/* PART D — API Performance Monitor */}
        <Grid item xs={12}>
          <PerformanceMonitor />
        </Grid>
      </Grid>
    </Box>
  );
};

export default TelemetryDashboard;
