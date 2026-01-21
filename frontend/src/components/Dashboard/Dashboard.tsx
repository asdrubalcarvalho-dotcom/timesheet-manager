import React, { useState, useEffect } from 'react';
import {
  Box,
  Grid,
  Card,
  CardContent,
  Typography,
  CircularProgress,
  Alert,
  AlertTitle,
  Button,
  Paper,
  useTheme,
  alpha
} from '@mui/material';
import {
  TrendingUp,
  AttachMoney,
  AccessTime,
  PendingActions,
  CheckCircle
} from '@mui/icons-material';
import {
  BarChart,
  Bar,
  LineChart,
  Line,
  PieChart,
  Pie,
  Cell,
  XAxis,
  YAxis,
  CartesianGrid,
  Tooltip,
  Legend,
  ResponsiveContainer
} from 'recharts';
import { dashboardApi } from '../../services/api';
import type { DashboardStatistics } from '../../types';
import { useAuth } from '../Auth/AuthContext';
import PageHeader from '../Common/PageHeader';
import { useNavigate } from 'react-router-dom';
import { getPolicyAlertModel } from '../../utils/policyAlert';
import { formatTenantDate, formatTenantMoney, formatTenantNumber } from '../../utils/tenantFormatting';

const Dashboard: React.FC = () => {
  const theme = useTheme();
  const { user, tenantContext } = useAuth();
  const navigate = useNavigate();
  const [loading, setLoading] = useState(true);
  const [error, setError] = useState('');
  const [stats, setStats] = useState<DashboardStatistics | null>(null);

  const policyAlert = React.useMemo(() => getPolicyAlertModel(tenantContext), [tenantContext]);

  useEffect(() => {
    loadStatistics();
  }, []);

  // Helper function to truncate long project names
  const truncateLabel = (label: string, maxLength: number = 20): string => {
    if (label.length <= maxLength) return label;
    return label.substring(0, maxLength - 3) + '...';
  };

  const formatChartDate = (value: string): string => formatTenantDate(value, tenantContext);

  const loadStatistics = async () => {
    try {
      setLoading(true);
      setError('');
      const data = await dashboardApi.getStatistics({
        date_from: new Date(Date.now() - 30 * 24 * 60 * 60 * 1000).toISOString().split('T')[0],
        date_to: new Date().toISOString().split('T')[0]
      });
      setStats(data);
    } catch (err: any) {
      console.error('Error loading dashboard statistics:', err);
      setError(err.response?.data?.message || 'Failed to load dashboard statistics');
    } finally {
      setLoading(false);
    }
  };

  // Color palette
  const COLORS = {
    primary: theme.palette.primary.main,
    secondary: theme.palette.secondary.main,
    success: theme.palette.success.main,
    warning: theme.palette.warning.main,
    error: theme.palette.error.main,
    info: theme.palette.info.main
  };

  const STATUS_COLORS: Record<string, string> = {
    Draft: COLORS.info,
    Submitted: COLORS.warning,
    Approved: COLORS.success,
    Rejected: COLORS.error,
    Closed: alpha(theme.palette.text.primary, 0.6)
  };

  const CHART_COLORS = [
    COLORS.primary,
    COLORS.secondary,
    COLORS.success,
    COLORS.warning,
    COLORS.info,
    COLORS.error,
    '#9c27b0',
    '#ff9800',
    '#795548',
    '#607d8b'
  ];

  if (loading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '400px' }}>
        <CircularProgress />
      </Box>
    );
  }

  if (error) {
    return (
      <Box sx={{ p: 3 }}>
        <Alert severity="error">{error}</Alert>
      </Box>
    );
  }

  if (!stats) {
    return (
      <Box sx={{ p: 3 }}>
        <Alert severity="info">No data available</Alert>
      </Box>
    );
  }

  return (
    <Box sx={{ p: 3 }}>
      <PageHeader
        title="Dashboard"
        subtitle={`Welcome back, ${user?.name || 'User'}! Here's your overview for the last 30 days.`}
      />

      {policyAlert && (
        <Alert
          severity={policyAlert.severity}
          sx={{ mt: 2 }}
          action={
            policyAlert.cta ? (
              <Button color="inherit" size="small" onClick={() => navigate(policyAlert.cta!.to)}>
                {policyAlert.cta.label}
              </Button>
            ) : null
          }
        >
          <AlertTitle>{policyAlert.title}</AlertTitle>
          {policyAlert.message}
        </Alert>
      )}

      {/* Summary Cards */}
      <Grid container spacing={3} sx={{ mb: 4, mt: 4 }}>
        <Grid item xs={12} sm={6} md={3}>
          <Card
            sx={{
              background: `linear-gradient(135deg, ${COLORS.primary} 0%, ${alpha(COLORS.primary, 0.8)} 100%)`,
              color: 'white'
            }}
          >
            <CardContent>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                <AccessTime sx={{ fontSize: 40, mr: 2 }} />
                <Box>
                  <Typography variant="h4" sx={{ fontWeight: 'bold' }}>
                    {formatTenantNumber(stats.summary.total_hours, tenantContext, 1)}
                  </Typography>
                  <Typography variant="body2">Total Hours</Typography>
                </Box>
              </Box>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} sm={6} md={3}>
          <Card
            sx={{
              background: `linear-gradient(135deg, ${COLORS.success} 0%, ${alpha(COLORS.success, 0.8)} 100%)`,
              color: 'white'
            }}
          >
            <CardContent>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                <AttachMoney sx={{ fontSize: 40, mr: 2 }} />
                <Box>
                  <Typography variant="h4" sx={{ fontWeight: 'bold' }}>
                    {formatTenantMoney(stats.summary.total_expenses, tenantContext)}
                  </Typography>
                  <Typography variant="body2">Total Expenses</Typography>
                </Box>
              </Box>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} sm={6} md={3}>
          <Card
            sx={{
              background: `linear-gradient(135deg, ${COLORS.warning} 0%, ${alpha(COLORS.warning, 0.8)} 100%)`,
              color: 'white'
            }}
          >
            <CardContent>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                <PendingActions sx={{ fontSize: 40, mr: 2 }} />
                <Box>
                  <Typography variant="h4" sx={{ fontWeight: 'bold' }}>
                    {formatTenantNumber(stats.summary.pending_timesheets + stats.summary.pending_expenses, tenantContext, 0)}
                  </Typography>
                  <Typography variant="body2">
                    Pending ({formatTenantNumber(stats.summary.pending_timesheets, tenantContext, 0)}T + {formatTenantNumber(stats.summary.pending_expenses, tenantContext, 0)}E)
                  </Typography>
                </Box>
              </Box>
            </CardContent>
          </Card>
        </Grid>

        <Grid item xs={12} sm={6} md={3}>
          <Card
            sx={{
              background: `linear-gradient(135deg, ${COLORS.info} 0%, ${alpha(COLORS.info, 0.8)} 100%)`,
              color: 'white'
            }}
          >
            <CardContent>
              <Box sx={{ display: 'flex', alignItems: 'center', mb: 1 }}>
                <CheckCircle sx={{ fontSize: 40, mr: 2 }} />
                <Box>
                  <Typography variant="h4" sx={{ fontWeight: 'bold' }}>
                    {formatTenantNumber(stats.summary.approved_timesheets + stats.summary.approved_expenses, tenantContext, 0)}
                  </Typography>
                  <Typography variant="body2">
                    Approved ({formatTenantNumber(stats.summary.approved_timesheets, tenantContext, 0)}T + {formatTenantNumber(stats.summary.approved_expenses, tenantContext, 0)}E)
                  </Typography>
                </Box>
              </Box>
            </CardContent>
          </Card>
        </Grid>
      </Grid>

      {/* Charts Row 1 */}
      <Grid container spacing={3} sx={{ mb: 4 }}>
        {/* Hours by Project */}
        <Grid item xs={12} md={6}>
          <Paper sx={{ p: 3, height: '400px', display: 'flex', flexDirection: 'column' }}>
            <Typography variant="h6" sx={{ mb: 2, display: 'flex', alignItems: 'center' }}>
              <TrendingUp sx={{ mr: 1 }} />
              Hours by Project
            </Typography>
            {stats.hours_by_project.length > 0 ? (
            <Box sx={{ flex: 1, minHeight: 0 }}>
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={stats.hours_by_project}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis 
                  dataKey="project_name" 
                  angle={-45}
                  textAnchor="end"
                  height={100}
                  interval={0}
                  tickFormatter={(value) => truncateLabel(value, 15)}
                />
                <YAxis />
                <Tooltip 
                  content={({ active, payload }) => {
                    if (active && payload && payload.length) {
                      return (
                        <Paper sx={{ p: 1.5 }}>
                          <Typography variant="body2" sx={{ fontWeight: 'bold' }}>
                            {payload[0].payload.project_name}
                          </Typography>
                          <Typography variant="body2" color="primary">
                            Hours: {formatTenantNumber(Number(payload[0].value), tenantContext, 1)}
                          </Typography>
                        </Paper>
                      );
                    }
                    return null;
                  }}
                />
                <Legend />
                <Bar dataKey="total_hours" fill={COLORS.primary} name="Hours" />
              </BarChart>
            </ResponsiveContainer>
            </Box>
            ) : (
              <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <Typography variant="body2" color="text.secondary">No data available</Typography>
              </Box>
            )}
          </Paper>
        </Grid>

        {/* Expenses by Project */}
        <Grid item xs={12} md={6}>
          <Paper sx={{ p: 3, height: '400px', display: 'flex', flexDirection: 'column' }}>
            <Typography variant="h6" sx={{ mb: 2, display: 'flex', alignItems: 'center' }}>
              <AttachMoney sx={{ mr: 1 }} />
              Expenses by Project
            </Typography>
            {stats.expenses_by_project.length > 0 ? (
            <Box sx={{ flex: 1, minHeight: 0 }}>
              <ResponsiveContainer width="100%" height="100%">
                <BarChart data={stats.expenses_by_project}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis 
                  dataKey="project_name" 
                  angle={-45}
                  textAnchor="end"
                  height={100}
                  interval={0}
                  tickFormatter={(value) => truncateLabel(value, 15)}
                />
                <YAxis />
                <Tooltip 
                  content={({ active, payload }) => {
                    if (active && payload && payload.length) {
                      return (
                        <Paper sx={{ p: 1.5 }}>
                          <Typography variant="body2" sx={{ fontWeight: 'bold' }}>
                            {payload[0].payload.project_name}
                          </Typography>
                          <Typography variant="body2" color="success.main">
                            Amount: {formatTenantMoney(Number(payload[0].value), tenantContext)}
                          </Typography>
                        </Paper>
                      );
                    }
                    return null;
                  }}
                />
                <Legend />
                <Bar dataKey="total_amount" fill={COLORS.success} name="Amount" />
              </BarChart>
            </ResponsiveContainer>
            </Box>
            ) : (
              <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <Typography variant="body2" color="text.secondary">No data available</Typography>
              </Box>
            )}
          </Paper>
        </Grid>
      </Grid>

      {/* Charts Row 2 */}
      <Grid container spacing={3} sx={{ mb: 4 }}>
        {/* Hours by Status - Pie Chart */}
        <Grid item xs={12} md={6}>
          <Paper sx={{ p: 3, height: '400px', display: 'flex', flexDirection: 'column' }}>
            <Typography variant="h6" sx={{ mb: 2 }}>
              Timesheets by Status
            </Typography>
            {stats.hours_by_status.length > 0 ? (
            <Box sx={{ flex: 1, minHeight: 0 }}>
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                <Pie
                  data={stats.hours_by_status as any}
                  dataKey="count"
                  nameKey="status"
                  cx="50%"
                  cy="50%"
                  outerRadius={100}
                  label={(entry) => {
                    const payload = (entry as any)?.payload;
                    const status = payload?.status ?? '';
                    const count = payload?.count ?? '';
                    return `${status}: ${count}`;
                  }}
                >
                  {stats.hours_by_status.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={STATUS_COLORS[entry.status] || CHART_COLORS[index % CHART_COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
            </Box>
            ) : (
              <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <Typography variant="body2" color="text.secondary">No data available</Typography>
              </Box>
            )}
          </Paper>
        </Grid>

        {/* Expenses by Status - Pie Chart */}
        <Grid item xs={12} md={6}>
          <Paper sx={{ p: 3, height: '400px', display: 'flex', flexDirection: 'column' }}>
            <Typography variant="h6" sx={{ mb: 2 }}>
              Expenses by Status
            </Typography>
            {stats.expenses_by_status.length > 0 ? (
            <Box sx={{ flex: 1, minHeight: 0 }}>
              <ResponsiveContainer width="100%" height="100%">
                <PieChart>
                <Pie
                  data={stats.expenses_by_status as any}
                  dataKey="count"
                  nameKey="status"
                  cx="50%"
                  cy="50%"
                  outerRadius={100}
                  label={(entry) => {
                    const payload = (entry as any)?.payload;
                    const status = payload?.status ?? '';
                    const count = payload?.count ?? '';
                    return `${status}: ${count}`;
                  }}
                >
                  {stats.expenses_by_status.map((entry, index) => (
                    <Cell key={`cell-${index}`} fill={STATUS_COLORS[entry.status] || CHART_COLORS[index % CHART_COLORS.length]} />
                  ))}
                </Pie>
                <Tooltip />
                <Legend />
              </PieChart>
            </ResponsiveContainer>
            </Box>
            ) : (
              <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <Typography variant="body2" color="text.secondary">No data available</Typography>
              </Box>
            )}
          </Paper>
        </Grid>
      </Grid>

      {/* Charts Row 3 - Trends */}
      <Grid container spacing={3}>
        {/* Daily Hours Trend */}
        <Grid item xs={12} md={6}>
          <Paper sx={{ p: 3, height: '400px', display: 'flex', flexDirection: 'column' }}>
            <Typography variant="h6" sx={{ mb: 2 }}>
              Daily Hours Trend
            </Typography>
            {stats.daily_hours.length > 0 ? (
            <Box sx={{ flex: 1, minHeight: 0 }}>
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={stats.daily_hours}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis 
                  dataKey="date" 
                  angle={-45}
                  textAnchor="end"
                  height={80}
                  tickFormatter={(value) => formatChartDate(value)}
                />
                <YAxis />
                <Tooltip 
                  labelFormatter={(value) => formatChartDate(String(value))}
                  formatter={(value) => [`${formatTenantNumber(Number(value), tenantContext, 1)} hours`, 'Hours']}
                />
                <Legend />
                <Line 
                  type="monotone" 
                  dataKey="hours" 
                  stroke={COLORS.primary} 
                  strokeWidth={2}
                  name="Hours"
                  dot={{ fill: COLORS.primary }}
                />
              </LineChart>
            </ResponsiveContainer>
            </Box>
            ) : (
              <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <Typography variant="body2" color="text.secondary">No data available</Typography>
              </Box>
            )}
          </Paper>
        </Grid>

        {/* Daily Expenses Trend */}
        <Grid item xs={12} md={6}>
          <Paper sx={{ p: 3, height: '400px', display: 'flex', flexDirection: 'column' }}>
            <Typography variant="h6" sx={{ mb: 2 }}>
              Daily Expenses Trend
            </Typography>
            {stats.daily_expenses.length > 0 ? (
            <Box sx={{ flex: 1, minHeight: 0 }}>
              <ResponsiveContainer width="100%" height="100%">
                <LineChart data={stats.daily_expenses}>
                <CartesianGrid strokeDasharray="3 3" />
                <XAxis 
                  dataKey="date" 
                  angle={-45}
                  textAnchor="end"
                  height={80}
                  tickFormatter={(value) => formatChartDate(value)}
                />
                <YAxis />
                <Tooltip 
                  labelFormatter={(value) => formatChartDate(String(value))}
                  formatter={(value) => [formatTenantMoney(Number(value), tenantContext), 'Amount']}
                />
                <Legend />
                <Line 
                  type="monotone" 
                  dataKey="amount" 
                  stroke={COLORS.success} 
                  strokeWidth={2}
                  name="Amount"
                  dot={{ fill: COLORS.success }}
                />
              </LineChart>
            </ResponsiveContainer>
            </Box>
            ) : (
              <Box sx={{ flex: 1, display: 'flex', alignItems: 'center', justifyContent: 'center' }}>
                <Typography variant="body2" color="text.secondary">No data available</Typography>
              </Box>
            )}
          </Paper>
        </Grid>
      </Grid>
    </Box>
  );
};

export default Dashboard;
