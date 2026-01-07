import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Card,
  CardContent,
  CircularProgress,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Typography,
} from '@mui/material';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs from 'dayjs';
import api from '../../services/api';
import PageHeader from '../Common/PageHeader';
import { useBilling } from '../../contexts/BillingContext';
import { getTenantAiState } from '../Common/aiState';
import ReportFiltersCard from '../Common/ReportFiltersCard';
import ReportAISideTab from '../Common/ReportAISideTab';

type Period = 'day' | 'week' | 'month';
type Dimension = 'user' | 'project';

type PivotRequestPayload = {
  period: Period;
  range: {
    from: string;
    to: string;
  };
  dimensions: {
    rows: [Dimension];
    columns: [Dimension];
  };
  metrics: ['hours'];
  include: {
    row_totals: boolean;
    column_totals: boolean;
    grand_total: boolean;
  };
  filters: {
    project_id: number | null;
    status: string | null;
  };
};

type PivotAxisItem = {
  key: string;
  label: string;
};

type PivotResponse = {
  meta: {
    period: Period;
    scoped: 'self' | 'all' | string;
    [key: string]: unknown;
  };
  rows: PivotAxisItem[];
  columns: PivotAxisItem[];
  cells: Record<string, number>;
  totals?: {
    rows?: Record<string, number>;
    columns?: Record<string, number>;
    grand?: number;
  };
};

type PivotResponseApi = {
  meta: {
    period: Period;
    scoped: 'self' | 'all' | string;
    range?: { from?: string; to?: string };
    [key: string]: unknown;
  };
  rows: Array<{ id: string; label: string }>;
  columns: Array<{ id: string; label: string }>;
  cells: Record<string, number>;
  totals?: {
    rows?: Array<{ row_id: string; hours: number }>;
    columns?: Array<{ column_id: string; hours: number }>;
    grand?: { hours: number } | null;
  };
};

const normalizePivotResponse = (apiData: PivotResponseApi): PivotResponse => {
  const rowTotals: Record<string, number> = {};
  const colTotals: Record<string, number> = {};

  for (const r of apiData.totals?.rows ?? []) {
    rowTotals[String(r.row_id)] = typeof r.hours === 'number' ? r.hours : 0;
  }
  for (const c of apiData.totals?.columns ?? []) {
    colTotals[String(c.column_id)] = typeof c.hours === 'number' ? c.hours : 0;
  }

  return {
    meta: apiData.meta,
    rows: (apiData.rows ?? []).map((r) => ({ key: String(r.id), label: String(r.label) })),
    columns: (apiData.columns ?? []).map((c) => ({ key: String(c.id), label: String(c.label) })),
    cells: apiData.cells ?? {},
    totals: {
      rows: rowTotals,
      columns: colTotals,
      grand: typeof apiData.totals?.grand?.hours === 'number' ? apiData.totals.grand.hours : undefined,
    },
  };
};

