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
import { useTranslation } from 'react-i18next';
import type { TFunction } from 'i18next';
import { useAuth } from '../Auth/AuthContext';
import { formatTenantDate, formatTenantNumber, getTenantDatePickerFormat } from '../../utils/tenantFormatting';

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
  // Backend can return cells as a record keyed by "row:col" (or "row|col")
  // OR as an array of {row_id, column_id, hours}. We normalize to Record<string, number>.
  cells:
    | Record<string, number | string>
    | Array<{ row_id: string | number; column_id: string | number; hours: number | string }>
    | null;
  totals?: {
    rows?: Array<{ row_id: string | number; hours: number | string }>;
    columns?: Array<{ column_id: string | number; hours: number | string }>;
    grand?: { hours: number | string } | null;
  };
};

type PivotInsightItem = { label: string; value: number };

type PivotInsights = {
  totalHours: number | null;
  topUser: PivotInsightItem | null;
  topProject: PivotInsightItem | null;
  highestCell: PivotInsightItem | null;
};

const parseHoursValue = (value: unknown): number => {
  if (typeof value === 'number') return Number.isFinite(value) ? value : 0;
  if (typeof value !== 'string') return 0;

  const raw = value.trim();
  if (raw === '') return 0;

  // Only support comma-decimal normalization if the API returns strings.
  // Examples: "12,50" -> 12.5, "12.50" -> 12.5
  const normalized = raw.includes(',') && !raw.includes('.') ? raw.replace(',', '.') : raw;
  const num = Number(normalized);
  return Number.isFinite(num) ? num : 0;
};

const splitPivotCellKey = (rawKey: string): { rowKey: string; colKey: string } | null => {
  const key = rawKey.trim();
  if (key === '') return null;

  const colon = key.lastIndexOf(':');
  const pipe = key.lastIndexOf('|');
  const sepIndex = Math.max(colon, pipe);
  if (sepIndex <= 0 || sepIndex >= key.length - 1) return null;

  const rowKey = key.slice(0, sepIndex).trim();
  const colKey = key.slice(sepIndex + 1).trim();
  if (rowKey === '' || colKey === '') return null;
  return { rowKey, colKey };
};

const normalizePivotResponse = (apiData: PivotResponseApi): PivotResponse => {
  const rows = (apiData.rows ?? []).map((r) => ({ key: String(r.id).trim(), label: String(r.label) }));
  const columns = (apiData.columns ?? []).map((c) => ({ key: String(c.id).trim(), label: String(c.label) }));

  const cells: Record<string, number> = {};
  const rawCells = apiData.cells;
  if (Array.isArray(rawCells)) {
    for (const cell of rawCells) {
      const rowKey = String((cell as any)?.row_id ?? '').trim();
      const colKey = String((cell as any)?.column_id ?? '').trim();
      if (!rowKey || !colKey) continue;
      const canonicalCellKey = `${rowKey}:${colKey}`;
      cells[canonicalCellKey] = parseHoursValue((cell as any)?.hours);
    }
  } else if (rawCells && typeof rawCells === 'object') {
    for (const [k, v] of Object.entries(rawCells as Record<string, unknown>)) {
      const parts = splitPivotCellKey(k);
      if (!parts) continue;
      const canonicalCellKey = `${parts.rowKey}:${parts.colKey}`;
      cells[canonicalCellKey] = parseHoursValue(v);
    }
  }

  const rowTotals: Record<string, number> = {};
  const colTotals: Record<string, number> = {};

  for (const r of apiData.totals?.rows ?? []) {
    rowTotals[String(r.row_id).trim()] = parseHoursValue(r.hours);
  }
  for (const c of apiData.totals?.columns ?? []) {
    colTotals[String(c.column_id).trim()] = parseHoursValue(c.hours);
  }

  return {
    meta: apiData.meta,
    rows,
    columns,
    cells,
    totals: {
      rows: rowTotals,
      columns: colTotals,
      grand:
        apiData.totals?.grand && apiData.totals.grand.hours !== null && apiData.totals.grand.hours !== undefined
          ? parseHoursValue(apiData.totals.grand.hours)
          : undefined,
    },
  };
};

