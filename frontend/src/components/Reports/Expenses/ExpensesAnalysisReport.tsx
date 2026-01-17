import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Card,
  CardContent,
  CircularProgress,
  FormControl,
  Grid,
  InputLabel,
  MenuItem,
  Select,
  Stack,
  ToggleButton,
  ToggleButtonGroup,
  Typography,
  useTheme,
} from '@mui/material';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs, { Dayjs } from 'dayjs';
import {
  Area,
  Bar,
  CartesianGrid,
  ComposedChart,
  Legend,
  ResponsiveContainer,
  Tooltip,
  XAxis,
  YAxis,
} from 'recharts';
import PageHeader from '../../Common/PageHeader';
import ReportFiltersCard from '../../Common/ReportFiltersCard';
import ReportAISideTab from '../../Common/ReportAISideTab';
import { useAuth } from '../../Auth/AuthContext';
import { useBilling } from '../../../contexts/BillingContext';
import { getTenantAiState } from '../../Common/aiState';
import { API_URL, fetchWithAuth } from '../../../services/api';
import { formatTenantMoney } from '../../../utils/tenantFormatting';

type ExpenseEntry = {
  id?: number;
  project_id: number;
  date: string;
  expense_date?: string;
  created_at?: string;
  updated_at?: string;
  amount: number | string;
  category: string;
  status:
    | 'draft'
    | 'submitted'
    | 'approved'
    | 'rejected'
    | 'finance_review'
    | 'finance_approved'
    | 'paid'
    | string;
  project?: {
    id: number;
    name: string;
  };
};

type TrendGranularity = 'day' | 'week' | 'month';