const answerPivotQuestionDeterministically = (
  question: string,
  data: PivotResponse | null,
  rowDimension: Dimension,
  columnDimension: Dimension
): string => {
  const q = question.trim().toLowerCase();

  if (!data) {
    return 'Load the pivot report first (select a range and wait for results), then ask again.';
  }

  const rowLabelByKey = new Map(data.rows.map((r) => [r.key, r.label] as const));
  const colLabelByKey = new Map(data.columns.map((c) => [c.key, c.label] as const));

  const grand = typeof data.totals?.grand === 'number' ? data.totals.grand : null;
  const scoped = data.meta?.scoped ? String(data.meta.scoped) : '—';
  const topRowTotals = Object.entries(data.totals?.rows ?? {})
    .map(([k, v]) => ({ key: k, label: rowLabelByKey.get(k) ?? k, hours: typeof v === 'number' ? v : 0 }))
    .sort((a, b) => b.hours - a.hours)
    .slice(0, 3);
  const topColTotals = Object.entries(data.totals?.columns ?? {})
    .map(([k, v]) => ({ key: k, label: colLabelByKey.get(k) ?? k, hours: typeof v === 'number' ? v : 0 }))
    .sort((a, b) => b.hours - a.hours)
    .slice(0, 3);

  let peak: { rowKey: string; colKey: string; hours: number } | null = null;
  for (const [k, v] of Object.entries(data.cells ?? {})) {
    const hours = typeof v === 'number' ? v : 0;
    if (hours <= 0) continue;
    const [rowKey, colKey] = k.split(':');
    if (!rowKey || !colKey) continue;
    if (!peak || hours > peak.hours) {
      peak = { rowKey, colKey, hours };
    }
  }

  const baseSummaryLines = [
    `Scope: ${scoped}. Dimensions: ${rowDimension} × ${columnDimension}.`,
    grand !== null ? `Grand total hours: ${grand}.` : 'Grand total hours: —.',
    peak
      ? `Peak cell: ${rowLabelByKey.get(peak.rowKey) ?? peak.rowKey} × ${
          colLabelByKey.get(peak.colKey) ?? peak.colKey
        } = ${peak.hours}h.`
      : 'Peak cell: —.',
  ];

  if (q.includes('grand') || q.includes('total')) {
    return baseSummaryLines.join('\n');
  }

  if (q.includes('top') || q.includes('highest') || q.includes('most')) {
    const rowsLabel = rowDimension === 'user' ? 'Users' : 'Projects';
    const colsLabel = columnDimension === 'user' ? 'Users' : 'Projects';
    const lines = [...baseSummaryLines];

    if (topRowTotals.length > 0) {
      lines.push(
        `${rowsLabel} by total: ${topRowTotals
          .map((r) => `${r.label} (${r.hours}h)`)
          .join(', ')}.`
      );
    }
    if (topColTotals.length > 0) {
      lines.push(
        `${colsLabel} by total: ${topColTotals
          .map((c) => `${c.label} (${c.hours}h)`)
          .join(', ')}.`
      );
    }

    return lines.join('\n');
  }

  // Default: concise deterministic summary.
  return baseSummaryLines.join('\n');
};