const answerPivotQuestionDeterministically = (
  question: string,
  data: PivotResponse | null,
  rowDimension: Dimension,
  columnDimension: Dimension,
  t: TFunction
): string => {
  const q = question.trim().toLowerCase();

  if (!data) {
    return t('timesheetPivot.ai.loadFirst');
  }

  const rowDimensionLabel = rowDimension === 'user'
    ? t('timesheetPivot.dimensions.user')
    : t('timesheetPivot.dimensions.project');
  const columnDimensionLabel = columnDimension === 'user'
    ? t('timesheetPivot.dimensions.user')
    : t('timesheetPivot.dimensions.project');

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
    const parts = splitPivotCellKey(k);
    if (!parts) continue;
    if (!peak || hours > peak.hours) {
      peak = { rowKey: parts.rowKey, colKey: parts.colKey, hours };
    }
  }

  const baseSummaryLines = [
    t('timesheetPivot.ai.scopeLine', {
      scoped,
      row: rowDimensionLabel,
      column: columnDimensionLabel,
    }),
    grand !== null
      ? t('timesheetPivot.ai.grandTotal', { hours: grand })
      : t('timesheetPivot.ai.grandTotalEmpty'),
    peak
      ? t('timesheetPivot.ai.peakCell', {
          row: rowLabelByKey.get(peak.rowKey) ?? peak.rowKey,
          column: colLabelByKey.get(peak.colKey) ?? peak.colKey,
          hours: peak.hours,
        })
      : t('timesheetPivot.ai.peakCellEmpty'),
  ];

  if (q.includes('grand') || q.includes('total')) {
    return baseSummaryLines.join('\n');
  }

  if (q.includes('top') || q.includes('highest') || q.includes('most')) {
    const rowsLabel = rowDimension === 'user'
      ? t('timesheetPivot.dimensions.users')
      : t('timesheetPivot.dimensions.projects');
    const colsLabel = columnDimension === 'user'
      ? t('timesheetPivot.dimensions.users')
      : t('timesheetPivot.dimensions.projects');
    const lines = [...baseSummaryLines];

    if (topRowTotals.length > 0) {
      lines.push(
        t('timesheetPivot.ai.totalsLine', {
          label: rowsLabel,
          items: topRowTotals
            .map((r) => `${r.label} (${t('common.hoursShort', { value: r.hours })})`)
            .join(', '),
        })
      );
    }
    if (topColTotals.length > 0) {
      lines.push(
        t('timesheetPivot.ai.totalsLine', {
          label: colsLabel,
          items: topColTotals
            .map((c) => `${c.label} (${t('common.hoursShort', { value: c.hours })})`)
            .join(', '),
        })
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
  const { t } = useTranslation();
  const { tenantContext } = useAuth();
  const datePickerFormat = getTenantDatePickerFormat(tenantContext);
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
              : t('timesheetPivot.errors.loadFailed');
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
  }, [canQuery, payload, t]);

  useEffect(() => {
    if (!import.meta.env.DEV) return;
    if (!data) return;
    const grand = typeof data.totals?.grand === 'number' ? data.totals.grand : 0;
    if (!(grand > 0)) return;

    const sampleRowKey = data.rows?.[0]?.key ?? '';
    const sampleColKey = data.columns?.[0]?.key ?? '';
    const sampleCanonicalKey = sampleRowKey && sampleColKey ? `${sampleRowKey}:${sampleColKey}` : '';

    let anyNonZero = false;
    for (const r of data.rows ?? []) {
      for (const c of data.columns ?? []) {
        const canonicalCellKey = `${r.key}:${c.key}`;
        const value = typeof data.cells?.[canonicalCellKey] === 'number' ? data.cells[canonicalCellKey] : 0;
        if (value !== 0) {
          anyNonZero = true;
          break;
        }
      }
      if (anyNonZero) break;
    }

    if (!anyNonZero) {
      const firstKeys = Object.keys(data.cells ?? {}).slice(0, 5);
      console.warn('[TimesheetPivotReport] grand>0 but all displayed cells are 0', {
        sampleRowKey,
        sampleColKey,
        sampleCanonicalKey,
        firstCellKeys: firstKeys,
      });
    }
  }, [data]);

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
            : t('timesheetPivot.errors.exportFailed');
      setError(message);
    } finally {
      setExporting(null);
    }
  };

  const showRowTotals = data?.totals?.rows && payload.include.row_totals;
  const showColumnTotals = data?.totals?.columns && payload.include.column_totals;
  const showGrandTotal = typeof data?.totals?.grand === 'number' && payload.include.grand_total;

  const rowDimensionLabel = rowDimension === 'user'
    ? t('timesheetPivot.dimensions.user')
    : t('timesheetPivot.dimensions.project');
  const columnDimensionLabel = columnDimension === 'user'
    ? t('timesheetPivot.dimensions.user')
    : t('timesheetPivot.dimensions.project');
  const rowHeaderLabel = rowDimensionLabel;

  const deterministicInsights = useMemo(() => {
    const scoped = data?.meta?.scoped ? String(data.meta.scoped) : '—';
    const grand = typeof data?.totals?.grand === 'number' ? data.totals.grand : null;
    const dims = t('timesheetPivot.dimsLabel', {
      row: rowDimensionLabel,
      column: columnDimensionLabel,
    });

    return {
      scoped,
      grand,
      dims,
    };
  }, [data, rowDimensionLabel, columnDimensionLabel, t]);

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
    return answerPivotQuestionDeterministically(question, data, rowDimension, columnDimension, t);
  };

  const fromPickerValue = useMemo(() => {
    const parsed = dayjs(from);
    return parsed.isValid() ? parsed : null;
  }, [from]);

  const toPickerValue = useMemo(() => {
    const parsed = dayjs(to);
    return parsed.isValid() ? parsed : null;
  }, [to]);

  const pivotInsights = useMemo<PivotInsights>(() => {
    const rowLabels = new Map(data?.rows?.map((r) => [r.key, r.label]) ?? []);
    const columnLabels = new Map(data?.columns?.map((c) => [c.key, c.label]) ?? []);

    const getTop = (totals: Record<string, number> | undefined, labels: Map<string, string>): PivotInsightItem | null => {
      if (!totals) return null;
      let bestKey: string | null = null;
      let bestValue = -Infinity;
      Object.entries(totals).forEach(([key, value]) => {
        if (typeof value === 'number' && value > bestValue) {
          bestValue = value;
          bestKey = key;
        }
      });
      if (!bestKey || !Number.isFinite(bestValue)) return null;
      return { label: labels.get(bestKey) ?? bestKey, value: bestValue };
    };

    const topRow = getTop(data?.totals?.rows, rowLabels);
    const topColumn = getTop(data?.totals?.columns, columnLabels);

    const topUser = rowDimension === 'user' ? topRow : columnDimension === 'user' ? topColumn : null;
    const topProject = rowDimension === 'project' ? topRow : columnDimension === 'project' ? topColumn : null;

    let highestCell: { label: string; value: number } | null = null;
    if (data?.cells) {
      Object.entries(data.cells).forEach(([key, value]) => {
        if (typeof value !== 'number') return;
        if (!highestCell || value > highestCell.value) {
          const [rowKey, columnKey] = key.split(':');
          const rowLabel = rowLabels.get(rowKey) ?? rowKey;
          const colLabel = columnLabels.get(columnKey) ?? columnKey;
          highestCell = { label: `${rowLabel} × ${colLabel}`, value };
        }
      });
    }

    return {
      totalHours: typeof data?.totals?.grand === 'number' ? data.totals.grand : null,
      topUser,
      topProject,
      highestCell,
    };
  }, [data, rowDimension, columnDimension]);

  const insightSuggestions = useMemo(
    () => [
      t('timesheetPivot.insights.suggestions.topUsers'),
      t('timesheetPivot.insights.suggestions.topProjects'),
      t('timesheetPivot.insights.suggestions.peakCell'),
    ],
    [t]
  );

  const aiInsightsNode = useMemo(() => {
    const emptyValue = t('rightPanel.insights.emptyValue');
    const formatHours = (value: number | null) =>
      value !== null
        ? t('common.hoursShort', { value: formatTenantNumber(value, tenantContext, 2) })
        : emptyValue;

    const metrics = [
      {
        key: 'total',
        label: t('timesheetPivot.insights.totalHours'),
        value: formatHours(pivotInsights.totalHours),
      },
      {
        key: 'top-user',
        label: t('timesheetPivot.insights.topUser'),
        value: pivotInsights.topUser
          ? `${pivotInsights.topUser.label} (${formatHours(pivotInsights.topUser.value)})`
          : emptyValue,
      },
      {
        key: 'top-project',
        label: t('timesheetPivot.insights.topProject'),
        value: pivotInsights.topProject
          ? `${pivotInsights.topProject.label} (${formatHours(pivotInsights.topProject.value)})`
          : emptyValue,
      },
      {
        key: 'peak-cell',
        label: t('timesheetPivot.insights.peakCell'),
        value: pivotInsights.highestCell
          ? `${pivotInsights.highestCell.label} (${formatHours(pivotInsights.highestCell.value)})`
          : emptyValue,
      },
    ];

    return (
      <Stack spacing={2}>
        <Box>
          <Typography variant="subtitle2">{t('timesheetPivot.insights.summaryTitle')}</Typography>
          <Typography variant="body2" color="text.secondary">
            {t('timesheetPivot.insights.summaryHint')}
          </Typography>
        </Box>
        <Grid container spacing={1.5}>
          {metrics.map((metric) => (
            <Grid key={metric.key} item xs={12} sm={6}>
              <Card variant="outlined" sx={{ height: '100%' }}>
                <CardContent sx={{ p: 1.5 }}>
                  <Typography variant="caption" color="text.secondary">
                    {metric.label}
                  </Typography>
                  <Typography variant="subtitle1" fontWeight={600}>
                    {metric.value}
                  </Typography>
                </CardContent>
              </Card>
            </Grid>
          ))}
        </Grid>
      </Stack>
    );
  }, [pivotInsights, tenantContext, t]);

  return (
    <Box sx={{ p: 3 }}>
      <Stack spacing={2}>
        <PageHeader
          title={t('timesheetPivot.title')}
          subtitle={t('timesheetPivot.subtitle')}
        />

        <ReportFiltersCard
          expanded={filtersExpanded}
          onToggleExpanded={() => setFiltersExpanded(!filtersExpanded)}
          activeFiltersCount={activeFiltersCount}
          onClearAll={clearAllFilters}
          resultsLabel={data ? t('timesheetPivot.resultsLabel', { count: data.rows.length }) : undefined}
        >
          <Grid container spacing={1} alignItems="center">
            <Grid item xs={12} md={2}>
              <FormControl fullWidth size="small">
                <InputLabel id="pivot-period-label">{t('timesheetPivot.filters.period')}</InputLabel>
                <Select
                  labelId="pivot-period-label"
                  label={t('timesheetPivot.filters.period')}
                  value={period}
                  onChange={(e) => setPeriod(e.target.value as Period)}
                >
                  <MenuItem value="day">{t('timesheetPivot.periods.day')}</MenuItem>
                  <MenuItem value="week">{t('timesheetPivot.periods.week')}</MenuItem>
                  <MenuItem value="month">{t('timesheetPivot.periods.month')}</MenuItem>
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={3}>
              <DatePicker
                label={t('timesheetPivot.filters.from')}
                value={fromPickerValue}
                onChange={(val) => val && setFrom(val.format('YYYY-MM-DD'))}
                format={datePickerFormat}
                slotProps={{ textField: { size: 'small', fullWidth: true } }}
              />
            </Grid>

            <Grid item xs={12} md={3}>
              <DatePicker
                label={t('timesheetPivot.filters.to')}
                value={toPickerValue}
                onChange={(val) => val && setTo(val.format('YYYY-MM-DD'))}
                format={datePickerFormat}
                slotProps={{ textField: { size: 'small', fullWidth: true } }}
              />
            </Grid>

            <Grid item xs={12} md={2}>
              <FormControl fullWidth size="small">
                <InputLabel id="pivot-rows-label">{t('timesheetPivot.filters.rows')}</InputLabel>
                <Select
                  labelId="pivot-rows-label"
                  label={t('timesheetPivot.filters.rows')}
                  value={rowDimension}
                  onChange={(e) => {
                    const next = e.target.value as Dimension;
                    setRowDimension(next);
                    if (next === columnDimension) {
                      setColumnDimension(next === 'user' ? 'project' : 'user');
                    }
                  }}
                >
                  <MenuItem value="user">{t('timesheetPivot.dimensions.user')}</MenuItem>
                  <MenuItem value="project">{t('timesheetPivot.dimensions.project')}</MenuItem>
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={2}>
              <FormControl fullWidth size="small">
                <InputLabel id="pivot-columns-label">{t('timesheetPivot.filters.columns')}</InputLabel>
                <Select
                  labelId="pivot-columns-label"
                  label={t('timesheetPivot.filters.columns')}
                  value={columnDimension}
                  onChange={(e) => {
                    const next = e.target.value as Dimension;
                    setColumnDimension(next);
                    if (next === rowDimension) {
                      setRowDimension(next === 'user' ? 'project' : 'user');
                    }
                  }}
                >
                  <MenuItem value="user">{t('timesheetPivot.dimensions.user')}</MenuItem>
                  <MenuItem value="project">{t('timesheetPivot.dimensions.project')}</MenuItem>
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
                    {t('timesheetPivot.labels.scope')}
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
                        {t('timesheetPivot.labels.period')}
                      </Typography>
                      <Typography variant="h6" fontWeight={600} color="white">
                        {t(`timesheetPivot.periods.${period}`)}
                      </Typography>
                    </Stack>
                  </Grid>
                  <Grid item xs={6} sm={4}>
                    <Stack spacing={0.3}>
                      <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                        {t('timesheetPivot.labels.range')}
                      </Typography>
                      <Typography variant="h6" fontWeight={600} color="white">
                        {formatTenantDate(from, tenantContext)} → {formatTenantDate(to, tenantContext)}
                      </Typography>
                    </Stack>
                  </Grid>
                  <Grid item xs={12} sm={4}>
                    <Stack spacing={0.3}>
                      <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                        {t('timesheetPivot.labels.grandTotal')}
                      </Typography>
                      <Typography variant="h6" fontWeight={600} color="white">
                        {deterministicInsights.grand !== null
                          ? t('common.hoursShort', {
                              value: formatTenantNumber(deterministicInsights.grand, tenantContext, 2),
                            })
                          : t('rightPanel.insights.emptyValue')}
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
            {exporting === 'csv' ? t('timesheetPivot.export.exporting') : t('timesheetPivot.export.csv')}
          </Button>
          <Button
            variant="outlined"
            size="small"
            sx={{ textTransform: 'none' }}
            disabled={!canQuery || exporting !== null}
            onClick={() => void handleExport('xlsx')}
          >
            {exporting === 'xlsx' ? t('timesheetPivot.export.exporting') : t('timesheetPivot.export.xlsx')}
          </Button>
        </Box>

        {!canQuery && (
          <Alert severity="info">{t('timesheetPivot.validation.invalidFilters')}</Alert>
        )}

        {error && <Alert severity="error">{error}</Alert>}

        {loading && (
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
            <CircularProgress size={24} />
            <Typography variant="body2" color="text.secondary">
              {t('timesheetPivot.loading')}
            </Typography>
          </Box>
        )}

        {!loading && data && data.rows.length === 0 && (
          <Alert severity="info">{t('timesheetPivot.noData')}</Alert>
        )}

        {!loading && data && data.rows.length > 0 && (
          <>
            <Typography variant="body2" color="text.secondary">
              {t('timesheetPivot.labels.scoped')}: {String(data.meta?.scoped ?? '—')}
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
                        {t('timesheetPivot.labels.total')}
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
                            {formatTenantNumber(value, tenantContext, 2)}
                          </TableCell>
                        );
                      })}
                      {showRowTotals && (
                        <TableCell align="right" sx={{ fontWeight: 700 }}>
                          {formatTenantNumber(
                            typeof data.totals?.rows?.[r.key] === 'number' ? data.totals.rows[r.key] : 0,
                            tenantContext,
                            2
                          )}
                        </TableCell>
                      )}
                    </TableRow>
                  ))}

                  {(showColumnTotals || showGrandTotal) && (
                    <TableRow>
                      <TableCell sx={{ fontWeight: 700 }}>{t('timesheetPivot.labels.total')}</TableCell>
                      {data.columns.map((c) => (
                        <TableCell key={c.key} align="right" sx={{ fontWeight: 700 }}>
                          {formatTenantNumber(
                            typeof data.totals?.columns?.[c.key] === 'number' ? data.totals.columns[c.key] : 0,
                            tenantContext,
                            2
                          )}
                        </TableCell>
                      ))}
                      {showRowTotals && (
                        <TableCell align="right" sx={{ fontWeight: 800 }}>
                          {showGrandTotal
                            ? formatTenantNumber(typeof data.totals?.grand === 'number' ? data.totals.grand : 0, tenantContext, 2)
                            : ''}
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
        title={t('rightPanel.tabs.ai')}
        insights={aiInsightsNode}
        insightSuggestions={insightSuggestions}
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
