/**
 * COPILOT GLOBAL RULES — DO NOT IGNORE
 * See backend/config/telemetry.php for complete rules
 * 
 * This component fetches data from /api/superadmin/telemetry/* endpoints ONLY
 * NEVER calls /api/admin/telemetry/* (internal API) directly
 */
/* 
--------------------------------------------------------------------------------
COPILOT FRONTEND INSTRUCTIONS — TELEMETRY DASHBOARD
--------------------------------------------------------------------------------

GOAL:
Atualizar o TelemetryDashboard React para usar TAMBÉM os novos endpoints:
  - GET /api/superadmin/telemetry/health
  - GET /api/superadmin/telemetry/errors

REGRAS GERAIS:
- NUNCA chamar /api/admin/telemetry/* diretamente do frontend.
- SEMPRE usar apenas /api/superadmin/telemetry/*.
- NÃO expor nenhuma API key.
- NÃO inventar endpoints, modelos, campos ou tipos.
- Usar apenas os campos realmente devolvidos pelas APIs.

ENDPOINTS DISPONÍVEIS (BACKEND JÁ IMPLEMENTADO):
  GET /api/superadmin/telemetry/info
  GET /api/superadmin/telemetry/tenants
  GET /api/superadmin/telemetry/billing
  GET /api/superadmin/telemetry/usage
  GET /api/superadmin/telemetry/health
  GET /api/superadmin/telemetry/errors

FORMATO ESPERADO DAS RESPOSTAS:

1) /info
{
  success: true,
  data: {
    app_name: string,
    app_env: string,
    app_debug: boolean,
    app_url: string,
    php_version: string,
    laravel_version: string,
    timezone: string,
    database_connection: string
  }
}

2) /tenants
{
  success: true,
  data: {
    total: number,
    tenants: Array<{
      id: string,
      slug: string,
      name?: string|null,
      owner_email: string|null,
      status: string,
      plan: string|null,
      created_at: string|null,
      trial_ends_at: string|null
    }>
  }
}

3) /billing
{
  success: true,
  data: {
    subscriptions: {
      total: number,
      active: number,
      trial: number,
      by_plan: Record<string, number>
    },
    payments: {
      total: number,
      completed: number,
      total_revenue: number,
      currency: string
    }
  }
}

4) /usage
{
  success: true,
  data: {
    timesheets: { total: number, today: number },
    expenses: { total: number, today: number },
    users: { total: number, active: number }
  },
  note?: string
}

5) /health
{
  success: true,
  data: {
    cpu_load: number|null,
    memory_usage_percent: number|null,
    disk_free_gb: number|null,
    disk_total_gb: number|null,
    disk_usage_percent: number|null,
    queue_connection: string|null,
    cache_connection: string|null,
    database_connection: string|null
  }
}

6) /errors
{
  success: true,
  data: {
    lines: string[]   // últimas linhas do laravel.log
  },
  message?: string   // opcional em caso de "not implemented" ou ficheiro em falta
}

UI QUE DEVES IMPLEMENTAR/ATUALIZAR:

1) CARDS NO TOPO (grid responsivo, MUI):
   - Card "System Info":
       - App, Env, Debug, PHP, Laravel, Timezone, DB
   - Card "Billing":
       - Subscriptions total/active/trial
       - Payments total/completed
       - Revenue formatado com currency
   - Card "Usage":
       - Timesheets total/hoje
       - Expenses total/hoje
       - Users total/ativos
   - NOVO Card "Health":
       - Mostrar:
           CPU Load (formatado, ex: "0.32")
           Memory Usage (ex: "68%")
           Disk Usage (ex: "123 / 250 GB (49%)")
           Queue: nome da ligação
           Cache: nome da ligação
       - Se algum campo vier null → mostrar "N/A".

2) TABELA DE TENANTS:
   - Usar MUI Table.
   - Colunas:
       Slug, Name, Owner Email, Status, Plan, Created, Trial Ends
   - Usar os campos que vêm em data.tenants.

3) LOGS DE ERRO (NOVO PAINEL):
   - Secção em baixo chamada "Recent Error Logs".
   - Usar Paper + Typography + Box.
   - Mostrar:
       - Se success=true e data.lines existir:
           - Listar as últimas linhas numa <pre> ou lista com scroll (altura fixa).
       - Se não houver linhas:
           - Mostrar texto "No recent errors found".
       - Se success=false:
           - Mostrar mensagem de erro vinda de response.message.
   - NÃO formatar / interpretar as linhas, apenas mostrar texto.

4) CONTROLOS:
   - Botão "Refresh" reutilizado → volta a chamar TODOS os endpoints.
   - Toggle "Auto-refresh (60s)" já existente:
       - Se ativo → usar setInterval para refetch a cada 60000ms.
       - Limpar intervalo no unmount / desativar toggle.

IMPLEMENTAÇÃO TÉCNICA:

- Usar React functional component + hooks (useState, useEffect).
- Usar a instância de API já existente (ex: api.get('/api/superadmin/telemetry/info')).
- Centralizar a função fetchAllTelemetry() que:
      - faz Promise.all às 6 chamadas
      - atualiza 6 estados independentes (info, tenants, billing, usage, health, errors)
- Usar AlertSnackbar (já existente no projeto) para:
      - Erros de rede
      - Erros de success=false vindo do backend

- NÃO criar novos serviços em api.ts a não ser que já exista padrão para isso.
  Se já houver funções específicas para os endpoints, reutiliza-as.

ESTILO:
- Usar componentes MUI já usados no resto da app (Paper, Card, CardContent, Typography, Grid, Switch, Button, Table, TableHead, TableRow, TableCell, TableBody).
- Layout limpo, sem inventar temas novos.

NÃO FAZER:
- Não chamar diretamente /api/admin/telemetry/*.
- Não expor nenhuma API key.
- Não inventar novos campos (usar apenas os listados acima).
- Não adicionar lógica de routing nova fora do já existente.

--------------------------------------------------------------------------------
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
  FormControlLabel
} from '@mui/material';
import { Refresh as RefreshIcon } from '@mui/icons-material';
import api from '../../services/api';

interface SystemInfo {
  app_name: string;
  laravel_version: string;
  php_version: string;
  environment: string;
  debug: boolean;
  timezone: string;
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
    by_plan: Record<string, number>;
  };
  payments: {
    total: number;
    total_revenue: number;
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
                  <Typography variant="body2"><strong>Env:</strong> {info.environment}</Typography>
                  <Typography variant="body2"><strong>Debug:</strong> {info.debug ? 'ON' : 'OFF'}</Typography>
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
                  <Typography variant="body2"><strong>Payments:</strong> {billing.payments.total}</Typography>
                  <Typography variant="body2"><strong>Revenue:</strong> €{billing.payments.total_revenue.toFixed(2)}</Typography>
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

        {/* System Health */}
        <Grid item xs={12} md={6}>
          <Card>
            <CardContent>
              <Typography variant="h6" gutterBottom>System Health</Typography>
              {health && (
                <Box>
                  <Box display="flex" alignItems="center" gap={1} mb={1}>
                    <Typography variant="body2"><strong>App:</strong></Typography>
                    <Chip label={health.app} color={health.app === 'ok' ? 'success' : 'error'} size="small" />
                  </Box>
                  <Box display="flex" alignItems="center" gap={1} mb={1}>
                    <Typography variant="body2"><strong>Database:</strong></Typography>
                    <Chip label={health.database} color={health.database === 'ok' ? 'success' : 'error'} size="small" />
                  </Box>
                  <Box display="flex" alignItems="center" gap={1} mb={1}>
                    <Typography variant="body2"><strong>Redis:</strong></Typography>
                    <Chip 
                      label={health.redis} 
                      color={health.redis === 'ok' ? 'success' : health.redis === 'disabled' ? 'default' : 'error'} 
                      size="small" 
                    />
                  </Box>
                  <Typography variant="body2"><strong>Disk Free:</strong> {health.disk_free_mb.toFixed(2)} MB</Typography>
                </Box>
              )}
            </CardContent>
          </Card>
        </Grid>

        {/* Recent Errors */}
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

        {/* Tenants Table */}
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
                      <TableRow key={tenant.id}>
                        <TableCell>{tenant.slug}</TableCell>
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
                        <TableCell>{new Date(tenant.created_at).toLocaleDateString()}</TableCell>
                        <TableCell>
                          {tenant.trial_ends_at ? new Date(tenant.trial_ends_at).toLocaleDateString() : '-'}
                        </TableCell>
                      </TableRow>
                    ))}
                  </TableBody>
                </Table>
              </TableContainer>
            </CardContent>
          </Card>
        </Grid>
      </Grid>
    </Box>
  );
};

export default TelemetryDashboard;