const todayAsYmd = (): string => {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

const firstDayOfMonthAsYmd = (): string => {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  return `${y}-${m}-01`;
};

const getFilenameFromContentDisposition = (headerValue: string | undefined): string | null => {
  if (!headerValue) return null;

  // Common forms:
  // - attachment; filename="file.csv"
  // - attachment; filename=file.csv
  // - attachment; filename*=UTF-8''file.csv
  const filenameStarMatch = headerValue.match(/filename\*=(?:UTF-8''|utf-8''|)([^;]+)/);
  if (filenameStarMatch?.[1]) {
    const raw = filenameStarMatch[1].trim().replace(/^"|"$/g, '');
    try {
      return decodeURIComponent(raw);
    } catch {
      return raw;
    }
  }

  const filenameMatch = headerValue.match(/filename=([^;]+)/);
  if (filenameMatch?.[1]) {
    return filenameMatch[1].trim().replace(/^"|"$/g, '');
  }

  return null;
};

const TimesheetPivotReport: React.FC = () => {
  const { billingSummary, tenantAiEnabled, openCheckoutForAddon } = useBilling();
  const aiState = getTenantAiState(billingSummary, tenantAiEnabled);

  const [filtersExpanded, setFiltersExpanded] = useState(true);

  const baselineFilters = useMemo(
    () => ({
      period: 'week' as Period,
      from: firstDayOfMonthAsYmd(),
      to: todayAsYmd(),
      rowDimension: 'user' as Dimension,
      columnDimension: 'project' as Dimension,
    }),
    []
  );

  const [period, setPeriod] = useState<Period>(baselineFilters.period);
  const [from, setFrom] = useState<string>(baselineFilters.from);
  const [to, setTo] = useState<string>(baselineFilters.to);
  const [rowDimension, setRowDimension] = useState<Dimension>(baselineFilters.rowDimension);
  const [columnDimension, setColumnDimension] = useState<Dimension>(baselineFilters.columnDimension);

  const [data, setData] = useState<PivotResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [exporting, setExporting] = useState<'csv' | 'xlsx' | null>(null);
  const [error, setError] = useState<string | null>(null);

  const payload: PivotRequestPayload = useMemo(
    () => ({
      period,
      range: { from, to },
      dimensions: {
        rows: [rowDimension],
        columns: [columnDimension],
      },
      metrics: ['hours'],
      include: {
        row_totals: true,
        column_totals: true,
        grand_total: true,
      },
      filters: {
        project_id: null,
        status: null,
      },
    }),
    [period, from, to, rowDimension, columnDimension]
  );

  const canQuery = from.length === 10 && to.length === 10 && rowDimension !== columnDimension;

  useEffect(() => {
    let mounted = true;

    const run = async () => {
      if (!canQuery) {
        setData(null);
        return;
      }

      setLoading(true);
      setError(null);

      try {
        const response = await api.post<PivotResponseApi>('/api/reports/timesheets/pivot', payload);
        if (!mounted) return;
        setData(normalizePivotResponse(response.data));
      } catch (e: any) {
        if (!mounted) return;
        const message =
          typeof e?.response?.data?.message === 'string'
            ? e.response.data.message
            : typeof e?.message === 'string'
              ? e.message
              : 'Failed to load pivot report';
        setError(message);
        setData(null);
      } finally {
        if (mounted) setLoading(false);
      }
    };

    void run();

    return () => {
      mounted = false;
    };
  }, [canQuery, payload]);

  const handleExport = async (format: 'csv' | 'xlsx') => {
    if (!canQuery) return;

    setExporting(format);
    setError(null);

    try {
      const response = await api.post('/api/reports/timesheets/pivot/export', { format, ...payload }, {
        responseType: 'blob',
      });

      const contentDisposition = response.headers?.['content-disposition'] as string | undefined;
      const filename =
        getFilenameFromContentDisposition(contentDisposition) || `timesheets_pivot_export.${format}`;

      const blob = response.data as Blob;
      const url = window.URL.createObjectURL(blob);
      const a = document.createElement('a');
      a.href = url;
      a.download = filename;
      document.body.appendChild(a);
      a.click();
      a.remove();
      window.URL.revokeObjectURL(url);
    } catch (e: any) {
      const message =
        typeof e?.response?.data?.message === 'string'
          ? e.response.data.message
          : typeof e?.message === 'string'
            ? e.message
            : 'Failed to export pivot report';
      setError(message);
    } finally {
      setExporting(null);
    }
  };

  const showRowTotals = data?.totals?.rows && payload.include.row_totals;
  const showColumnTotals = data?.totals?.columns && payload.include.column_totals;
  const showGrandTotal = typeof data?.totals?.grand === 'number' && payload.include.grand_total;

  const rowHeaderLabel = rowDimension === 'user' ? 'User' : 'Project';

  const deterministicInsights = useMemo(() => {
    const scoped = data?.meta?.scoped ? String(data.meta.scoped) : '—';
    const grand = typeof data?.totals?.grand === 'number' ? data.totals.grand : null;
    const dims = `${rowDimension} × ${columnDimension}`;

    return {
      scoped,
      grand,
      dims,
    };
  }, [data, rowDimension, columnDimension]);

  const activeFiltersCount = useMemo(() => {
    let count = 0;
    if (period !== baselineFilters.period) count++;
    if (from !== baselineFilters.from) count++;
    if (to !== baselineFilters.to) count++;
    if (rowDimension !== baselineFilters.rowDimension) count++;
    if (columnDimension !== baselineFilters.columnDimension) count++;
    return count;
  }, [baselineFilters, period, from, to, rowDimension, columnDimension]);

  const clearAllFilters = () => {
    setPeriod(baselineFilters.period);
    setFrom(baselineFilters.from);
    setTo(baselineFilters.to);
    setRowDimension(baselineFilters.rowDimension);
    setColumnDimension(baselineFilters.columnDimension);
  };

  const handleAskAi = async (question: string): Promise<string> => {
    return answerPivotQuestionDeterministically(question, data, rowDimension, columnDimension);
  };

  const fromPickerValue = useMemo(() => {
    const parsed = dayjs(from);
    return parsed.isValid() ? parsed : null;
  }, [from]);

  const toPickerValue = useMemo(() => {
    const parsed = dayjs(to);
    return parsed.isValid() ? parsed : null;
  }, [to]);

  const aiInsightsNode = useMemo(() => {
    return (
      <Typography variant="body2" color="text.secondary">
        Try: “grand total”, “top users/projects”, or “highest cell”.
      </Typography>
    );
  }, []);

  return (
    <Box sx={{ p: 3 }}>
      <Stack spacing={2}>
        <PageHeader
          title="Timesheets Analysis"
          subtitle="Hours by user × project"
        />

        <ReportFiltersCard
          expanded={filtersExpanded}
          onToggleExpanded={() => setFiltersExpanded(!filtersExpanded)}
          activeFiltersCount={activeFiltersCount}
          onClearAll={clearAllFilters}
          resultsLabel={data ? `${data.rows.length} rows` : undefined}
        >
          <Grid container spacing={1} alignItems="center">
            <Grid item xs={12} md={2}>
              <FormControl fullWidth size="small">
                <InputLabel id="pivot-period-label">Period</InputLabel>
                <Select
                  labelId="pivot-period-label"
                  label="Period"
                  value={period}
                  onChange={(e) => setPeriod(e.target.value as Period)}
                >
                  <MenuItem value="day">day</MenuItem>
                  <MenuItem value="week">week</MenuItem>
                  <MenuItem value="month">month</MenuItem>
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={3}>
              <DatePicker
                label="From"
                value={fromPickerValue}
                onChange={(val) => val && setFrom(val.format('YYYY-MM-DD'))}
                slotProps={{ textField: { size: 'small', fullWidth: true } }}
              />
            </Grid>

            <Grid item xs={12} md={3}>
              <DatePicker
                label="To"
                value={toPickerValue}
                onChange={(val) => val && setTo(val.format('YYYY-MM-DD'))}
                slotProps={{ textField: { size: 'small', fullWidth: true } }}
              />
            </Grid>

            <Grid item xs={12} md={2}>
              <FormControl fullWidth size="small">
                <InputLabel id="pivot-rows-label">Rows</InputLabel>
                <Select
                  labelId="pivot-rows-label"
                  label="Rows"
                  value={rowDimension}
                  onChange={(e) => {
                    const next = e.target.value as Dimension;
                    setRowDimension(next);
                    if (next === columnDimension) {
                      setColumnDimension(next === 'user' ? 'project' : 'user');
                    }
                  }}
                >
                  <MenuItem value="user">user</MenuItem>
                  <MenuItem value="project">project</MenuItem>
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={2}>
              <FormControl fullWidth size="small">
                <InputLabel id="pivot-columns-label">Columns</InputLabel>
                <Select
                  labelId="pivot-columns-label"
                  label="Columns"
                  value={columnDimension}
                  onChange={(e) => {
                    const next = e.target.value as Dimension;
                    setColumnDimension(next);
                    if (next === rowDimension) {
                      setRowDimension(next === 'user' ? 'project' : 'user');
                    }
                  }}
                >
                  <MenuItem value="user">user</MenuItem>
                  <MenuItem value="project">project</MenuItem>
                </Select>
              </FormControl>
            </Grid>
          </Grid>
        </ReportFiltersCard>

        <Card sx={{ mb: 1, background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)' }}>
          <CardContent sx={{ py: 1.25 }}>
            <Grid container spacing={2} alignItems="center">
              <Grid item xs={12} md={4}>
                <Stack spacing={0.3}>
                  <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                    SCOPE
                  </Typography>
                  <Typography variant="h6" fontWeight={700} color="white">
                    {deterministicInsights.scoped}
                  </Typography>
                  <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.7)', fontSize: '0.7rem' }}>
                    {deterministicInsights.dims}
                  </Typography>
                </Stack>
              </Grid>

              <Grid item xs={12} md={8}>
                <Grid container spacing={2}>
                  <Grid item xs={6} sm={4}>
                    <Stack spacing={0.3}>
                      <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                        PERIOD
                      </Typography>
                      <Typography variant="h6" fontWeight={600} color="white">
                        {period}
                      </Typography>
                    </Stack>
                  </Grid>
                  <Grid item xs={6} sm={4}>
                    <Stack spacing={0.3}>
                      <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                        RANGE
                      </Typography>
                      <Typography variant="h6" fontWeight={600} color="white">
                        {from} → {to}
                      </Typography>
                    </Stack>
                  </Grid>
                  <Grid item xs={12} sm={4}>
                    <Stack spacing={0.3}>
                      <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                        GRAND TOTAL
                      </Typography>
                      <Typography variant="h6" fontWeight={600} color="white">
                        {deterministicInsights.grand !== null ? `${deterministicInsights.grand}h` : '—'}
                      </Typography>
                    </Stack>
                  </Grid>
                </Grid>
              </Grid>
            </Grid>
          </CardContent>
        </Card>

        <Box sx={{ display: 'flex', justifyContent: 'flex-end', gap: 1 }}>
          <Button
            variant="outlined"
            size="small"
            sx={{ textTransform: 'none' }}
            disabled={!canQuery || exporting !== null}
            onClick={() => void handleExport('csv')}
          >
            {exporting === 'csv' ? 'Exporting…' : 'Export CSV'}
          </Button>
          <Button
            variant="outlined"
            size="small"
            sx={{ textTransform: 'none' }}
            disabled={!canQuery || exporting !== null}
            onClick={() => void handleExport('xlsx')}
          >
            {exporting === 'xlsx' ? 'Exporting…' : 'Export XLSX'}
          </Button>
        </Box>

        {!canQuery && (
          <Alert severity="info">Select a valid date range and different row/column dimensions.</Alert>
        )}

        {error && <Alert severity="error">{error}</Alert>}

        {loading && (
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
            <CircularProgress size={24} />
            <Typography variant="body2" color="text.secondary">
              Loading…
            </Typography>
          </Box>
        )}

        {!loading && data && data.rows.length === 0 && (
          <Alert severity="info">No data for the selected range.</Alert>
        )}

        {!loading && data && data.rows.length > 0 && (
          <>
            <Typography variant="body2" color="text.secondary">
              Scoped: {String(data.meta?.scoped ?? '—')}
            </Typography>

            <TableContainer>
              <Table size="small">
                <TableHead>
                  <TableRow>
                    <TableCell sx={{ fontWeight: 600 }}>{rowHeaderLabel}</TableCell>
                    {data.columns.map((c) => (
                      <TableCell key={c.key} align="right" sx={{ fontWeight: 600 }}>
                        {c.label}
                      </TableCell>
                    ))}
                    {showRowTotals && (
                      <TableCell align="right" sx={{ fontWeight: 700 }}>
                        Total
                      </TableCell>
                    )}
                  </TableRow>
                </TableHead>

                <TableBody>
                  {data.rows.map((r) => (
                    <TableRow key={r.key}>
                      <TableCell>{r.label}</TableCell>
                      {data.columns.map((c) => {
                        const cellKey = `${r.key}:${c.key}`;
                        const value = typeof data.cells?.[cellKey] === 'number' ? data.cells[cellKey] : 0;
                        return (
                          <TableCell key={c.key} align="right">
                            {value}
                          </TableCell>
                        );
                      })}
                      {showRowTotals && (
                        <TableCell align="right" sx={{ fontWeight: 700 }}>
                          {typeof data.totals?.rows?.[r.key] === 'number' ? data.totals.rows[r.key] : 0}
                        </TableCell>
                      )}
                    </TableRow>
                  ))}

                  {(showColumnTotals || showGrandTotal) && (
                    <TableRow>
                      <TableCell sx={{ fontWeight: 700 }}>Total</TableCell>
                      {data.columns.map((c) => (
                        <TableCell key={c.key} align="right" sx={{ fontWeight: 700 }}>
                          {typeof data.totals?.columns?.[c.key] === 'number' ? data.totals.columns[c.key] : 0}
                        </TableCell>
                      ))}
                      {showRowTotals && (
                        <TableCell align="right" sx={{ fontWeight: 800 }}>
                          {showGrandTotal ? data.totals?.grand : ''}
                        </TableCell>
                      )}
                    </TableRow>
                  )}
                </TableBody>
              </Table>
            </TableContainer>
          </>
        )}
      </Stack>

      <ReportAISideTab
        aiState={aiState}
        title="AI"
        insights={aiInsightsNode}
        onUpgrade={() => void openCheckoutForAddon('ai')}
        onOpenSettings={() => {
          window.location.href = '/billing';
        }}
        onAsk={handleAskAi}
      />
    </Box>
  );
};

export default TimesheetPivotReport;
