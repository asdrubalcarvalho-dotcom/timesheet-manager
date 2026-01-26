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
  Paper,
  Table,
  TableHead,
  TableRow,
  TableCell,
  TableBody,
  TableContainer,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  TextField,
  Checkbox,
  FormControlLabel
} from '@mui/material';
import { ArrowBack } from '@mui/icons-material';
import { useParams, useNavigate } from 'react-router-dom';
import api from '../../services/api';

import { useAuth } from '../Auth/AuthContext';
import { formatTenantDateTime } from '../../utils/tenantFormatting';
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

type TenantBillingDetails = {
  tenant: {
    id: string;
    slug: string;
    name: string;
    status: string;
    plan: string;
  };
  subscription: any | null;
  history: Record<string, any[]>;
};

interface UsageData {
  timesheets: { total: number; today: number };
  expenses: { total: number; today: number };
  users: { total: number; active: number };
}

type GenericRow = Record<string, any>;

const DATE_CANDIDATE_KEYS = [
  'failed_at',
  'changed_at',
  'created_at',
  'updated_at',
  'resolved_at',
  'next_reminder_at',
  'last_reminder_at',
  'trial_ends_at',
  'next_renewal_at',
  'date',
  'timestamp',
];

const getBestDateKey = (rows: GenericRow[]): string | null => {
  if (rows.length === 0) return null;
  const sample = rows[0] ?? {};
  for (const key of DATE_CANDIDATE_KEYS) {
    if (key in sample) return key;
  }
  // Heuristic fallback: any *_at key
  const anyAt = Object.keys(sample).find((k) => k.endsWith('_at'));
  return anyAt ?? null;
};

const toSortableTime = (value: any): number | null => {
  if (value == null) return null;
  const d = new Date(value);
  const t = d.getTime();
  return Number.isFinite(t) ? t : null;
};

const getColumnsForRows = (rows: GenericRow[]): string[] => {
  const keys = new Set<string>();
  for (const row of rows) {
    Object.keys(row ?? {}).forEach((k) => keys.add(k));
  }
  const cols = Array.from(keys);

  const priority = (k: string): number => {
    if (DATE_CANDIDATE_KEYS.includes(k)) return 0;
    if (k.endsWith('_at')) return 1;
    if (k === 'id') return 2;
    if (k.endsWith('_id')) return 3;
    return 10;
  };

  return cols.sort((a, b) => {
    const pa = priority(a);
    const pb = priority(b);
    if (pa !== pb) return pa - pb;
    return a.localeCompare(b);
  });
};

const renderCellValue = (value: any, key: string, tenantContext: any): string => {
  if (value === null || value === undefined) return '—';

  if (typeof value === 'string' && (key.endsWith('_at') || DATE_CANDIDATE_KEYS.includes(key))) {
    // Use existing tenant-aware formatting when the value looks like a datetime.
    const t = toSortableTime(value);
    if (t !== null) return formatTenantDateTime(value, tenantContext);
  }

  if (typeof value === 'boolean') return value ? 'true' : 'false';
  if (typeof value === 'number') return String(value);

  if (typeof value === 'object') {
    try {
      const json = JSON.stringify(value);
      return json.length > 300 ? `${json.slice(0, 300)}…` : json;
    } catch {
      return '[object]';
    }
  }

  return String(value);
};

const BillingHistoryTable: React.FC<{
  title: string;
  rows: any[];
  tenantContext: any;
}> = ({ title, rows, tenantContext }) => {
  const normalized: GenericRow[] = (rows ?? []).map((r) => (r && typeof r === 'object' ? (r as GenericRow) : { value: r }));
  const dateKey = getBestDateKey(normalized);

  const sorted = [...normalized].sort((a, b) => {
    if (!dateKey) return 0;
    const ta = toSortableTime(a?.[dateKey]);
    const tb = toSortableTime(b?.[dateKey]);
    if (ta === null && tb === null) return 0;
    if (ta === null) return 1;
    if (tb === null) return -1;
    return tb - ta;
  });

  const limited = sorted.slice(0, 50);
  const showLimitNote = sorted.length > 50;
  const columns = getColumnsForRows(limited);

  return (
    <Box sx={{ mt: 2 }}>
      <Box display="flex" alignItems="baseline" justifyContent="space-between" sx={{ mb: 1 }}>
        <Typography variant="subtitle2">{title}</Typography>
        {showLimitNote && (
          <Typography variant="caption" color="text.secondary">
            Showing latest 50
          </Typography>
        )}
      </Box>

      <TableContainer component={Paper} variant="outlined">
        <Table size="small">
          <TableHead>
            <TableRow>
              {columns.map((col) => (
                <TableCell key={col} sx={{ fontWeight: 600 }}>
                  {col}
                </TableCell>
              ))}
            </TableRow>
          </TableHead>
          <TableBody>
            {limited.map((row, idx) => (
              <TableRow key={`${title}-${idx}`}>
                {columns.map((col) => (
                  <TableCell key={col} sx={{ verticalAlign: 'top', whiteSpace: 'nowrap' }}>
                    {renderCellValue(row?.[col], col, tenantContext)}
                  </TableCell>
                ))}
              </TableRow>
            ))}
          </TableBody>
        </Table>
      </TableContainer>
    </Box>
  );
};

