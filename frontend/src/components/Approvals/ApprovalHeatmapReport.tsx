import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Card,
  CardContent,
  Checkbox,
  CircularProgress,
  FormControlLabel,
  Grid,
  Stack,
  Tooltip,
  Typography,
  alpha,
  useTheme,
} from '@mui/material';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs from 'dayjs';
import api from '../../services/api';
import PageHeader from '../Common/PageHeader';
import { useBilling } from '../../contexts/BillingContext';
import { getTenantAiState } from '../Common/aiState';
import ReportFiltersCard from '../Common/ReportFiltersCard';
import ReportAISideTab from '../Common/ReportAISideTab';
import { useAuth } from '../Auth/AuthContext';
import { formatTenantDate, formatTenantDayMonth, getTenantDatePickerFormat, getTenantUiLocale } from '../../utils/tenantFormatting';
import { weekStartToFirstDay } from '../../utils/weekStartToFirstDay';

type HeatmapRequestPayload = {
  range: {
    from: string;
    to: string;
  };
  include: {
    timesheets: boolean;
    expenses: boolean;
  };
};

type HeatmapEntityCounts = {
  pending: number;
  approved: number;
};

type HeatmapDay = {
  timesheets?: HeatmapEntityCounts;
  expenses?: HeatmapEntityCounts;
  total_pending: number;
};

type HeatmapResponse = {
  meta: {
    from: string;
    to: string;
    scoped: string;
    [key: string]: unknown;
  };
  days: Record<string, HeatmapDay>;
};

