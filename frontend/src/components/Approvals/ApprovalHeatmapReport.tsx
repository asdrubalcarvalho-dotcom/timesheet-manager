import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Checkbox,
  CircularProgress,
  FormControlLabel,
  Stack,
  TextField,
  Tooltip,
  Typography,
  alpha,
  useTheme,
} from '@mui/material';
import api from '../../services/api';

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

const startOfWeekMonday = (date: Date): Date => {
  const d = new Date(date);
  const day = d.getDay(); // 0=Sun, 1=Mon
  const delta = (day + 6) % 7; // days since Monday
  d.setDate(d.getDate() - delta);
  d.setHours(0, 0, 0, 0);
  return d;
};

const formatTooltipDay = (date: Date): string =>
  new Intl.DateTimeFormat('en-GB', { day: '2-digit', month: 'short' }).format(date);

const ApprovalHeatmapReport: React.FC = () => {
  const theme = useTheme();

  const [from, setFrom] = useState<string>(daysAgoAsYmd(30));
  const [to, setTo] = useState<string>(todayAsYmd());
  const [includeTimesheets, setIncludeTimesheets] = useState(true);
  const [includeExpenses, setIncludeExpenses] = useState(true);

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
    return startOfWeekMonday(fromDate);
  }, [fromDate]);

  const calendarCells = useMemo(() => {
    if (!calendarStart || !toDate) return [] as string[];
    const end = new Date(toDate);
    end.setHours(0, 0, 0, 0);

    // End on Sunday to close the last week
    const endDay = end.getDay(); // 0=Sun
    const addDays = (7 - ((endDay + 6) % 7) - 1 + 7) % 7; // days until Sunday when Monday is start
    end.setDate(end.getDate() + addDays);

    const res: string[] = [];
    const d = new Date(calendarStart);
    while (d.getTime() <= end.getTime()) {
      res.push(formatYmd(d));
      d.setDate(d.getDate() + 1);
    }
    return res;
  }, [calendarStart, toDate]);

  return (
    <Box sx={{ p: 3 }}>
      <Stack spacing={2}>
        <Typography variant="h5">Approval Heatmap</Typography>

        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2} alignItems={{ md: 'center' }}>
          <TextField
            size="small"
            label="From"
            type="date"
            value={from}
            onChange={(e) => setFrom(e.target.value)}
            InputLabelProps={{ shrink: true }}
            sx={{ minWidth: 160 }}
          />

          <TextField
            size="small"
            label="To"
            type="date"
            value={to}
            onChange={(e) => setTo(e.target.value)}
            InputLabelProps={{ shrink: true }}
            sx={{ minWidth: 160 }}
          />

          <FormControlLabel
            control={
              <Checkbox
                checked={includeTimesheets}
                onChange={(e) => setIncludeTimesheets(e.target.checked)}
                size="small"
              />
            }
            label="Timesheets"
          />

          <FormControlLabel
            control={
              <Checkbox
                checked={includeExpenses}
                onChange={(e) => setIncludeExpenses(e.target.checked)}
                size="small"
              />
            }
            label="Expenses"
          />
        </Stack>

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
              {['Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'].map((dow) => (
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

                const dateObj = ymdToDate(ymd);
                const dayNumber = dateObj ? dateObj.getDate() : '';

                const tooltip = (() => {
                  if (!inRange) return null;

                  const dateObj = ymdToDate(ymd);
                  const header = dateObj ? formatTooltipDay(dateObj) : ymd;

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
              Showing {data.meta.from} → {data.meta.to} (scoped: {data.meta.scoped})
            </Typography>
          </Box>
        )}
      </Stack>
    </Box>
  );
};

export default ApprovalHeatmapReport;