const TelemetryTenantPage: React.FC = () => {
  const { slug } = useParams<{ slug: string }>();
  const navigate = useNavigate();
  const { tenantContext } = useAuth();
  
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [billingDetails, setBillingDetails] = useState<TenantBillingDetails | null>(null);
  const [usage, setUsage] = useState<UsageData | null>(null);
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState<string | null>(null);

  // Delete flow (strong confirm)
  const [deleteOpen, setDeleteOpen] = useState(false);
  const [deleteStep, setDeleteStep] = useState<1 | 2 | 3>(1);
  const [confirmSlug, setConfirmSlug] = useState('');
  const [confirmIrreversible, setConfirmIrreversible] = useState(false);
  const [deleteLoading, setDeleteLoading] = useState(false);
  const [deleteError, setDeleteError] = useState<string | null>(null);

  const normalizeHistory = (history: any): Record<string, any[]> => {
    const obj = history && typeof history === 'object' ? history : {};
    const getArray = (key: string): any[] => (Array.isArray(obj[key]) ? obj[key] : []);

    // Guarantee known keys are always arrays for UI robustness.
    // This does not invent records; it only prevents null/invalid values from breaking rendering.
    return {
      ...obj,
      plan_change_history: getArray('plan_change_history'),
      subscription_plan_history: getArray('subscription_plan_history'),
      payment_failures: getArray('payment_failures'),
    };
  };

  useEffect(() => {
    const fetchData = async () => {
      try {
        setLoading(true);
        setError(null);

        // Fetch all tenants and filter by slug (frontend filtering per RULE 1)
        const tenantsRes = await api.get('/api/superadmin/telemetry/tenants');

        const allTenants = tenantsRes.data.data.tenants || [];
        const foundTenant = allTenants.find((t: Tenant) => t.slug === slug);

        if (!foundTenant) {
          setError(`Tenant with slug "${slug}" not found`);
          return;
        }

        setTenant(foundTenant);

        const [usageRes, billingDetailsRes] = await Promise.all([
          api.get(`/api/superadmin/telemetry/tenants/${encodeURIComponent(String(slug))}/usage`),
          api.get(`/api/superadmin/telemetry/tenants/${encodeURIComponent(String(slug))}/billing-details`),
        ]);

        setUsage(usageRes.data.data);
        const rawBillingDetails = billingDetailsRes.data?.data;
        setBillingDetails(
          rawBillingDetails
            ? {
                ...rawBillingDetails,
                history: normalizeHistory(rawBillingDetails.history),
              }
            : null
        );
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
      <Box display="flex" alignItems="center" justifyContent="space-between" sx={{ mb: 3 }}>
        <Button startIcon={<ArrowBack />} onClick={() => navigate('/admin/telemetry')}>
          Back to Dashboard
        </Button>

        <Button
          color="error"
          variant="contained"
          onClick={() => {
            setDeleteError(null);
            setDeleteStep(1);
            setConfirmSlug('');
            setConfirmIrreversible(false);
            setDeleteOpen(true);
          }}
        >
          Delete tenant
        </Button>
      </Box>

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
                  <strong>Created:</strong> {formatTenantDateTime(tenant.created_at, tenantContext)}
                </Typography>
                <Typography variant="body2">
                  <strong>Trial Ends:</strong>{' '}
                  {tenant.trial_ends_at ? formatTenantDateTime(tenant.trial_ends_at, tenantContext) : 'N/A'}
                </Typography>
              </Box>
            </CardContent>
          </Card>
        </Grid>

        {/* Section 2: Subscription + History */}
        <Grid item xs={12} md={6}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>Subscription</Typography>
              <Divider sx={{ mb: 2 }} />
              {billingDetails?.subscription ? (
                <Box display="flex" flexDirection="column" gap={1}>
                  <Typography variant="body2"><strong>Status:</strong> {billingDetails.subscription.status ?? 'N/A'}</Typography>
                  <Typography variant="body2"><strong>Plan:</strong> {billingDetails.subscription.plan ?? 'N/A'}</Typography>
                  <Typography variant="body2"><strong>User limit:</strong> {billingDetails.subscription.user_limit ?? 'N/A'}</Typography>
                  <Typography variant="body2"><strong>Is trial:</strong> {String(Boolean(billingDetails.subscription.is_trial))}</Typography>
                  <Typography variant="body2"><strong>Trial ends:</strong> {billingDetails.subscription.trial_ends_at ? formatTenantDateTime(billingDetails.subscription.trial_ends_at, tenantContext) : 'N/A'}</Typography>
                  <Typography variant="body2"><strong>Next renewal:</strong> {billingDetails.subscription.next_renewal_at ? formatTenantDateTime(billingDetails.subscription.next_renewal_at, tenantContext) : 'N/A'}</Typography>
                </Box>
              ) : (
                <Typography variant="body2" color="text.secondary">No subscription found for this tenant</Typography>
              )}

              <Divider sx={{ my: 2 }} />
              <Typography variant="subtitle2" gutterBottom>History</Typography>
              {billingDetails ? (() => {
                const historyEntries = Object.entries(billingDetails.history ?? {}).filter(([, v]) => Array.isArray(v));
                const nonEmpty = historyEntries.filter(([, v]) => (v as any[]).length > 0);

                if (nonEmpty.length === 0) {
                  return <Typography variant="body2" color="text.secondary">No history available</Typography>;
                }

                const titleForKey = (k: string): string => {
                  if (k === 'plan_change_history') return 'Plan change history';
                  if (k === 'subscription_plan_history') return 'Subscription plan history';
                  if (k === 'payment_failures') return 'Payment failures';
                  return k;
                };

                return (
                  <Box>
                    {nonEmpty.map(([key, value]) => (
                      <BillingHistoryTable
                        key={key}
                        title={titleForKey(key)}
                        rows={value as any[]}
                        tenantContext={tenantContext}
                      />
                    ))}
                  </Box>
                );
              })() : (
                <Typography variant="body2" color="text.secondary">Loading billing details…</Typography>
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
                        Active today: {usage.users.active}
                      </Typography>
                    </Paper>
                  </Grid>
                </Grid>
              )}
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      <Dialog open={deleteOpen} onClose={() => (deleteLoading ? null : setDeleteOpen(false))} maxWidth="sm" fullWidth>
        <DialogTitle>Delete tenant (irreversible)</DialogTitle>
        <DialogContent>
          {deleteError && <Alert severity="error" sx={{ mb: 2 }}>{deleteError}</Alert>}

          {deleteStep === 1 && (
            <Box display="flex" flexDirection="column" gap={2} sx={{ mt: 1 }}>
              <Typography variant="body2">
                Step 1/3: Type the tenant slug to confirm deletion.
              </Typography>
              <TextField
                label="Tenant slug"
                value={confirmSlug}
                onChange={(e) => setConfirmSlug(e.target.value)}
                placeholder={tenant.slug}
                fullWidth
              />
            </Box>
          )}

          {deleteStep === 2 && (
            <Box display="flex" flexDirection="column" gap={2} sx={{ mt: 1 }}>
              <Typography variant="body2">
                Step 2/3: Acknowledge this action is irreversible.
              </Typography>
              <FormControlLabel
                control={<Checkbox checked={confirmIrreversible} onChange={(e) => setConfirmIrreversible(e.target.checked)} />}
                label="I understand this is irreversible"
              />
            </Box>
          )}

          {deleteStep === 3 && (
            <Box display="flex" flexDirection="column" gap={2} sx={{ mt: 1 }}>
              <Typography variant="body2">
                Step 3/3: Final confirmation.
              </Typography>
              <Alert severity="warning">
                This will delete tenant <strong>{tenant.slug}</strong> and drop its database.
              </Alert>
            </Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button
            onClick={() => {
              if (deleteLoading) return;
              if (deleteStep === 1) return setDeleteOpen(false);
              setDeleteStep((deleteStep - 1) as 1 | 2 | 3);
              setDeleteError(null);
            }}
            disabled={deleteLoading}
          >
            {deleteStep === 1 ? 'Cancel' : 'Back'}
          </Button>

          {deleteStep < 3 ? (
            <Button
              variant="contained"
              onClick={() => {
                setDeleteError(null);
                setDeleteStep((deleteStep + 1) as 1 | 2 | 3);
              }}
              disabled={deleteLoading || (deleteStep === 1 && confirmSlug !== tenant.slug) || (deleteStep === 2 && !confirmIrreversible)}
            >
              Next
            </Button>
          ) : (
            <Button
              color="error"
              variant="contained"
              disabled={deleteLoading || confirmSlug !== tenant.slug || !confirmIrreversible}
              onClick={async () => {
                try {
                  setDeleteLoading(true);
                  setDeleteError(null);

                  await api.post(`/api/superadmin/telemetry/tenants/${encodeURIComponent(String(slug))}/delete`, {
                    confirm_slug: confirmSlug,
                    confirm_irreversible: confirmIrreversible,
                    confirm_final: true,
                  });

                  setDeleteOpen(false);
                  navigate('/admin/telemetry');
                } catch (err: any) {
                  setDeleteError(err.response?.data?.message || err.message || 'Failed to delete tenant');
                } finally {
                  setDeleteLoading(false);
                }
              }}
            >
              Delete now
            </Button>
          )}
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default TelemetryTenantPage;