const todayAsYmd = (): string => {
  const d = new Date();
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

const daysAgoAsYmd = (daysAgo: number): string => {
  const d = new Date();
  d.setDate(d.getDate() - daysAgo);
  const y = d.getFullYear();
  const m = String(d.getMonth() + 1).padStart(2, '0');
  const day = String(d.getDate()).padStart(2, '0');
  return `${y}-${m}-${day}`;
};

const ymdToDate = (ymd: string): Date | null => {
  if (!/^\d{4}-\d{2}-\d{2}$/.test(ymd)) return null;
  const [y, m, d] = ymd.split('-').map((p) => Number(p));
  const date = new Date(y, m - 1, d);
  if (Number.isNaN(date.getTime())) return null;
  // Basic sanity: ensure it round-trips.
  if (date.getFullYear() !== y || date.getMonth() !== m - 1 || date.getDate() !== d) return null;
  return date;
};

const formatYmd = (date: Date): string => {
  const y = date.getFullYear();
  const m = String(date.getMonth() + 1).padStart(2, '0');
  const d = String(date.getDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
};

const diffDaysInclusive = (from: Date, to: Date): number => {
  const fromUtc = Date.UTC(from.getFullYear(), from.getMonth(), from.getDate());
  const toUtc = Date.UTC(to.getFullYear(), to.getMonth(), to.getDate());
  const diff = Math.floor((toUtc - fromUtc) / (24 * 60 * 60 * 1000));
  return diff + 1;
};

const startOfWeek = (date: Date, firstDay: 0 | 1): Date => {
  const d = new Date(date);
  const day = d.getDay(); // 0=Sun, 1=Mon
  const delta = firstDay === 1 ? (day + 6) % 7 : day; // days since week start
  d.setDate(d.getDate() - delta);
  d.setHours(0, 0, 0, 0);
  return d;
};

const ApprovalHeatmapReport: React.FC = () => {
  const theme = useTheme();
  const { tenantContext } = useAuth();
  const rawWeekStart: unknown =
    (tenantContext as any)?.week_start ??
    (tenantContext as any)?.tenant?.week_start ??
    (tenantContext as any)?.tenant?.settings?.week_start ??
    (tenantContext as any)?.weekStart ??
    null;

  const firstDay = useMemo((): 0 | 1 => {
    // Normalize to the existing helper's accepted values.
    const raw = rawWeekStart;
    if (typeof raw === 'number') {
      if (raw === 0 || raw === 7) return 0;
      if (raw === 1) return 1;
    }

    const s = (raw ?? '').toString().trim().toLowerCase();
    if (s === '0' || s === '7' || s === 'sun' || s === 'sunday') return 0;
    if (s === '1' || s === 'mon' || s === 'monday') return 1;

    return weekStartToFirstDay(typeof raw === 'string' ? raw : null);
  }, [rawWeekStart]);
  const datePickerFormat = getTenantDatePickerFormat(tenantContext);

  const { billingSummary, tenantAiEnabled, openCheckoutForAddon } = useBilling();
  const aiState = getTenantAiState(billingSummary, tenantAiEnabled);
  const [filtersExpanded, setFiltersExpanded] = useState(true);

  const baselineFilters = useMemo(
    () => ({
      from: daysAgoAsYmd(30),
      to: todayAsYmd(),
      includeTimesheets: true,
      includeExpenses: true,
    }),
    []
  );

  const [from, setFrom] = useState<string>(baselineFilters.from);
  const [to, setTo] = useState<string>(baselineFilters.to);
  const [includeTimesheets, setIncludeTimesheets] = useState(baselineFilters.includeTimesheets);
  const [includeExpenses, setIncludeExpenses] = useState(baselineFilters.includeExpenses);

  const [data, setData] = useState<HeatmapResponse | null>(null);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const payload: HeatmapRequestPayload = useMemo(
    () => ({
      range: { from, to },
      include: { timesheets: includeTimesheets, expenses: includeExpenses },
    }),
    [from, to, includeTimesheets, includeExpenses]
  );

  const fromDate = useMemo(() => ymdToDate(from), [from]);
  const toDate = useMemo(() => ymdToDate(to), [to]);

  const canQuery = useMemo(() => {
    if (!fromDate || !toDate) return false;
    if (fromDate.getTime() > toDate.getTime()) return false;
    if (!includeTimesheets && !includeExpenses) return false;
    const days = diffDaysInclusive(fromDate, toDate);
    return days >= 1 && days <= 62;
  }, [fromDate, toDate, includeTimesheets, includeExpenses]);

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
        const response = await api.post<HeatmapResponse>('/api/reports/approvals/heatmap', payload);
        if (!mounted) return;
        setData(response.data);
      } catch (e: any) {
        if (!mounted) return;
        const message =
          typeof e?.response?.data?.message === 'string'
            ? e.response.data.message
            : typeof e?.message === 'string'
              ? e.message
              : 'Failed to load approval heatmap';
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

  const allDates = useMemo(() => {
    if (!fromDate || !toDate) return [] as string[];
    const res: string[] = [];
    const d = new Date(fromDate);
    d.setHours(0, 0, 0, 0);
    while (d.getTime() <= toDate.getTime()) {
      res.push(formatYmd(d));
      d.setDate(d.getDate() + 1);
    }
    return res;
  }, [fromDate, toDate]);

  const calendarStart = useMemo(() => {
    if (!fromDate) return null;
    return startOfWeek(fromDate, firstDay);
  }, [fromDate, firstDay]);

  const calendarCells = useMemo(() => {
    if (!calendarStart || !toDate) return [] as string[];
    const end = new Date(toDate);
    end.setHours(0, 0, 0, 0);

    // End the grid at the end of the last displayed week.
    // Monday-start -> end on Sunday. Sunday-start -> end on Saturday.
    const endDay = end.getDay();
    const targetEndDay = firstDay === 1 ? 0 : 6;
    const addDays = (targetEndDay - endDay + 7) % 7;
    end.setDate(end.getDate() + addDays);

    const res: string[] = [];
    const d = new Date(calendarStart);
    while (d.getTime() <= end.getTime()) {
      res.push(formatYmd(d));
      d.setDate(d.getDate() + 1);
    }
    return res;
  }, [calendarStart, toDate, firstDay]);

  const weekdayLabels = useMemo(() => {
    const locale = getTenantUiLocale(tenantContext);
    // Known Sunday: 2020-08-02, Monday: 2020-08-03
    const base = firstDay === 0 ? new Date(Date.UTC(2020, 7, 2)) : new Date(Date.UTC(2020, 7, 3));
    return Array.from({ length: 7 }, (_v, i) => {
      const d = new Date(base);
      d.setUTCDate(d.getUTCDate() + i);
      return new Intl.DateTimeFormat(locale, { weekday: 'short', timeZone: 'UTC' }).format(d);
    });
  }, [firstDay, tenantContext?.locale, tenantContext?.ui_locale, tenantContext?.region]);

  const totals = useMemo(() => {
    const days = data?.days ?? {};
    let totalPending = 0;
    let totalApprovedTimesheets = 0;
    let totalApprovedExpenses = 0;

    for (const v of Object.values(days)) {
      totalPending += typeof v.total_pending === 'number' ? v.total_pending : 0;
      totalApprovedTimesheets += typeof v.timesheets?.approved === 'number' ? v.timesheets.approved : 0;
      totalApprovedExpenses += typeof v.expenses?.approved === 'number' ? v.expenses.approved : 0;
    }

    return {
      scoped: data?.meta?.scoped ? String(data.meta.scoped) : '—',
      totalPending,
      totalApprovedTimesheets,
      totalApprovedExpenses,
    };
  }, [data]);

  const activeFiltersCount = useMemo(() => {
    let count = 0;
    if (from !== baselineFilters.from) count++;
    if (to !== baselineFilters.to) count++;
    if (includeTimesheets !== baselineFilters.includeTimesheets) count++;
    if (includeExpenses !== baselineFilters.includeExpenses) count++;
    return count;
  }, [baselineFilters, from, to, includeTimesheets, includeExpenses]);

  const clearAllFilters = () => {
    setFrom(baselineFilters.from);
    setTo(baselineFilters.to);
    setIncludeTimesheets(baselineFilters.includeTimesheets);
    setIncludeExpenses(baselineFilters.includeExpenses);
  };

  const fromPickerValue = useMemo(() => {
    const parsed = dayjs(from);
    return parsed.isValid() ? parsed : null;
  }, [from]);

  const toPickerValue = useMemo(() => {
    const parsed = dayjs(to);
    return parsed.isValid() ? parsed : null;
  }, [to]);

  const handleAskAi = async (question: string): Promise<string> => {
    const response = await api.post<{ answer: string }>(
      '/api/ai/approvals/query',
      {
        question,
        context: {
          range: { from, to },
          types: [
            ...(includeTimesheets ? (['timesheets'] as const) : []),
            ...(includeExpenses ? (['expenses'] as const) : []),
          ],
        },
      }
    );

    return String(response.data?.answer ?? '');
  };

  const aiInsightsNode = useMemo(() => {
    return (
      <Typography variant="body2" color="text.secondary">
        Try: “Where are approvals piling up?”, “Which days were worst?”, or “Compare timesheets vs expenses”.
      </Typography>
    );
  }, []);

  return (
    <Box sx={{ p: 3 }}>
      <Stack spacing={2}>
        <PageHeader
          title="Approval Heatmap"
          subtitle="Pending approvals by day"
        />

        <ReportFiltersCard
          expanded={filtersExpanded}
          onToggleExpanded={() => setFiltersExpanded(!filtersExpanded)}
          activeFiltersCount={activeFiltersCount}
          onClearAll={clearAllFilters}
        >
          <Grid container spacing={1} alignItems="center">
            <Grid item xs={12} md={3}>
              <DatePicker
                label="From"
                value={fromPickerValue}
                onChange={(val) => val && setFrom(val.format('YYYY-MM-DD'))}
                format={datePickerFormat}
                slotProps={{ textField: { size: 'small', fullWidth: true } }}
              />
            </Grid>

            <Grid item xs={12} md={3}>
              <DatePicker
                label="To"
                value={toPickerValue}
                onChange={(val) => val && setTo(val.format('YYYY-MM-DD'))}
                format={datePickerFormat}
                slotProps={{ textField: { size: 'small', fullWidth: true } }}
              />
            </Grid>

            <Grid item xs={12} md={3}>
              <FormControlLabel
                control={
                  <Checkbox
                    checked={includeTimesheets}
                    onChange={(e) => setIncludeTimesheets(e.target.checked)}
                    size="small"
                  />
                }
                label="Timesheets"
                sx={{ m: 0 }}
              />
            </Grid>

            <Grid item xs={12} md={3}>
              <FormControlLabel
                control={
                  <Checkbox
                    checked={includeExpenses}
                    onChange={(e) => setIncludeExpenses(e.target.checked)}
                    size="small"
                  />
                }
                label="Expenses"
                sx={{ m: 0 }}
              />
            </Grid>
          </Grid>
        </ReportFiltersCard>

        <Card sx={{ mb: 1, background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)' }}>
          <CardContent sx={{ py: 1.25 }}>
            <Grid container spacing={2} alignItems="center">
              <Grid item xs={12} md={3}>
                <Stack spacing={0.3}>
                  <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                    SCOPE
                  </Typography>
                  <Typography variant="h6" fontWeight={700} color="white">
                    {totals.scoped}
                  </Typography>
                  <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.7)', fontSize: '0.7rem' }}>
                    {formatTenantDate(from, tenantContext)} → {formatTenantDate(to, tenantContext)}
                  </Typography>
                </Stack>
              </Grid>

              <Grid item xs={12} md={9}>
                <Grid container spacing={2}>
                  <Grid item xs={6} sm={4}>
                    <Stack spacing={0.3}>
                      <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                        Pending
                      </Typography>
                      <Typography variant="h6" fontWeight={600} color="white">
                        {totals.totalPending}
                      </Typography>
                    </Stack>
                  </Grid>
                  <Grid item xs={6} sm={4}>
                    <Stack spacing={0.3}>
                      <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                        Approved (timesheets)
                      </Typography>
                      <Typography variant="h6" fontWeight={600} color="white">
                        {totals.totalApprovedTimesheets}
                      </Typography>
                    </Stack>
                  </Grid>
                  <Grid item xs={6} sm={4}>
                    <Stack spacing={0.3}>
                      <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                        Approved (expenses)
                      </Typography>
                      <Typography variant="h6" fontWeight={600} color="white">
                        {totals.totalApprovedExpenses}
                      </Typography>
                    </Stack>
                  </Grid>
                </Grid>
              </Grid>
            </Grid>
          </CardContent>
        </Card>

        {!canQuery && (
          <Alert severity="info">
            Select a valid range (max 62 days) and include at least one type.
          </Alert>
        )}

        {error && <Alert severity="error">{error}</Alert>}

        {loading && (
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <CircularProgress size={20} />
            <Typography variant="body2" color="text.secondary">
              Loading heatmap…
            </Typography>
          </Box>
        )}

        {data && canQuery && (
          <Box>
            <Box
              sx={{
                display: 'grid',
                gridTemplateColumns: 'repeat(7, minmax(0, 1fr))',
                gap: 1,
                maxWidth: 980,
              }}
            >
              {weekdayLabels.map((dow) => (
                <Typography
                  key={dow}
                  variant="caption"
                  sx={{
                    color: 'text.secondary',
                    textAlign: 'center',
                    fontWeight: 700,
                    letterSpacing: 0.3,
                  }}
                >
                  {dow}
                </Typography>
              ))}

              {calendarCells.map((ymd) => {
                const inRange = allDates.includes(ymd);
                const day = data.days?.[ymd];
                const pending = inRange && day ? day.total_pending : 0;

                const bg = (() => {
                  if (!inRange) return alpha(theme.palette.common.white, 0.02);

                  // Spec thresholds:
                  // 0 -> grey
                  // 1-2 -> yellow
                  // 3-5 -> orange
                  // 6+ -> red
                  if (pending <= 0) return theme.palette.action.hover;
                  if (pending <= 2) return alpha(theme.palette.warning.light, 0.35);
                  if (pending <= 5) return alpha(theme.palette.warning.main, 0.35);
                  return alpha(theme.palette.error.main, 0.35);
                })();

                const dayNumber = Number(ymd.slice(8, 10)).toString();

                const tooltip = (() => {
                  if (!inRange) return null;

                  const dateObj = ymdToDate(ymd);
                  const header = dateObj ? formatTenantDayMonth(ymd, tenantContext) : ymd;

                  const tsPending = day?.timesheets?.pending ?? 0;
                  const exPending = day?.expenses?.pending ?? 0;

                  const lines: string[] = [header];
                  if (includeTimesheets) {
                    lines.push(`Timesheets: ${tsPending} pending`);
                  }
                  if (includeExpenses) {
                    lines.push(`Expenses: ${exPending} pending`);
                  }
                  return lines.join('\n');
                })();

                const cell = (
                  <Box
                    sx={{
                      borderRadius: 1.5,
                      bgcolor: bg,
                      minHeight: 60,
                      px: 1,
                      py: 0.75,
                      display: 'flex',
                      flexDirection: 'column',
                      justifyContent: 'space-between',
                      border: `1px solid ${alpha(theme.palette.common.white, 0.06)}`,
                      opacity: inRange ? 1 : 0.45,
                    }}
                  >
                    <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                      {dayNumber}
                    </Typography>
                    {inRange && pending > 0 && (
                      <Typography variant="body2" sx={{ fontWeight: 700, textAlign: 'right' }}>
                        {pending}
                      </Typography>
                    )}
                  </Box>
                );

                if (!tooltip) return <Box key={ymd}>{cell}</Box>;

                return (
                  <Tooltip
                    key={ymd}
                    title={<span style={{ whiteSpace: 'pre-line' }}>{tooltip}</span>}
                    placement="top"
                    arrow
                  >
                    <Box>{cell}</Box>
                  </Tooltip>
                );
              })}
            </Box>

            <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 2 }}>
              Showing {formatTenantDate(String(data.meta.from ?? ''), tenantContext)} → {formatTenantDate(String(data.meta.to ?? ''), tenantContext)} (scoped: {data.meta.scoped})
            </Typography>
          </Box>
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

export default ApprovalHeatmapReport;
