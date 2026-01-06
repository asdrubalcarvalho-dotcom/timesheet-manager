import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  CircularProgress,
  FormControl,
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
  TextField,
  Typography,
} from '@mui/material';
import api from '../../services/api';

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
  const [period, setPeriod] = useState<Period>('week');
  const [from, setFrom] = useState<string>(firstDayOfMonthAsYmd());
  const [to, setTo] = useState<string>(todayAsYmd());
  const [rowDimension, setRowDimension] = useState<Dimension>('user');
  const [columnDimension, setColumnDimension] = useState<Dimension>('project');

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
        const response = await api.post<PivotResponse>('/api/reports/timesheets/pivot', payload);
        if (!mounted) return;
        setData(response.data);
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

  return (
    <Box sx={{ p: 3 }}>
      <Stack spacing={2}>
        <Typography variant="h5">Timesheet Pivot Report</Typography>

        <Stack direction={{ xs: 'column', md: 'row' }} spacing={2}>
          <FormControl size="small" sx={{ minWidth: 160 }}>
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

          <FormControl size="small" sx={{ minWidth: 180 }}>
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

          <FormControl size="small" sx={{ minWidth: 180 }}>
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

          <Stack direction="row" spacing={1} sx={{ alignItems: 'center' }}>
            <Button
              variant="outlined"
              disabled={!canQuery || exporting !== null}
              onClick={() => void handleExport('csv')}
            >
              {exporting === 'csv' ? 'Exporting…' : 'Export CSV'}
            </Button>
            <Button
              variant="outlined"
              disabled={!canQuery || exporting !== null}
              onClick={() => void handleExport('xlsx')}
            >
              {exporting === 'xlsx' ? 'Exporting…' : 'Export XLSX'}
            </Button>
          </Stack>
        </Stack>

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
    </Box>
  );
};

export default TimesheetPivotReport;