type TrendPoint = {
  key: string;
  label: string;
  spend: number;
  count: number;
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

const isValidYmd = (s: string): boolean => /^\d{4}-\d{2}-\d{2}$/.test(s);

const toDayjsOrNull = (ymd: string): Dayjs | null => {
  if (!isValidYmd(ymd)) return null;
  const d = dayjs(ymd);
  return d.isValid() ? d : null;
};

const safeAmount = (value: number | string): number => {
  const n = typeof value === 'number' ? value : Number(String(value).replace(',', '.'));
  return Number.isFinite(n) ? n : 0;
};

const extractExpenseDateForTrends = (exp: ExpenseEntry): Dayjs | null => {
  const raw =
    (typeof exp.expense_date === 'string' && exp.expense_date) ||
    (typeof exp.created_at === 'string' && exp.created_at) ||
    (typeof exp.date === 'string' && exp.date) ||
    (typeof exp.updated_at === 'string' && exp.updated_at) ||
    '';

  const parsed = dayjs(raw);
  return parsed.isValid() ? parsed : null;
};

const startOfWeekMonday = (d: Dayjs): Dayjs => {
  // dayjs().day(): 0=Sun..6=Sat
  const day = d.day();
  const delta = (day + 6) % 7; // days since Monday
  return d.subtract(delta, 'day').startOf('day');
};

const coefficientOfVariation = (values: number[]): number | null => {
  const finite = values.filter((v) => Number.isFinite(v));
  if (finite.length === 0) return null;

  const mean = finite.reduce((acc, v) => acc + v, 0) / finite.length;
  if (mean === 0) return null;

  const variance = finite.reduce((acc, v) => acc + (v - mean) * (v - mean), 0) / finite.length;
  const std = Math.sqrt(variance);
  return std / mean;
};

const quantile = (sortedAsc: number[], q: number): number | null => {
  const arr = sortedAsc.filter((v) => Number.isFinite(v));
  if (arr.length === 0) return null;

  const clamped = Math.min(1, Math.max(0, q));
  const pos = (arr.length - 1) * clamped;
  const base = Math.floor(pos);
  const rest = pos - base;
  const next = arr[base + 1];
  const cur = arr[base];
  if (!Number.isFinite(cur)) return null;
  if (Number.isFinite(next)) return cur + rest * (next - cur);
  return cur;
};

const ExpensesAnalysisReport: React.FC = () => {
  const theme = useTheme();
  const { tenantContext } = useAuth();
  const { billingSummary, tenantAiEnabled, openCheckoutForAddon } = useBilling();
  const aiState = getTenantAiState(billingSummary, tenantAiEnabled);

  const formatMoney = useMemo(() => {
    return (amount: number) => formatTenantMoney(amount, tenantContext);
  }, [tenantContext]);

  const baselineFilters = useMemo(
    () => ({
      from: firstDayOfMonthAsYmd(),
      to: todayAsYmd(),
      status: 'all' as string,
      category: 'all' as string,
      projectId: 'all' as 'all' | number,
    }),
    []
  );

  const [filtersExpanded, setFiltersExpanded] = useState(true);

  const [from, setFrom] = useState(baselineFilters.from);
  const [to, setTo] = useState(baselineFilters.to);
  const [status, setStatus] = useState<string>(baselineFilters.status);
  const [category, setCategory] = useState<string>(baselineFilters.category);
  const [projectId, setProjectId] = useState<'all' | number>(baselineFilters.projectId);

  const [trendGranularity, setTrendGranularity] = useState<TrendGranularity>('week');

  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [expenses, setExpenses] = useState<ExpenseEntry[]>([]);

  useEffect(() => {
    let mounted = true;

    const run = async () => {
      setLoading(true);
      setError(null);

      try {
        const response = await fetchWithAuth(`${API_URL}/api/expenses?report=1`);
        if (!mounted) return;

        if (!response.ok) {
          const errorData = await response.json().catch(() => null);
          const message =
            (typeof errorData?.message === 'string' && errorData.message) ||
            (typeof errorData?.error === 'string' && errorData.error) ||
            `Failed to load expenses: ${response.status}`;
          throw new Error(message);
        }

        const data = await response.json();
        const items: ExpenseEntry[] = Array.isArray(data) ? data : Array.isArray(data?.data) ? data.data : [];
        setExpenses(items);
      } catch (e: any) {
        if (!mounted) return;
        setExpenses([]);
        setError(typeof e?.message === 'string' ? e.message : 'Failed to load expenses');
      } finally {
        if (mounted) setLoading(false);
      }
    };

    void run();

    return () => {
      mounted = false;
    };
  }, []);

  const activeFiltersCount = useMemo(() => {
    let count = 0;
    if (from !== baselineFilters.from) count += 1;
    if (to !== baselineFilters.to) count += 1;
    if (status !== baselineFilters.status) count += 1;
    if (category !== baselineFilters.category) count += 1;
    if (projectId !== baselineFilters.projectId) count += 1;
    return count;
  }, [baselineFilters, category, from, projectId, status, to]);

  const clearAll = () => {
    setFrom(baselineFilters.from);
    setTo(baselineFilters.to);
    setStatus(baselineFilters.status);
    setCategory(baselineFilters.category);
    setProjectId(baselineFilters.projectId);
  };

  const fromDate = useMemo(() => {
    const d = toDayjsOrNull(from);
    return d ? d.toDate() : null;
  }, [from]);

  const toDate = useMemo(() => {
    const d = toDayjsOrNull(to);
    return d ? d.toDate() : null;
  }, [to]);

  const filteredExpenses = useMemo(() => {
    if (!fromDate || !toDate) return [] as ExpenseEntry[];

    const fromYmd = dayjs(fromDate).format('YYYY-MM-DD');
    const toYmd = dayjs(toDate).format('YYYY-MM-DD');

    return expenses.filter((exp) => {
      const ymd = typeof exp.date === 'string' ? exp.date.slice(0, 10) : '';
      if (!isValidYmd(ymd)) return false;
      if (ymd < fromYmd || ymd > toYmd) return false;

      if (status !== 'all' && String(exp.status) !== status) return false;
      if (category !== 'all' && String(exp.category) !== category) return false;
      if (projectId !== 'all' && exp.project_id !== projectId) return false;

      return true;
    });
  }, [category, expenses, fromDate, projectId, status, toDate]);

  const previousRange = useMemo(() => {
    if (!fromDate || !toDate) return null;

    const fromD = dayjs(fromDate);
    const toD = dayjs(toDate);
    if (!fromD.isValid() || !toD.isValid()) return null;

    const diffDays = toD.startOf('day').diff(fromD.startOf('day'), 'day');
    if (!Number.isFinite(diffDays) || diffDays < 0) return null;

    const prevTo = fromD.subtract(1, 'day');
    const prevFrom = prevTo.subtract(diffDays, 'day');

    return {
      from: prevFrom.format('YYYY-MM-DD'),
      to: prevTo.format('YYYY-MM-DD'),
    };
  }, [fromDate, toDate]);

  const previousFilteredExpenses = useMemo(() => {
    if (!previousRange) return [] as ExpenseEntry[];

    const fromYmd = previousRange.from;
    const toYmd = previousRange.to;

    return expenses.filter((exp) => {
      const ymd = typeof exp.date === 'string' ? exp.date.slice(0, 10) : '';
      if (!isValidYmd(ymd)) return false;
      if (ymd < fromYmd || ymd > toYmd) return false;

      if (status !== 'all' && String(exp.status) !== status) return false;
      if (category !== 'all' && String(exp.category) !== category) return false;
      if (projectId !== 'all' && exp.project_id !== projectId) return false;

      return true;
    });
  }, [category, expenses, previousRange, projectId, status]);

  const projects = useMemo(() => {
    const byId = new Map<number, string>();
    for (const exp of expenses) {
      if (typeof exp.project_id !== 'number') continue;
      const name = exp.project?.name ? String(exp.project.name) : `Project #${exp.project_id}`;
      if (!byId.has(exp.project_id)) byId.set(exp.project_id, name);
    }
    return Array.from(byId.entries())
      .map(([id, name]) => ({ id, name }))
      .sort((a, b) => a.name.localeCompare(b.name));
  }, [expenses]);

  const categories = useMemo(() => {
    const set = new Set<string>();
    for (const exp of expenses) {
      if (typeof exp.category === 'string' && exp.category.trim()) set.add(exp.category);
    }
    return Array.from(set.values()).sort((a, b) => a.localeCompare(b));
  }, [expenses]);

  const statusOptions = useMemo(() => {
    const set = new Set<string>();
    for (const exp of expenses) {
      if (typeof exp.status === 'string' && exp.status.trim()) set.add(exp.status);
    }
    return Array.from(set.values()).sort((a, b) => a.localeCompare(b));
  }, [expenses]);

  const kpis = useMemo(() => {
    const totalValue = filteredExpenses.reduce((acc, exp) => acc + safeAmount(exp.amount), 0);
    const counts = {
      pendingReview: filteredExpenses.filter((e) => e.status === 'submitted').length,
      financeReview: filteredExpenses.filter((e) => e.status === 'finance_review').length,
      approved: filteredExpenses.filter((e) => e.status === 'finance_approved').length,
      paid: filteredExpenses.filter((e) => e.status === 'paid').length,
    };

    return {
      totalValue,
      count: filteredExpenses.length,
      ...counts,
    };
  }, [filteredExpenses]);

  const trends = useMemo(() => {
    const pointsMap = new Map<string, TrendPoint>();
    let unparseableDates = 0;

    for (const exp of filteredExpenses) {
      const d = extractExpenseDateForTrends(exp);
      if (!d) {
        unparseableDates += 1;
        continue;
      }

      let key: string;
      let label: string;
      if (trendGranularity === 'day') {
        key = d.format('YYYY-MM-DD');
        label = d.format('YYYY-MM-DD');
      } else if (trendGranularity === 'month') {
        key = d.format('YYYY-MM');
        label = d.format('YYYY-MM');
      } else {
        const start = startOfWeekMonday(d);
        key = start.format('YYYY-MM-DD');
        label = start.format('YYYY-MM-DD');
      }

      const existing = pointsMap.get(key);
      if (existing) {
        existing.spend += safeAmount(exp.amount);
        existing.count += 1;
      } else {
        pointsMap.set(key, {
          key,
          label,
          spend: safeAmount(exp.amount),
          count: 1,
        });
      }
    }

    const series = Array.from(pointsMap.values()).sort((a, b) => a.key.localeCompare(b.key));

    const spendValues = series.map((p) => p.spend);
    const cov = coefficientOfVariation(spendValues);

    const peak = series.reduce<TrendPoint | null>((best, p) => (!best || p.spend > best.spend ? p : best), null);
    const lowest = series.reduce<TrendPoint | null>((best, p) => (!best || p.spend < best.spend ? p : best), null);

    const last = series.length >= 1 ? series[series.length - 1] : null;
    const prev = series.length >= 2 ? series[series.length - 2] : null;

    const pctChange =
      last && prev && prev.spend !== 0
        ? ((last.spend - prev.spend) / Math.abs(prev.spend)) * 100
        : last && prev && prev.spend === 0 && last.spend !== 0
          ? null
          : null;

    return {
      series,
      unparseableDates,
      insights: {
        peak,
        lowest,
        pctChange,
        volatility: cov,
      },
    };
  }, [filteredExpenses, trendGranularity]);

  const breakdownByCategory = useMemo(() => {
    const map = new Map<string, { count: number; total: number }>();
    for (const exp of filteredExpenses) {
      const key = typeof exp.category === 'string' && exp.category.trim() ? exp.category : 'Uncategorized';
      const cur = map.get(key) ?? { count: 0, total: 0 };
      cur.count += 1;
      cur.total += safeAmount(exp.amount);
      map.set(key, cur);
    }

    return Array.from(map.entries())
      .map(([key, v]) => ({ key, ...v }))
      .sort((a, b) => b.total - a.total);
  }, [filteredExpenses]);

  const breakdownByProject = useMemo(() => {
    const map = new Map<number, { name: string; count: number; total: number }>();
    for (const exp of filteredExpenses) {
      const id = exp.project_id;
      const name = exp.project?.name ? String(exp.project.name) : `Project #${id}`;
      const cur = map.get(id) ?? { name, count: 0, total: 0 };
      cur.count += 1;
      cur.total += safeAmount(exp.amount);
      map.set(id, cur);
    }

    return Array.from(map.entries())
      .map(([id, v]) => ({ id, ...v }))
      .sort((a, b) => b.total - a.total);
  }, [filteredExpenses]);

  const comparison = useMemo(() => {
    const currentTotal = filteredExpenses.reduce((acc, e) => acc + safeAmount(e.amount), 0);
    const previousTotal = previousFilteredExpenses.reduce((acc, e) => acc + safeAmount(e.amount), 0);

    const currentCount = filteredExpenses.length;
    const previousCount = previousFilteredExpenses.length;

    const deltaTotal = currentTotal - previousTotal;
    const deltaCount = currentCount - previousCount;

    const pctTotal = previousTotal !== 0 ? (deltaTotal / Math.abs(previousTotal)) * 100 : null;
    const pctCount = previousCount !== 0 ? (deltaCount / Math.abs(previousCount)) * 100 : null;

    const prevByCategory = new Map<string, { count: number; total: number }>();
    for (const exp of previousFilteredExpenses) {
      const key = typeof exp.category === 'string' && exp.category.trim() ? exp.category : 'Uncategorized';
      const cur = prevByCategory.get(key) ?? { count: 0, total: 0 };
      cur.count += 1;
      cur.total += safeAmount(exp.amount);
      prevByCategory.set(key, cur);
    }
    const previousTopCategory = Array.from(prevByCategory.entries())
      .map(([key, v]) => ({ key, ...v }))
      .sort((a, b) => b.total - a.total)[0];

    const prevByProject = new Map<number, { name: string; count: number; total: number }>();
    for (const exp of previousFilteredExpenses) {
      const id = exp.project_id;
      const name = exp.project?.name ? String(exp.project.name) : `Project #${id}`;
      const cur = prevByProject.get(id) ?? { name, count: 0, total: 0 };
      cur.count += 1;
      cur.total += safeAmount(exp.amount);
      prevByProject.set(id, cur);
    }
    const previousTopProject = Array.from(prevByProject.entries())
      .map(([id, v]) => ({ id, ...v }))
      .sort((a, b) => b.total - a.total)[0];

    return {
      current: { total: currentTotal, count: currentCount },
      previous: { total: previousTotal, count: previousCount },
      delta: { total: deltaTotal, count: deltaCount },
      pct: { total: pctTotal, count: pctCount },
      previousTopCategory,
      previousTopProject,
    };
  }, [filteredExpenses, previousFilteredExpenses]);

  const outliers = useMemo(() => {
    const amounts = filteredExpenses.map((e) => safeAmount(e.amount)).filter((v) => Number.isFinite(v));
    const sorted = [...amounts].sort((a, b) => a - b);

    const q1 = quantile(sorted, 0.25);
    const q3 = quantile(sorted, 0.75);
    const iqr = q1 !== null && q3 !== null ? q3 - q1 : null;
    const threshold = iqr !== null && iqr > 0 ? q3! + 1.5 * iqr : null;

    const rows =
      threshold === null
        ? []
        : filteredExpenses
            .map((e) => ({
              exp: e,
              amount: safeAmount(e.amount),
            }))
            .filter((x) => x.amount > threshold)
            .sort((a, b) => b.amount - a.amount)
            .slice(0, 10);

    return {
      q1,
      q3,
      iqr,
      threshold,
      rows,
    };
  }, [filteredExpenses]);

  const statusBreakdown = useMemo(() => {
    const map = new Map<string, { count: number; total: number }>();
    for (const exp of filteredExpenses) {
      const s = typeof exp.status === 'string' && exp.status.trim() ? exp.status : 'unknown';
      const cur = map.get(s) ?? { count: 0, total: 0 };
      cur.count += 1;
      cur.total += safeAmount(exp.amount);
      map.set(s, cur);
    }

    const knownOrder = [
      'draft',
      'submitted',
      'finance_review',
      'approved',
      'finance_approved',
      'paid',
      'rejected',
      'closed',
      'unknown',
    ];

    const entries = Array.from(map.entries()).map(([statusKey, v]) => ({ statusKey, ...v }));
    const rank = (k: string) => {
      const idx = knownOrder.indexOf(k);
      return idx === -1 ? 999 : idx;
    };

    return entries.sort((a, b) => {
      const ra = rank(a.statusKey);
      const rb = rank(b.statusKey);
      if (ra !== rb) return ra - rb;
      return b.total - a.total;
    });
  }, [filteredExpenses]);

  const statusFunnel = useMemo(() => {
    const get = (key: string) => statusBreakdown.find((s) => s.statusKey === key);
    const submitted = get('submitted')?.count ?? 0;
    const financeReview = get('finance_review')?.count ?? 0;
    const financeApproved = get('finance_approved')?.count ?? 0;
    const paid = get('paid')?.count ?? 0;

    const rate = (num: number, den: number): number | null => {
      if (!Number.isFinite(num) || !Number.isFinite(den) || den <= 0) return null;
      return (num / den) * 100;
    };

    return {
      submitted,
      financeReview,
      financeApproved,
      paid,
      rates: {
        submittedToPaid: rate(paid, submitted),
        submittedToFinanceReview: rate(financeReview, submitted),
        financeReviewToFinanceApproved: rate(financeApproved, financeReview),
      },
    };
  }, [statusBreakdown]);

  const dataQuality = useMemo(() => {
    let missingCategory = 0;
    let missingProjectName = 0;
    let invalidAmount = 0;
    let unparseableTrendDates = 0;

    for (const exp of filteredExpenses) {
      const cat = typeof exp.category === 'string' ? exp.category.trim() : '';
      if (!cat) missingCategory += 1;

      const name = exp.project?.name ? String(exp.project.name).trim() : '';
      if (!name) missingProjectName += 1;

      const amt = safeAmount(exp.amount);
      if (!Number.isFinite(amt)) invalidAmount += 1;

      const d = extractExpenseDateForTrends(exp);
      if (!d) unparseableTrendDates += 1;
    }

    return {
      total: filteredExpenses.length,
      missingCategory,
      missingProjectName,
      invalidAmount,
      unparseableTrendDates,
    };
  }, [filteredExpenses]);

  const moversByCategory = useMemo(() => {
    if (!previousRange) return null;

    const current = new Map<string, { total: number; count: number }>();
    for (const exp of filteredExpenses) {
      const key = typeof exp.category === 'string' && exp.category.trim() ? exp.category : 'Uncategorized';
      const cur = current.get(key) ?? { total: 0, count: 0 };
      cur.total += safeAmount(exp.amount);
      cur.count += 1;
      current.set(key, cur);
    }

    const previous = new Map<string, { total: number; count: number }>();
    for (const exp of previousFilteredExpenses) {
      const key = typeof exp.category === 'string' && exp.category.trim() ? exp.category : 'Uncategorized';
      const cur = previous.get(key) ?? { total: 0, count: 0 };
      cur.total += safeAmount(exp.amount);
      cur.count += 1;
      previous.set(key, cur);
    }

    const keys = new Set<string>([...current.keys(), ...previous.keys()]);
    const rows = Array.from(keys.values()).map((key) => {
      const c = current.get(key) ?? { total: 0, count: 0 };
      const p = previous.get(key) ?? { total: 0, count: 0 };
      return {
        key,
        currentTotal: c.total,
        previousTotal: p.total,
        deltaTotal: c.total - p.total,
        currentCount: c.count,
        previousCount: p.count,
        deltaCount: c.count - p.count,
      };
    });

    const increases = [...rows].sort((a, b) => b.deltaTotal - a.deltaTotal).slice(0, 5);
    const decreases = [...rows].sort((a, b) => a.deltaTotal - b.deltaTotal).slice(0, 5);

    return { rows, increases, decreases };
  }, [filteredExpenses, previousFilteredExpenses, previousRange]);

  const moversByProject = useMemo(() => {
    if (!previousRange) return null;

    const current = new Map<number, { name: string; total: number; count: number }>();
    for (const exp of filteredExpenses) {
      const id = exp.project_id;
      const name = exp.project?.name ? String(exp.project.name) : `Project #${id}`;
      const cur = current.get(id) ?? { name, total: 0, count: 0 };
      cur.total += safeAmount(exp.amount);
      cur.count += 1;
      current.set(id, cur);
    }

    const previous = new Map<number, { name: string; total: number; count: number }>();
    for (const exp of previousFilteredExpenses) {
      const id = exp.project_id;
      const name = exp.project?.name ? String(exp.project.name) : `Project #${id}`;
      const cur = previous.get(id) ?? { name, total: 0, count: 0 };
      cur.total += safeAmount(exp.amount);
      cur.count += 1;
      previous.set(id, cur);
    }

    const ids = new Set<number>([...current.keys(), ...previous.keys()]);
    const rows = Array.from(ids.values()).map((id) => {
      const c = current.get(id) ?? { name: `Project #${id}`, total: 0, count: 0 };
      const p = previous.get(id) ?? { name: c.name, total: 0, count: 0 };
      return {
        id,
        name: c.name,
        currentTotal: c.total,
        previousTotal: p.total,
        deltaTotal: c.total - p.total,
        currentCount: c.count,
        previousCount: p.count,
        deltaCount: c.count - p.count,
      };
    });

    const increases = [...rows].sort((a, b) => b.deltaTotal - a.deltaTotal).slice(0, 5);
    const decreases = [...rows].sort((a, b) => a.deltaTotal - b.deltaTotal).slice(0, 5);

    return { rows, increases, decreases };
  }, [filteredExpenses, previousFilteredExpenses, previousRange]);

  const spendMix = useMemo(() => {
    const totalSpend = filteredExpenses.reduce((acc, e) => acc + safeAmount(e.amount), 0);

    const byCategory = new Map<string, number>();
    const byStatus = new Map<string, number>();

    for (const exp of filteredExpenses) {
      const amount = safeAmount(exp.amount);

      const cat = typeof exp.category === 'string' && exp.category.trim() ? exp.category : 'Uncategorized';
      byCategory.set(cat, (byCategory.get(cat) ?? 0) + amount);

      const st = typeof exp.status === 'string' && exp.status.trim() ? exp.status : 'unknown';
      byStatus.set(st, (byStatus.get(st) ?? 0) + amount);
    }

    const toRows = (m: Map<string, number>) =>
      Array.from(m.entries())
        .map(([key, total]) => ({
          key,
          total,
          share: totalSpend > 0 ? (total / totalSpend) * 100 : 0,
        }))
        .sort((a, b) => b.total - a.total);

    return {
      totalSpend,
      categories: toRows(byCategory),
      statuses: toRows(byStatus),
    };
  }, [filteredExpenses]);

  const aiInsightsNode = useMemo(() => {
    return (
      <Typography variant="body2" color="text.secondary">
        Try asking about “categories”, “projects”, “outliers”, “what changed”, “status funnel”, “data quality”, “movers”, or “mix”.
      </Typography>
    );
  }, []);

  const handleAskAi = async (question: string): Promise<string> => {
    const q = question.trim().toLowerCase();

    if (q.includes('total') || q.includes('value') || q.includes('amount')) {
      return `Total value is ${formatMoney(kpis.totalValue)} across ${kpis.count} expenses (range ${from} → ${to}).`;
    }

    if (q.includes('status') || q.includes('pending') || q.includes('finance') || q.includes('paid') || q.includes('approved')) {
      return `Statuses (range ${from} → ${to}): Pending Review ${kpis.pendingReview}, Finance Review ${kpis.financeReview}, Approved ${kpis.approved}, Paid ${kpis.paid}.`;
    }

    if (q.includes('category')) {
      const top = breakdownByCategory.slice(0, 5);
      if (top.length === 0) return 'No expenses match the current filters.';
      return [
        `Top categories (range ${from} → ${to}):`,
        ...top.map((c) => `- ${c.key}: ${formatMoney(c.total)} (${c.count})`),
      ].join('\n');
    }

    if (q.includes('project')) {
      const top = breakdownByProject.slice(0, 5);
      if (top.length === 0) return 'No expenses match the current filters.';
      return [
        `Top projects (range ${from} → ${to}):`,
        ...top.map((p) => `- ${p.name}: ${formatMoney(p.total)} (${p.count})`),
      ].join('\n');
    }

    if (q.includes('outlier') || q.includes('anomal') || q.includes('unusual')) {
      if (filteredExpenses.length === 0) return 'No expenses match the current filters.';
      if (outliers.threshold === null) return 'Outliers need more spend variation (IQR unavailable for the current result set).';
      if (outliers.rows.length === 0) return `No outliers detected (threshold > ${formatMoney(outliers.threshold)}).`;
      return [
        `Outliers (threshold > ${formatMoney(outliers.threshold)}):`,
        ...outliers.rows.map(({ exp, amount }) => {
          const name = exp.project?.name ? String(exp.project.name) : `Project #${exp.project_id}`;
          const cat = typeof exp.category === 'string' && exp.category.trim() ? exp.category : 'Uncategorized';
          const ymd = typeof exp.date === 'string' ? exp.date.slice(0, 10) : '';
          return `- ${formatMoney(amount)} · ${name} · ${cat} · ${ymd}`;
        }),
      ].join('\n');
    }

    if (q.includes('what changed') || q.includes('changed') || q.includes('compare') || q.includes('previous')) {
      if (!previousRange) return 'Select a valid date range to compare.';
      const totalLine = `Total: ${formatMoney(comparison.current.total)} vs ${formatMoney(comparison.previous.total)} (${previousRange.from} → ${previousRange.to})`;
      const pct = typeof comparison.pct.total === 'number' ? `${comparison.pct.total >= 0 ? '+' : ''}${comparison.pct.total.toFixed(1)}%` : '—';
      const countPct = typeof comparison.pct.count === 'number' ? `${comparison.pct.count >= 0 ? '+' : ''}${comparison.pct.count.toFixed(1)}%` : '—';
      return [
        `Change vs previous period (${previousRange.from} → ${previousRange.to}):`,
        `- ${totalLine} · Δ ${formatMoney(comparison.delta.total)} · ${pct}`,
        `- Count: ${comparison.current.count} vs ${comparison.previous.count} · Δ ${comparison.delta.count} · ${countPct}`,
      ].join('\n');
    }

    if (q.includes('funnel') || (q.includes('status') && (q.includes('breakdown') || q.includes('distribution')))) {
      if (filteredExpenses.length === 0) return 'No expenses match the current filters.';

      const lines = statusBreakdown.map((s) => `- ${s.statusKey}: ${s.count} · ${formatMoney(s.total)}`);
      const r1 = typeof statusFunnel.rates.submittedToPaid === 'number' ? `${statusFunnel.rates.submittedToPaid.toFixed(1)}%` : '—';
      const r2 =
        typeof statusFunnel.rates.financeReviewToFinanceApproved === 'number'
          ? `${statusFunnel.rates.financeReviewToFinanceApproved.toFixed(1)}%`
          : '—';
      return [
        `Status funnel (range ${from} → ${to}):`,
        ...lines,
        `Key rates: submitted→paid ${r1} · finance_review→finance_approved ${r2}`,
      ].join('\n');
    }

    if (q.includes('data quality') || q.includes('missing') || q.includes('coverage')) {
      if (filteredExpenses.length === 0) return 'No expenses match the current filters.';
      return [
        `Data quality (range ${from} → ${to}):`,
        `- Results: ${dataQuality.total}`,
        `- Missing category: ${dataQuality.missingCategory}`,
        `- Missing project name: ${dataQuality.missingProjectName}`,
        `- Unparseable dates for trends: ${dataQuality.unparseableTrendDates}`,
      ].join('\n');
    }

    if (q.includes('mover') || q.includes('driver') || (q.includes('change') && q.includes('category')) || (q.includes('change') && q.includes('project'))) {
      if (!previousRange) return 'Select a valid date range to compare.';
      const catUp = moversByCategory?.increases?.filter((r) => r.deltaTotal > 0).slice(0, 3) ?? [];
      const catDown = moversByCategory?.decreases?.filter((r) => r.deltaTotal < 0).slice(0, 3) ?? [];
      const projUp = moversByProject?.increases?.filter((r) => r.deltaTotal > 0).slice(0, 3) ?? [];
      const projDown = moversByProject?.decreases?.filter((r) => r.deltaTotal < 0).slice(0, 3) ?? [];

      return [
        `Top movers vs previous period (${previousRange.from} → ${previousRange.to}):`,
        catUp.length ? 'Category increases:' : 'Category increases: —',
        ...catUp.map((r) => `- ${r.key}: +${formatMoney(r.deltaTotal)} (Δ ${r.deltaCount})`),
        catDown.length ? 'Category decreases:' : 'Category decreases: —',
        ...catDown.map((r) => `- ${r.key}: ${formatMoney(r.deltaTotal)} (Δ ${r.deltaCount})`),
        projUp.length ? 'Project increases:' : 'Project increases: —',
        ...projUp.map((r) => `- ${r.name}: +${formatMoney(r.deltaTotal)} (Δ ${r.deltaCount})`),
        projDown.length ? 'Project decreases:' : 'Project decreases: —',
        ...projDown.map((r) => `- ${r.name}: ${formatMoney(r.deltaTotal)} (Δ ${r.deltaCount})`),
      ].join('\n');
    }

    if (q.includes('mix') || q.includes('share') || q.includes('distribution')) {
      if (filteredExpenses.length === 0) return 'No expenses match the current filters.';
      if (spendMix.totalSpend <= 0) return 'Spend mix is not available (total spend is 0 for the current filters).';

      const topCats = spendMix.categories.slice(0, 5);
      const topStatuses = spendMix.statuses.slice(0, 5);

      return [
        `Spend mix (range ${from} → ${to}): total ${formatMoney(spendMix.totalSpend)}`,
        'Top categories:',
        ...topCats.map((r) => `- ${r.key}: ${r.share.toFixed(1)}% (${formatMoney(r.total)})`),
        'Top statuses:',
        ...topStatuses.map((r) => `- ${r.key}: ${r.share.toFixed(1)}% (${formatMoney(r.total)})`),
      ].join('\n');
    }

    return [
      `Expenses analysis (range ${from} → ${to}):`,
      `- Total: ${formatMoney(kpis.totalValue)} (${kpis.count} expenses)`,
      `- Pending Review: ${kpis.pendingReview}`,
      `- Finance Review: ${kpis.financeReview}`,
      `- Approved: ${kpis.approved}`,
      `- Paid: ${kpis.paid}`,
      'Ask about “categories”, “projects”, “outliers”, “what changed”, “status funnel”, “data quality”, “movers”, or “mix”.',
    ].join('\n');
  };

  const canQuery = Boolean(fromDate && toDate && fromDate.getTime() <= toDate.getTime());

  return (
    <Box sx={{ p: 3 }}>
      <Stack spacing={2}>
        <PageHeader title="Expenses Analysis" subtitle="Spend, trends and approvals" />

        <ReportFiltersCard
          expanded={filtersExpanded}
          onToggleExpanded={() => setFiltersExpanded(!filtersExpanded)}
          activeFiltersCount={activeFiltersCount}
          onClearAll={clearAll}
          resultsLabel={loading ? undefined : `${filteredExpenses.length} results`}
        >
          <Grid container spacing={1} alignItems="center">
            <Grid item xs={12} md={3}>
              <DatePicker
                label="From"
                value={toDayjsOrNull(from)}
                onChange={(v) => {
                  if (!v || !v.isValid()) return;
                  setFrom(v.format('YYYY-MM-DD'));
                }}
                slotProps={{ textField: { size: 'small', fullWidth: true } }}
              />
            </Grid>

            <Grid item xs={12} md={3}>
              <DatePicker
                label="To"
                value={toDayjsOrNull(to)}
                onChange={(v) => {
                  if (!v || !v.isValid()) return;
                  setTo(v.format('YYYY-MM-DD'));
                }}
                slotProps={{ textField: { size: 'small', fullWidth: true } }}
              />
            </Grid>

            <Grid item xs={12} md={2}>
              <FormControl fullWidth size="small">
                <InputLabel id="expenses-analysis-status">Status</InputLabel>
                <Select
                  labelId="expenses-analysis-status"
                  label="Status"
                  value={status}
                  onChange={(e) => setStatus(String(e.target.value))}
                >
                  <MenuItem value="all">All</MenuItem>
                  {statusOptions.map((s) => (
                    <MenuItem key={s} value={s}>
                      {s}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={2}>
              <FormControl fullWidth size="small">
                <InputLabel id="expenses-analysis-category">Category</InputLabel>
                <Select
                  labelId="expenses-analysis-category"
                  label="Category"
                  value={category}
                  onChange={(e) => setCategory(String(e.target.value))}
                >
                  <MenuItem value="all">All</MenuItem>
                  {categories.map((c) => (
                    <MenuItem key={c} value={c}>
                      {c}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Grid>

            <Grid item xs={12} md={2}>
              <FormControl fullWidth size="small">
                <InputLabel id="expenses-analysis-project">Project</InputLabel>
                <Select
                  labelId="expenses-analysis-project"
                  label="Project"
                  value={projectId}
                  onChange={(e) => {
                    const v = e.target.value;
                    setProjectId(v === 'all' ? 'all' : Number(v));
                  }}
                >
                  <MenuItem value="all">All</MenuItem>
                  {projects.map((p) => (
                    <MenuItem key={p.id} value={p.id}>
                      {p.name}
                    </MenuItem>
                  ))}
                </Select>
              </FormControl>
            </Grid>
          </Grid>
        </ReportFiltersCard>

        {!canQuery && <Alert severity="info">Select a valid date range.</Alert>}
        {error && <Alert severity="error">{error}</Alert>}

        {loading && (
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <CircularProgress size={20} />
            <Typography variant="body2" color="text.secondary">
              Loading expenses…
            </Typography>
          </Box>
        )}

        {!loading && canQuery && (
          <>
            <Card sx={{ mb: 1, background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)' }}>
              <CardContent sx={{ py: 1.25 }}>
                <Grid container spacing={2} alignItems="center">
                  <Grid item xs={12} md={3}>
                    <Stack spacing={0.3}>
                      <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                        TOTAL VALUE
                      </Typography>
                      <Typography variant="h5" fontWeight={700} color="white">
                        {formatMoney(kpis.totalValue)}
                      </Typography>
                      <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.7)', fontSize: '0.7rem' }}>
                        {kpis.count} expenses
                      </Typography>
                    </Stack>
                  </Grid>

                  <Grid item xs={12} md={9}>
                    <Grid container spacing={2}>
                      <Grid item xs={6} sm={3}>
                        <Stack spacing={0.3}>
                          <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                            Pending Review
                          </Typography>
                          <Typography variant="h6" fontWeight={600} color="white">
                            {kpis.pendingReview}
                          </Typography>
                        </Stack>
                      </Grid>
                      <Grid item xs={6} sm={3}>
                        <Stack spacing={0.3}>
                          <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                            Finance Review
                          </Typography>
                          <Typography variant="h6" fontWeight={600} color="white">
                            {kpis.financeReview}
                          </Typography>
                        </Stack>
                      </Grid>
                      <Grid item xs={6} sm={3}>
                        <Stack spacing={0.3}>
                          <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                            Approved
                          </Typography>
                          <Typography variant="h6" fontWeight={600} color="white">
                            {kpis.approved}
                          </Typography>
                        </Stack>
                      </Grid>
                      <Grid item xs={6} sm={3}>
                        <Stack spacing={0.3}>
                          <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                            Paid
                          </Typography>
                          <Typography variant="h6" fontWeight={600} color="white">
                            {kpis.paid}
                          </Typography>
                        </Stack>
                      </Grid>
                    </Grid>
                  </Grid>
                </Grid>
              </CardContent>
            </Card>

            <Card>
              <CardContent>
                <Box
                  sx={{
                    display: 'flex',
                    alignItems: { xs: 'flex-start', md: 'center' },
                    justifyContent: 'space-between',
                    gap: 2,
                    flexDirection: { xs: 'column', md: 'row' },
                    mb: 1,
                  }}
                >
                  <Box>
                    <Typography variant="subtitle1" sx={{ fontWeight: 700 }}>
                      Trends
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      Spend and expense count over time for the current filters
                    </Typography>
                  </Box>

                  <ToggleButtonGroup
                    size="small"
                    exclusive
                    value={trendGranularity}
                    onChange={(_, v) => {
                      if (v === null) return;
                      setTrendGranularity(v);
                    }}
                    aria-label="Trends granularity"
                  >
                    <ToggleButton value="day">Daily</ToggleButton>
                    <ToggleButton value="week">Weekly</ToggleButton>
                    <ToggleButton value="month">Monthly</ToggleButton>
                  </ToggleButtonGroup>
                </Box>

                {filteredExpenses.length > 0 && trends.series.length === 0 ? (
                  <Alert severity="info" variant="outlined" sx={{ mb: 2 }}>
                    Trends not available from current data (missing expense dates).
                  </Alert>
                ) : null}

                {filteredExpenses.length === 0 ? (
                  <Alert severity="info" variant="outlined" sx={{ mb: 2 }}>
                    No expenses match the current filters.
                  </Alert>
                ) : (
                  <Box sx={{ width: '100%', height: 260 }}>
                    <ResponsiveContainer width="100%" height="100%">
                      <ComposedChart data={trends.series} margin={{ top: 8, right: 16, left: 0, bottom: 8 }}>
                        <CartesianGrid strokeDasharray="3 3" stroke={theme.palette.divider} />
                        <XAxis dataKey="label" tick={{ fontSize: 12 }} />
                        <YAxis
                          yAxisId="spend"
                          tick={{ fontSize: 12 }}
                          tickFormatter={(v: number) => {
                            if (!Number.isFinite(v)) return '';
                            return formatMoney(v);
                          }}
                        />
                        <YAxis yAxisId="count" orientation="right" allowDecimals={false} tick={{ fontSize: 12 }} />
                        <Tooltip
                          formatter={(value: any, name: any) => {
                            if (name === 'spend') return [formatMoney(Number(value) || 0), 'Total Spend'];
                            if (name === 'count') return [String(Number(value) || 0), 'Expense Count'];
                            return [String(value), String(name)];
                          }}
                        />
                        <Legend
                          formatter={(value: any) => (value === 'spend' ? 'Total Spend' : value === 'count' ? 'Expense Count' : String(value))}
                        />
                        <Area
                          yAxisId="spend"
                          type="monotone"
                          dataKey="spend"
                          name="spend"
                          stroke={theme.palette.primary.main}
                          fill={theme.palette.primary.main}
                          fillOpacity={0.18}
                          strokeWidth={2}
                        />
                        <Bar
                          yAxisId="count"
                          dataKey="count"
                          name="count"
                          fill={theme.palette.secondary.main}
                          barSize={12}
                          radius={[4, 4, 0, 0]}
                        />
                      </ComposedChart>
                    </ResponsiveContainer>
                  </Box>
                )}

                <Grid container spacing={2} sx={{ mt: 1 }}>
                  <Grid item xs={12} sm={6} md={3}>
                    <Stack spacing={0.25}>
                      <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                        PEAK PERIOD
                      </Typography>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {trends.insights.peak ? `${trends.insights.peak.label} · ${formatMoney(trends.insights.peak.spend)}` : '—'}
                      </Typography>
                    </Stack>
                  </Grid>
                  <Grid item xs={12} sm={6} md={3}>
                    <Stack spacing={0.25}>
                      <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                        LOWEST PERIOD
                      </Typography>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {trends.insights.lowest ? `${trends.insights.lowest.label} · ${formatMoney(trends.insights.lowest.spend)}` : '—'}
                      </Typography>
                    </Stack>
                  </Grid>
                  <Grid item xs={12} sm={6} md={3}>
                    <Stack spacing={0.25}>
                      <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                        CHANGE VS PREVIOUS
                      </Typography>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {typeof trends.insights.pctChange === 'number'
                          ? `${trends.insights.pctChange >= 0 ? '+' : ''}${trends.insights.pctChange.toFixed(1)}%`
                          : '—'}
                      </Typography>
                    </Stack>
                  </Grid>
                  <Grid item xs={12} sm={6} md={3}>
                    <Stack spacing={0.25}>
                      <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                        SPEND VOLATILITY
                      </Typography>
                      <Typography variant="body2" sx={{ fontWeight: 600 }}>
                        {typeof trends.insights.volatility === 'number'
                          ? `${(trends.insights.volatility * 100).toFixed(1)}%`
                          : '—'}
                      </Typography>
                    </Stack>
                  </Grid>
                </Grid>
              </CardContent>
            </Card>

            <Grid container spacing={2}>
              <Grid item xs={12} md={6}>
                <Card>
                  <CardContent>
                    <Typography variant="subtitle1" sx={{ fontWeight: 700, mb: 1 }}>
                      Breakdown by Category
                    </Typography>
                    {breakdownByCategory.length === 0 ? (
                      <Alert severity="info" variant="outlined">
                        No expenses match the current filters.
                      </Alert>
                    ) : (
                      <Stack spacing={1.5}>
                        <Box sx={{ width: '100%', height: 240 }}>
                          <ResponsiveContainer width="100%" height="100%">
                            <ComposedChart
                              layout="vertical"
                              data={breakdownByCategory.slice(0, 8).map((row) => ({
                                name: row.key,
                                total: row.total,
                                count: row.count,
                              }))}
                              margin={{ top: 8, right: 16, left: 8, bottom: 8 }}
                            >
                              <CartesianGrid strokeDasharray="3 3" stroke={theme.palette.divider} />
                              <XAxis
                                type="number"
                                tick={{ fontSize: 12 }}
                                tickFormatter={(v: number) => (Number.isFinite(v) ? formatMoney(v) : '')}
                              />
                              <YAxis
                                type="category"
                                dataKey="name"
                                width={120}
                                tick={{ fontSize: 12 }}
                                tickFormatter={(v: any) => {
                                  const s = String(v ?? '');
                                  return s.length > 18 ? `${s.slice(0, 18)}…` : s;
                                }}
                              />
                              <Tooltip
                                formatter={(value: any, name: any, ctx: any) => {
                                  if (name === 'total') {
                                    const count = ctx?.payload?.count;
                                    const suffix = Number.isFinite(count) ? ` (${count})` : '';
                                    return [`${formatMoney(Number(value) || 0)}${suffix}`, 'Spend'];
                                  }
                                  return [String(value), String(name)];
                                }}
                              />
                              <Bar dataKey="total" name="total" fill={theme.palette.primary.main} barSize={14} radius={[0, 6, 6, 0]} />
                            </ComposedChart>
                          </ResponsiveContainer>
                        </Box>

                        <Stack spacing={1}>
                          {breakdownByCategory.slice(0, 10).map((row) => (
                            <Box key={row.key} sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                              <Typography variant="body2" color="text.primary">
                                {row.key}
                              </Typography>
                              <Typography variant="body2" color="text.secondary">
                                {formatMoney(row.total)} · {row.count}
                              </Typography>
                            </Box>
                          ))}
                        </Stack>
                      </Stack>
                    )}
                  </CardContent>
                </Card>
              </Grid>

              <Grid item xs={12} md={6}>
                <Card>
                  <CardContent>
                    <Typography variant="subtitle1" sx={{ fontWeight: 700, mb: 1 }}>
                      Breakdown by Project
                    </Typography>
                    {breakdownByProject.length === 0 ? (
                      <Alert severity="info" variant="outlined">
                        No expenses match the current filters.
                      </Alert>
                    ) : (
                      <Stack spacing={1.5}>
                        <Box sx={{ width: '100%', height: 240 }}>
                          <ResponsiveContainer width="100%" height="100%">
                            <ComposedChart
                              layout="vertical"
                              data={breakdownByProject.slice(0, 8).map((row) => ({
                                name: row.name,
                                total: row.total,
                                count: row.count,
                              }))}
                              margin={{ top: 8, right: 16, left: 8, bottom: 8 }}
                            >
                              <CartesianGrid strokeDasharray="3 3" stroke={theme.palette.divider} />
                              <XAxis
                                type="number"
                                tick={{ fontSize: 12 }}
                                tickFormatter={(v: number) => (Number.isFinite(v) ? formatMoney(v) : '')}
                              />
                              <YAxis
                                type="category"
                                dataKey="name"
                                width={120}
                                tick={{ fontSize: 12 }}
                                tickFormatter={(v: any) => {
                                  const s = String(v ?? '');
                                  return s.length > 18 ? `${s.slice(0, 18)}…` : s;
                                }}
                              />
                              <Tooltip
                                formatter={(value: any, name: any, ctx: any) => {
                                  if (name === 'total') {
                                    const count = ctx?.payload?.count;
                                    const suffix = Number.isFinite(count) ? ` (${count})` : '';
                                    return [`${formatMoney(Number(value) || 0)}${suffix}`, 'Spend'];
                                  }
                                  return [String(value), String(name)];
                                }}
                              />
                              <Bar dataKey="total" name="total" fill={theme.palette.primary.main} barSize={14} radius={[0, 6, 6, 0]} />
                            </ComposedChart>
                          </ResponsiveContainer>
                        </Box>

                        <Stack spacing={1}>
                          {breakdownByProject.slice(0, 10).map((row) => (
                            <Box key={row.id} sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                              <Typography variant="body2" color="text.primary">
                                {row.name}
                              </Typography>
                              <Typography variant="body2" color="text.secondary">
                                {formatMoney(row.total)} · {row.count}
                              </Typography>
                            </Box>
                          ))}
                        </Stack>
                      </Stack>
                    )}
                  </CardContent>
                </Card>
              </Grid>
            </Grid>

            <Grid container spacing={2} sx={{ mt: 0 }}>
              <Grid item xs={12} md={6}>
                <Card>
                  <CardContent>
                    <Typography variant="subtitle1" sx={{ fontWeight: 700, mb: 1 }}>
                      Outliers
                    </Typography>
                    <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                      Detect unusually large expenses using an IQR threshold (Q3 + 1.5×IQR)
                    </Typography>

                    {filteredExpenses.length === 0 ? (
                      <Alert severity="info" variant="outlined">
                        No expenses match the current filters.
                      </Alert>
                    ) : outliers.threshold === null ? (
                      <Alert severity="info">
                        Not enough variation to compute an outlier threshold for this result set.
                      </Alert>
                    ) : outliers.rows.length === 0 ? (
                      <Alert severity="success">No outliers detected (threshold &gt; {formatMoney(outliers.threshold)}).</Alert>
                    ) : (
                      <Stack spacing={1}>
                        <Alert severity="warning">Outlier threshold &gt; {formatMoney(outliers.threshold)}</Alert>
                        {outliers.rows.map(({ exp, amount }) => {
                          const name = exp.project?.name ? String(exp.project.name) : `Project #${exp.project_id}`;
                          const cat = typeof exp.category === 'string' && exp.category.trim() ? exp.category : 'Uncategorized';
                          const ymd = typeof exp.date === 'string' ? exp.date.slice(0, 10) : '';
                          return (
                            <Box key={String(exp.id ?? `${exp.project_id}-${ymd}-${amount}`)} sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                              <Typography variant="body2" color="text.primary">
                                {formatMoney(amount)}
                              </Typography>
                              <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'right' }}>
                                {name} · {cat} · {ymd}
                              </Typography>
                            </Box>
                          );
                        })}
                      </Stack>
                    )}
                  </CardContent>
                </Card>
              </Grid>

              <Grid item xs={12} md={6}>
                <Card>
                  <CardContent>
                    <Typography variant="subtitle1" sx={{ fontWeight: 700, mb: 1 }}>
                      What changed
                    </Typography>
                    {previousRange ? (
                      <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                        Comparison vs previous period {previousRange.from} → {previousRange.to}
                      </Typography>
                    ) : (
                      <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                        Select a valid date range to compare.
                      </Typography>
                    )}

                    {!previousRange ? null : (
                      <Stack spacing={1}>
                        <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                          <Typography variant="body2" color="text.primary" sx={{ fontWeight: 600 }}>
                            Total spend
                          </Typography>
                          <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'right' }}>
                            {formatMoney(comparison.current.total)} vs {formatMoney(comparison.previous.total)}
                          </Typography>
                        </Box>

                        <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                          <Typography variant="body2" color="text.primary" sx={{ fontWeight: 600 }}>
                            Change
                          </Typography>
                          <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'right' }}>
                            {formatMoney(comparison.delta.total)}
                            {typeof comparison.pct.total === 'number'
                              ? ` (${comparison.pct.total >= 0 ? '+' : ''}${comparison.pct.total.toFixed(1)}%)`
                              : ''}
                          </Typography>
                        </Box>

                        <Box sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                          <Typography variant="body2" color="text.primary" sx={{ fontWeight: 600 }}>
                            Volume
                          </Typography>
                          <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'right' }}>
                            {comparison.current.count} vs {comparison.previous.count}
                            {typeof comparison.pct.count === 'number'
                              ? ` (${comparison.pct.count >= 0 ? '+' : ''}${comparison.pct.count.toFixed(1)}%)`
                              : ''}
                          </Typography>
                        </Box>

                        <Box sx={{ pt: 0.5 }}>
                          <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                            TOP CATEGORY (CURRENT → PREVIOUS)
                          </Typography>
                          <Typography variant="body2" color="text.primary">
                            {breakdownByCategory[0]
                              ? `${breakdownByCategory[0].key} ${formatMoney(breakdownByCategory[0].total)}`
                              : '—'}
                            {'  →  '}
                            {comparison.previousTopCategory
                              ? `${comparison.previousTopCategory.key} ${formatMoney(comparison.previousTopCategory.total)}`
                              : '—'}
                          </Typography>
                        </Box>

                        <Box sx={{ pt: 0.5 }}>
                          <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                            TOP PROJECT (CURRENT → PREVIOUS)
                          </Typography>
                          <Typography variant="body2" color="text.primary">
                            {breakdownByProject[0]
                              ? `${breakdownByProject[0].name} ${formatMoney(breakdownByProject[0].total)}`
                              : '—'}
                            {'  →  '}
                            {comparison.previousTopProject
                              ? `${comparison.previousTopProject.name} ${formatMoney(comparison.previousTopProject.total)}`
                              : '—'}
                          </Typography>
                        </Box>
                      </Stack>
                    )}
                  </CardContent>
                </Card>
              </Grid>
            </Grid>

            <Card>
              <CardContent>
                <Typography variant="subtitle1" sx={{ fontWeight: 700, mb: 1 }}>
                  Status funnel
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                  Counts and spend by status for the current filters
                </Typography>

                {filteredExpenses.length === 0 ? (
                  <Alert severity="info" variant="outlined">
                    No expenses match the current filters.
                  </Alert>
                ) : (
                  <>
                    <Box sx={{ width: '100%', height: 260 }}>
                      <ResponsiveContainer width="100%" height="100%">
                        <ComposedChart
                          layout="vertical"
                          data={statusBreakdown.map((s) => ({
                            name: s.statusKey,
                            total: s.total,
                            count: s.count,
                          }))}
                          margin={{ top: 8, right: 16, left: 8, bottom: 8 }}
                        >
                          <CartesianGrid strokeDasharray="3 3" stroke={theme.palette.divider} />
                          <XAxis
                            type="number"
                            tick={{ fontSize: 12 }}
                            tickFormatter={(v: number) => (Number.isFinite(v) ? formatMoney(v) : '')}
                          />
                          <YAxis type="category" dataKey="name" width={140} tick={{ fontSize: 12 }} />
                          <Tooltip
                            formatter={(value: any, name: any, ctx: any) => {
                              if (name === 'total') {
                                const count = ctx?.payload?.count;
                                const suffix = Number.isFinite(count) ? ` (${count})` : '';
                                return [`${formatMoney(Number(value) || 0)}${suffix}`, 'Spend'];
                              }
                              if (name === 'count') return [String(Number(value) || 0), 'Count'];
                              return [String(value), String(name)];
                            }}
                          />
                          <Bar dataKey="total" name="total" fill={theme.palette.primary.main} barSize={14} radius={[0, 6, 6, 0]} />
                        </ComposedChart>
                      </ResponsiveContainer>
                    </Box>

                    <Grid container spacing={2} sx={{ mt: 1 }}>
                      <Grid item xs={12} sm={6} md={4}>
                        <Stack spacing={0.25}>
                          <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                            SUBMITTED → PAID
                          </Typography>
                          <Typography variant="body2" sx={{ fontWeight: 600 }}>
                            {typeof statusFunnel.rates.submittedToPaid === 'number'
                              ? `${statusFunnel.rates.submittedToPaid.toFixed(1)}%`
                              : '—'}
                          </Typography>
                        </Stack>
                      </Grid>
                      <Grid item xs={12} sm={6} md={4}>
                        <Stack spacing={0.25}>
                          <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                            SUBMITTED → FINANCE REVIEW
                          </Typography>
                          <Typography variant="body2" sx={{ fontWeight: 600 }}>
                            {typeof statusFunnel.rates.submittedToFinanceReview === 'number'
                              ? `${statusFunnel.rates.submittedToFinanceReview.toFixed(1)}%`
                              : '—'}
                          </Typography>
                        </Stack>
                      </Grid>
                      <Grid item xs={12} sm={6} md={4}>
                        <Stack spacing={0.25}>
                          <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                            FINANCE REVIEW → FINANCE APPROVED
                          </Typography>
                          <Typography variant="body2" sx={{ fontWeight: 600 }}>
                            {typeof statusFunnel.rates.financeReviewToFinanceApproved === 'number'
                              ? `${statusFunnel.rates.financeReviewToFinanceApproved.toFixed(1)}%`
                              : '—'}
                          </Typography>
                        </Stack>
                      </Grid>
                    </Grid>
                  </>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardContent>
                <Typography variant="subtitle1" sx={{ fontWeight: 700, mb: 1 }}>
                  Data quality
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                  Coverage indicators for the current filtered result set
                </Typography>

                {filteredExpenses.length === 0 ? (
                  <Alert severity="info" variant="outlined">
                    No expenses match the current filters.
                  </Alert>
                ) : (
                  <Grid container spacing={2}>
                    <Grid item xs={12} sm={6} md={3}>
                      <Stack spacing={0.25}>
                        <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                          MISSING CATEGORY
                        </Typography>
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {dataQuality.missingCategory}
                        </Typography>
                      </Stack>
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                      <Stack spacing={0.25}>
                        <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                          MISSING PROJECT NAME
                        </Typography>
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {dataQuality.missingProjectName}
                        </Typography>
                      </Stack>
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                      <Stack spacing={0.25}>
                        <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                          UNPARSEABLE TREND DATES
                        </Typography>
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {dataQuality.unparseableTrendDates}
                        </Typography>
                      </Stack>
                    </Grid>
                    <Grid item xs={12} sm={6} md={3}>
                      <Stack spacing={0.25}>
                        <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                          RESULTS
                        </Typography>
                        <Typography variant="body2" sx={{ fontWeight: 600 }}>
                          {dataQuality.total}
                        </Typography>
                      </Stack>
                    </Grid>
                  </Grid>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardContent>
                <Typography variant="subtitle1" sx={{ fontWeight: 700, mb: 1 }}>
                  Top movers
                </Typography>
                {!previousRange ? (
                  <Alert severity="info">Select a valid date range to compare with the previous period.</Alert>
                ) : (
                  <>
                    <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                      Biggest spend deltas vs previous period {previousRange.from} → {previousRange.to}
                    </Typography>

                    <Grid container spacing={2}>
                      <Grid item xs={12} md={6}>
                        <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>
                          Categories
                        </Typography>
                        <Grid container spacing={2}>
                          <Grid item xs={12} sm={6}>
                            <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                              INCREASES
                            </Typography>
                            <Stack spacing={0.75} sx={{ mt: 0.75 }}>
                              {(moversByCategory?.increases ?? []).filter((r) => r.deltaTotal > 0).slice(0, 5).length === 0 ? (
                                <Typography variant="body2" color="text.secondary">
                                  —
                                </Typography>
                              ) : (
                                (moversByCategory?.increases ?? [])
                                  .filter((r) => r.deltaTotal > 0)
                                  .slice(0, 5)
                                  .map((r) => (
                                    <Box key={`cat-up-${r.key}`} sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                                      <Typography variant="body2" color="text.primary">
                                        {r.key}
                                      </Typography>
                                      <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'right' }}>
                                        +{formatMoney(r.deltaTotal)}
                                      </Typography>
                                    </Box>
                                  ))
                              )}
                            </Stack>
                          </Grid>
                          <Grid item xs={12} sm={6}>
                            <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                              DECREASES
                            </Typography>
                            <Stack spacing={0.75} sx={{ mt: 0.75 }}>
                              {(moversByCategory?.decreases ?? []).filter((r) => r.deltaTotal < 0).slice(0, 5).length === 0 ? (
                                <Typography variant="body2" color="text.secondary">
                                  —
                                </Typography>
                              ) : (
                                (moversByCategory?.decreases ?? [])
                                  .filter((r) => r.deltaTotal < 0)
                                  .slice(0, 5)
                                  .map((r) => (
                                    <Box key={`cat-down-${r.key}`} sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                                      <Typography variant="body2" color="text.primary">
                                        {r.key}
                                      </Typography>
                                      <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'right' }}>
                                        {formatMoney(r.deltaTotal)}
                                      </Typography>
                                    </Box>
                                  ))
                              )}
                            </Stack>
                          </Grid>
                        </Grid>
                      </Grid>

                      <Grid item xs={12} md={6}>
                        <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>
                          Projects
                        </Typography>
                        <Grid container spacing={2}>
                          <Grid item xs={12} sm={6}>
                            <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                              INCREASES
                            </Typography>
                            <Stack spacing={0.75} sx={{ mt: 0.75 }}>
                              {(moversByProject?.increases ?? []).filter((r) => r.deltaTotal > 0).slice(0, 5).length === 0 ? (
                                <Typography variant="body2" color="text.secondary">
                                  —
                                </Typography>
                              ) : (
                                (moversByProject?.increases ?? [])
                                  .filter((r) => r.deltaTotal > 0)
                                  .slice(0, 5)
                                  .map((r) => (
                                    <Box key={`proj-up-${r.id}`} sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                                      <Typography variant="body2" color="text.primary">
                                        {r.name}
                                      </Typography>
                                      <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'right' }}>
                                        +{formatMoney(r.deltaTotal)}
                                      </Typography>
                                    </Box>
                                  ))
                              )}
                            </Stack>
                          </Grid>
                          <Grid item xs={12} sm={6}>
                            <Typography variant="caption" color="text.secondary" sx={{ fontWeight: 700 }}>
                              DECREASES
                            </Typography>
                            <Stack spacing={0.75} sx={{ mt: 0.75 }}>
                              {(moversByProject?.decreases ?? []).filter((r) => r.deltaTotal < 0).slice(0, 5).length === 0 ? (
                                <Typography variant="body2" color="text.secondary">
                                  —
                                </Typography>
                              ) : (
                                (moversByProject?.decreases ?? [])
                                  .filter((r) => r.deltaTotal < 0)
                                  .slice(0, 5)
                                  .map((r) => (
                                    <Box key={`proj-down-${r.id}`} sx={{ display: 'flex', justifyContent: 'space-between', gap: 2 }}>
                                      <Typography variant="body2" color="text.primary">
                                        {r.name}
                                      </Typography>
                                      <Typography variant="body2" color="text.secondary" sx={{ textAlign: 'right' }}>
                                        {formatMoney(r.deltaTotal)}
                                      </Typography>
                                    </Box>
                                  ))
                              )}
                            </Stack>
                          </Grid>
                        </Grid>
                      </Grid>
                    </Grid>
                  </>
                )}
              </CardContent>
            </Card>

            <Card>
              <CardContent>
                <Typography variant="subtitle1" sx={{ fontWeight: 700, mb: 1 }}>
                  Spend mix
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                  Share of total spend by category and status
                </Typography>

                {filteredExpenses.length === 0 ? (
                  <Alert severity="info" variant="outlined">
                    No expenses match the current filters.
                  </Alert>
                ) : spendMix.totalSpend <= 0 ? (
                  <Alert severity="info">Spend mix not available (total spend is 0 for the current filters).</Alert>
                ) : (
                  <Grid container spacing={2}>
                    <Grid item xs={12} md={6}>
                      <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>
                        Category mix
                      </Typography>
                      <Box sx={{ width: '100%', height: 240 }}>
                        <ResponsiveContainer width="100%" height="100%">
                          <ComposedChart
                            layout="vertical"
                            data={spendMix.categories.slice(0, 8).map((r) => ({
                              name: r.key,
                              share: r.share,
                              total: r.total,
                            }))}
                            margin={{ top: 8, right: 16, left: 8, bottom: 8 }}
                          >
                            <CartesianGrid strokeDasharray="3 3" stroke={theme.palette.divider} />
                            <XAxis
                              type="number"
                              domain={[0, 100]}
                              tick={{ fontSize: 12 }}
                              tickFormatter={(v: number) => (Number.isFinite(v) ? `${v.toFixed(0)}%` : '')}
                            />
                            <YAxis
                              type="category"
                              dataKey="name"
                              width={120}
                              tick={{ fontSize: 12 }}
                              tickFormatter={(v: any) => {
                                const s = String(v ?? '');
                                return s.length > 18 ? `${s.slice(0, 18)}…` : s;
                              }}
                            />
                            <Tooltip
                              formatter={(value: any, name: any, ctx: any) => {
                                if (name === 'share') {
                                  const total = ctx?.payload?.total;
                                  const suffix = Number.isFinite(total) ? ` (${formatMoney(Number(total) || 0)})` : '';
                                  return [`${(Number(value) || 0).toFixed(1)}%${suffix}`, 'Share'];
                                }
                                return [String(value), String(name)];
                              }}
                            />
                            <Bar dataKey="share" name="share" fill={theme.palette.primary.main} barSize={14} radius={[0, 6, 6, 0]} />
                          </ComposedChart>
                        </ResponsiveContainer>
                      </Box>
                    </Grid>

                    <Grid item xs={12} md={6}>
                      <Typography variant="subtitle2" sx={{ fontWeight: 700, mb: 1 }}>
                        Status mix
                      </Typography>
                      <Box sx={{ width: '100%', height: 240 }}>
                        <ResponsiveContainer width="100%" height="100%">
                          <ComposedChart
                            layout="vertical"
                            data={spendMix.statuses.slice(0, 8).map((r) => ({
                              name: r.key,
                              share: r.share,
                              total: r.total,
                            }))}
                            margin={{ top: 8, right: 16, left: 8, bottom: 8 }}
                          >
                            <CartesianGrid strokeDasharray="3 3" stroke={theme.palette.divider} />
                            <XAxis
                              type="number"
                              domain={[0, 100]}
                              tick={{ fontSize: 12 }}
                              tickFormatter={(v: number) => (Number.isFinite(v) ? `${v.toFixed(0)}%` : '')}
                            />
                            <YAxis
                              type="category"
                              dataKey="name"
                              width={140}
                              tick={{ fontSize: 12 }}
                            />
                            <Tooltip
                              formatter={(value: any, name: any, ctx: any) => {
                                if (name === 'share') {
                                  const total = ctx?.payload?.total;
                                  const suffix = Number.isFinite(total) ? ` (${formatMoney(Number(total) || 0)})` : '';
                                  return [`${(Number(value) || 0).toFixed(1)}%${suffix}`, 'Share'];
                                }
                                return [String(value), String(name)];
                              }}
                            />
                            <Bar dataKey="share" name="share" fill={theme.palette.secondary.main} barSize={14} radius={[0, 6, 6, 0]} />
                          </ComposedChart>
                        </ResponsiveContainer>
                      </Box>
                    </Grid>
                  </Grid>
                )}
              </CardContent>
            </Card>
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

export default ExpensesAnalysisReport;
