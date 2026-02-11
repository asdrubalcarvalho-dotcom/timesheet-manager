import React, { useCallback, useMemo, useRef, useState } from 'react';
import {
  Alert,
  AlertTitle,
  Box,
  Button,
  Chip,
  CircularProgress,
  Stack,
  TextField,
  Typography,
} from '@mui/material';
import SmartToyIcon from '@mui/icons-material/SmartToy';
import { useBilling } from '../../../contexts/BillingContext';
import { getTenantAiState } from '../../Common/aiState';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { commitTimesheetPlan, previewTimesheetPlan } from '../../../services/aiTimesheet';
import { API_URL, expensesApi, fetchWithAuth, tasksApi, timesheetsApi } from '../../../services/api';
import type {
  AiTimesheetPlan,
  AiTimesheetPreviewResponse,
  Expense,
  Task,
  Timesheet,
  TimesheetManagerRow,
} from '../../../types';
import { useNotification } from '../../../contexts/NotificationContext';
import { useAuth } from '../../Auth/AuthContext';
import AiAssistantMessage from '../../AiTimesheet/AiAssistantMessage';
import AiTimesheetPreviewDetails from '../../AiTimesheet/AiTimesheetPreviewDetails';
import {
  mapAiTimesheetMissingFields,
  mapAiTimesheetPreviewError,
} from '../../../utils/aiTimesheetPreview';
import { looksLikeTimesheetBuilder } from '../../../utils/looksLikeTimesheetBuilder';
import { formatTenantDate, formatTenantMoney, formatTenantNumber } from '../../../utils/tenantFormatting';

type ChatMessage = {
  role: 'user' | 'assistant';
  content: React.ReactNode;
};

type FlaggedDomain = 'timesheets' | 'approvals' | 'expenses' | 'planning' | 'projects';

type FlaggedRange = {
  from: string;
  to: string;
};

type OptionalFilters = {
  project?: string;
  technician?: string;
};

type GuidedIntent =
  | { kind: 'flagged'; domain: FlaggedDomain; range: FlaggedRange }
  | { kind: 'overtime'; range: FlaggedRange }
  | { kind: 'projectBudget'; range: FlaggedRange };

type GuidedStep = 'domain' | 'period' | 'filter';

type GuidedState = {
  intent: 'flagged' | 'overtime' | 'projectBudget';
  domain?: FlaggedDomain;
  range?: FlaggedRange;
  step: GuidedStep;
};

type FooterQuickReply = {
  key: string;
  label: string;
  onClick: () => void;
};


const normalizeFlaggedQuery = (value: string): string => {
  return value
    .toLowerCase()
    .normalize('NFD')
    .replace(/[\u0300-\u036f]/g, '');
};

const normalizeMatchValue = (value: string): string => normalizeFlaggedQuery(value).trim();

const isFlaggedIntent = (value: string): boolean => {
  const normalized = normalizeFlaggedQuery(value);
  return /\b(flagged|flags?|pending|pendentes?|anomal(y|ies)|irregularit(y|ies)|sinalizad[oa]s?|lancamentos?\s+sinalizad[oa]s?|anomalias|irregularidades?)\b/.test(
    normalized
  );
};

const isOvertimeIntent = (value: string): boolean => {
  const normalized = normalizeFlaggedQuery(value);
  return /\b(overtime|extra(s)?|horas?[-\s]?extra(s)?|ot\b|sobretempo|picos?\s+de\s+horas?\s+extra(s)?)\b/.test(
    normalized
  );
};

const isProjectBudgetIntent = (value: string): boolean => {
  const normalized = normalizeFlaggedQuery(value);
  return /\b(budget(ed)?|orcament(o|os)|or[cç]amentad(o|as)?|planned\s+hours?|horas?\s+previstas?|estimadas?|exceed(ed)?|ultrapassad[oa]s?|exced(eu|eram|idas?|er)?)\b/.test(
    normalized
  );
};

const parseFlaggedRange = (value: string): FlaggedRange | null => {
  const normalized = normalizeFlaggedQuery(value);
  const match = normalized.match(/\b(\d{4}-\d{2}-\d{2})\s*\.\.\s*(\d{4}-\d{2}-\d{2})\b/);
  if (match) {
    return { from: match[1], to: match[2] };
  }

  const altMatch = normalized.match(/\b(\d{4}-\d{2}-\d{2})\s*(?:to|ate)\s*(\d{4}-\d{2}-\d{2})\b/i);
  if (altMatch) {
    return { from: altMatch[1], to: altMatch[2] };
  }

  return null;
};

const parseFlaggedDomain = (value: string): FlaggedDomain | null => {
  const normalized = normalizeFlaggedQuery(value);

  if (/\b(approvals?|aprovacoes?)\b/.test(normalized)) {
    return 'approvals';
  }

  if (/\b(expenses?|despesas?|gastos?)\b/.test(normalized)) {
    return 'expenses';
  }

  if (/\b(planning|planeamento|planejamento)\b/.test(normalized)) {
    return 'planning';
  }

  if (/\b(projects?|projetos?)\b/.test(normalized)) {
    return 'projects';
  }

  if (/\b(timesheets?|lancamentos?|registos?|apontamentos?)\b/.test(normalized)) {
    return 'timesheets';
  }

  return null;
};

const normalizeArrayResponse = <T,>(value: unknown): T[] => {
  if (Array.isArray(value)) {
    return value as T[];
  }

  if (value && typeof value === 'object') {
    const data = (value as { data?: unknown }).data;
    if (Array.isArray(data)) {
      return data as T[];
    }
  }

  return [];
};

const matchesFilterValue = (source: string | null | undefined, filter?: string): boolean => {
  if (!filter) return true;
  if (!source) return false;
  return normalizeMatchValue(source).includes(normalizeMatchValue(filter));
};

const extractFilterValue = (query: string, type: 'project' | 'technician'): string | null => {
  const normalized = normalizeFlaggedQuery(query);
  const keyword = type === 'project' ? 'project' : 'technician';
  const keywordAlt = type === 'project' ? 'projeto' : 'tecnico';
  const match = normalized.match(new RegExp(`(?:^|\b)(${keyword}|${keywordAlt})\b\s*[:=]?\s*(.+)$`, 'i'));
  if (match?.[2]) {
    return match[2].trim();
  }
  return null;
};

const normalizeHours = (value: number | string | null | undefined): number => {
  if (value === null || typeof value === 'undefined') return 0;
  const numeric = typeof value === 'number' ? value : Number(value);
  return Number.isFinite(numeric) ? numeric : 0;
};

// Keep in sync with TimesheetCalendar validation cap.
const DAILY_HOUR_CAP = 12;

const isDateWithinRange = (dateValue: string | null | undefined, range: FlaggedRange): boolean => {
  if (!dateValue) return false;
  return dateValue >= range.from && dateValue <= range.to;
};

const toDateParts = (date: Date, timeZone: string): { year: number; month: number; day: number } => {
  const parts = new Intl.DateTimeFormat('en-CA', {
    timeZone,
    year: 'numeric',
    month: '2-digit',
    day: '2-digit',
  }).formatToParts(date);

  const map = parts.reduce<Record<string, string>>((acc, part) => {
    acc[part.type] = part.value;
    return acc;
  }, {});

  return {
    year: Number(map.year),
    month: Number(map.month),
    day: Number(map.day),
  };
};

const toUtcDate = (year: number, month: number, day: number): Date => {
  return new Date(Date.UTC(year, month - 1, day));
};

const addUtcDays = (date: Date, days: number): Date => {
  return new Date(date.getTime() + days * 24 * 60 * 60 * 1000);
};

const formatYmd = (date: Date): string => {
  const y = date.getUTCFullYear();
  const m = String(date.getUTCMonth() + 1).padStart(2, '0');
  const d = String(date.getUTCDate()).padStart(2, '0');
  return `${y}-${m}-${d}`;
};

const buildDateRange = (
  period: 'last7' | 'month' | 'last30',
  timeZone: string
): { from: string; to: string } => {
  const now = new Date();
  const { year, month, day } = toDateParts(now, timeZone);
  const base = toUtcDate(year, month, day);

  if (period === 'last7') {
    const start = addUtcDays(base, -6);
    return { from: formatYmd(start), to: formatYmd(base) };
  }

  if (period === 'month') {
    const start = toUtcDate(year, month, 1);
    const end = new Date(Date.UTC(year, month, 0));
    return { from: formatYmd(start), to: formatYmd(end) };
  }

  const start = addUtcDays(base, -29);
  return { from: formatYmd(start), to: formatYmd(base) };
};

export const AiChatTab: React.FC = () => {
  const { t } = useTranslation();
  const { tenantContext } = useAuth();
  const { billingSummary, tenantAiEnabled, openCheckoutForAddon } = useBilling();
  const aiState = useMemo(() => getTenantAiState(billingSummary, tenantAiEnabled), [billingSummary, tenantAiEnabled]);
  const navigate = useNavigate();
  const { showSuccess, showError } = useNotification();

  const [question, setQuestion] = useState('');
  const [messages, setMessages] = useState<ChatMessage[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [preview, setPreview] = useState<AiTimesheetPreviewResponse | null>(null);
  const [previewPlan, setPreviewPlan] = useState<AiTimesheetPlan | null>(null);
  const [applyLoading, setApplyLoading] = useState(false);
  const [showExamples, setShowExamples] = useState(false);
  const [guidedState, setGuidedState] = useState<GuidedState | null>(null);
  const [pendingGuided, setPendingGuided] = useState<GuidedIntent | null>(null);
  const [pendingFilterType, setPendingFilterType] = useState<'project' | 'technician' | null>(null);
  const [footerQuickReplies, setFooterQuickReplies] = useState<FooterQuickReply[]>([]);
  const inputRef = useRef<HTMLInputElement | null>(null);
  const transcriptRef = useRef<HTMLDivElement | null>(null);
  const bottomRef = useRef<HTMLDivElement | null>(null);

  const isDev = typeof import.meta !== 'undefined' && !!import.meta.env?.DEV;
  const logAiTimesheet = useCallback(
    (message: string, detail?: unknown) => {
      if (!isDev) return;
      if (typeof detail === 'undefined') {
        console.info(`AI_TIMESHEET: ${message}`);
      } else {
        console.info(`AI_TIMESHEET: ${message}`, detail);
      }
    },
    [isDev]
  );

  const examplePrompts = useMemo(() => {
    const fallbackPrompts = [
      'DATE_RANGE=2026-02-10..2026-02-14\nProjeto: "Mobile Banking App"\nBloco 1: 09:00-13:00\nBloco 2: 14:00-18:00',
      'DATE_RANGE=2026-02-10..2026-02-14\nProjeto: "Mobile Banking App"\nBloco 1: 09:00–13:00\nBloco 2: 14:00–18:00',
      'DATE_RANGE=2026-02-10..2026-02-14\nProject: "Mobile Banking App"\nBlock 1: 09:00-13:00\nBlock 2: 14:00-18:00',
      'DATE_RANGE=2026-02-10..2026-02-10\nProjeto: "Mobile Banking App"\n09:00-10:00',
      'DATE_RANGE=2026-02-10..2026-02-14\nProjeto: "Mobile Banking App"\n09:00-13:00\n14:00-18:00',
      'DATE_RANGE=2026-02-10..2026-02-14\nProject: "Mobile Banking App"\n09:00-13:00\n14:00-18:00',
    ];
    const raw = t('rightPanel.ai.examples.items', { returnObjects: true });
    if (Array.isArray(raw) && raw.length > 0) {
      return raw.map((item) => String(item));
    }
    return fallbackPrompts;
  }, [t]);

  const suggestionChips = useMemo(
    () => [
      t('rightPanel.ai.suggestions.flags'),
      t('rightPanel.ai.suggestions.overtime'),
      t('rightPanel.ai.suggestions.approvals'),
      t('rightPanel.ai.suggestions.projectCosts'),
      t('rightPanel.ai.suggestions.timesheetCreate'),
    ],
    [t]
  );

  const collapsedPrompts = useMemo(() => examplePrompts.slice(0, 3), [examplePrompts]);
  const expandedPrompts = useMemo(
    () => [...examplePrompts, ...suggestionChips],
    [examplePrompts, suggestionChips]
  );

  const expenseStatusLabels = useMemo(
    () => ({
      draft: t('expenses.status.draft'),
      submitted: t('expenses.status.submitted'),
      approved: t('expenses.status.approvedLegacy'),
      rejected: t('expenses.status.rejected'),
      finance_review: t('expenses.status.financeReview'),
      finance_approved: t('expenses.status.financeApproved'),
      paid: t('expenses.status.paid'),
      closed: t('common.unknown'),
    }),
    [t]
  );

  const formatDateLabel = useCallback(
    (value?: string | null) => (value ? formatTenantDate(value, tenantContext) : t('common.unknown')),
    [tenantContext, t]
  );

  const getFlaggedDomainLabel = useCallback(
    (domain: FlaggedDomain) => t(`rightPanel.ai.guidedFlagged.${domain}`),
    [t]
  );

  const resolveExpenseStatusLabel = useCallback(
    (status?: Expense['status']) => {
      if (!status) return t('common.unknown');
      return expenseStatusLabels[status] || t('common.unknown');
    },
    [expenseStatusLabels, t]
  );

  const withLoading = useCallback(
    async <T,>(task: () => Promise<T>): Promise<T> => {
      setLoading(true);
      try {
        return await task();
      } finally {
        setLoading(false);
      }
    },
    []
  );

  const pushAssistantMessage = useCallback((content: React.ReactNode) => {
    setMessages((prev) => [...prev, { role: 'assistant', content }]);
  }, []);

  const pushAssistantText = useCallback(
    (text: string) => {
      pushAssistantMessage(text);
    },
    [pushAssistantMessage]
  );

  const pushUserMessage = useCallback((text: string) => {
    setMessages((prev) => [...prev, { role: 'user', content: text }]);
  }, []);

  const setFooterRepliesForResults = useCallback(
    (replies: FooterQuickReply[]) => {
      setFooterQuickReplies(replies);
    },
    []
  );

  const focusInput = useCallback(() => {
    inputRef.current?.focus();
  }, []);

  const handleTranscriptScroll = useCallback(() => {}, []);

  const startGuidedPeriodStep = (intent: 'flagged' | 'overtime' | 'projectBudget', domain?: FlaggedDomain) => {
    setGuidedState({ intent, domain, step: 'period' });
    pushAssistantMessage(t('rightPanel.ai.guidedFlagged.periodQuestion'));
    const tz = tenantContext?.timezone || 'UTC';
    const periodReplies: FooterQuickReply[] = [
      {
        key: `${intent}-period-last7`,
        label: t('rightPanel.ai.guidedFlagged.periods.last7'),
        onClick: () => {
          pushUserMessage(
            t('rightPanel.ai.guidedSelection.period', {
              period: t('rightPanel.ai.guidedFlagged.periods.last7'),
            })
          );
          handlePeriodSelection(intent, domain, buildDateRange('last7', tz), t('rightPanel.ai.guidedFlagged.periods.last7'));
        },
      },
      {
        key: `${intent}-period-month`,
        label: t('rightPanel.ai.guidedFlagged.periods.month'),
        onClick: () => {
          pushUserMessage(
            t('rightPanel.ai.guidedSelection.period', {
              period: t('rightPanel.ai.guidedFlagged.periods.month'),
            })
          );
          handlePeriodSelection(intent, domain, buildDateRange('month', tz), t('rightPanel.ai.guidedFlagged.periods.month'));
        },
      },
      {
        key: `${intent}-period-last30`,
        label: t('rightPanel.ai.guidedFlagged.periods.last30'),
        onClick: () => {
          pushUserMessage(
            t('rightPanel.ai.guidedSelection.period', {
              period: t('rightPanel.ai.guidedFlagged.periods.last30'),
            })
          );
          handlePeriodSelection(intent, domain, buildDateRange('last30', tz), t('rightPanel.ai.guidedFlagged.periods.last30'));
        },
      },
      {
        key: `${intent}-period-custom`,
        label: t('rightPanel.ai.guidedFlagged.periods.custom'),
        onClick: () => {
          pushUserMessage(
            t('rightPanel.ai.guidedSelection.period', {
              period: t('rightPanel.ai.guidedFlagged.periods.custom'),
            })
          );
          setGuidedState({ intent, domain, step: 'period' });
          pushAssistantMessage(t('rightPanel.ai.guidedFlagged.customHelp'));
          setFooterQuickReplies([]);
          const promptKey =
            intent === 'flagged'
              ? `rightPanel.ai.guidedFlagged.prompts.${domain ?? 'timesheets'}`
              : `rightPanel.ai.guidedPeriod.prompts.${intent}`;
          setQuestion(
            t(promptKey, {
              from: 'YYYY-MM-DD',
              to: 'YYYY-MM-DD',
            })
          );
          focusInput();
        },
      },
    ];
    setFooterQuickReplies(periodReplies);
    focusInput();
  };

  const startGuidedFilterStep = (intent: GuidedIntent) => {
    setGuidedState({
      intent: intent.kind === 'flagged' ? 'flagged' : intent.kind,
      domain: intent.kind === 'flagged' ? intent.domain : undefined,
      range: intent.range,
      step: 'filter',
    });
    setPendingGuided(intent);
    setPendingFilterType(null);
    pushAssistantMessage(t('rightPanel.ai.guidedFilter.question'));
    setFooterQuickReplies([
      {
        key: 'filter-none',
        label: t('rightPanel.ai.guidedFilter.none'),
        onClick: () => {
          pushUserMessage(
            t('rightPanel.ai.guidedSelection.filter', {
              filter: t('rightPanel.ai.guidedFilter.none'),
            })
          );
          setGuidedState(null);
          setFooterQuickReplies([]);
          void withLoading(() => executeGuidedIntent(intent));
        },
      },
      {
        key: 'filter-project',
        label: t('rightPanel.ai.guidedFilter.project'),
        onClick: () => {
          pushUserMessage(
            t('rightPanel.ai.guidedSelection.filter', {
              filter: t('rightPanel.ai.guidedFilter.project'),
            })
          );
          setPendingFilterType('project');
          setFooterQuickReplies([]);
          pushAssistantMessage(t('rightPanel.ai.guidedFilter.projectAsk'));
          setQuestion(t('rightPanel.ai.guidedFilter.projectPrompt'));
          focusInput();
        },
      },
      {
        key: 'filter-technician',
        label: t('rightPanel.ai.guidedFilter.technician'),
        onClick: () => {
          pushUserMessage(
            t('rightPanel.ai.guidedSelection.filter', {
              filter: t('rightPanel.ai.guidedFilter.technician'),
            })
          );
          setPendingFilterType('technician');
          setFooterQuickReplies([]);
          pushAssistantMessage(t('rightPanel.ai.guidedFilter.technicianAsk'));
          setQuestion(t('rightPanel.ai.guidedFilter.technicianPrompt'));
          focusInput();
        },
      },
    ]);
    focusInput();
  };

  const handleDomainSelection = (domain: FlaggedDomain, rangeOverride?: FlaggedRange | null) => {
    pushUserMessage(
      t('rightPanel.ai.guidedSelection.domain', {
        domain: t(`rightPanel.ai.guidedFlagged.${domain}`),
      })
    );
    if (rangeOverride) {
      startGuidedFilterStep({ kind: 'flagged', domain, range: rangeOverride });
      return;
    }
    startGuidedPeriodStep('flagged', domain);
  };

  const startGuidedDomainStep = (rangeOverride?: FlaggedRange | null) => {
    setGuidedState({ intent: 'flagged', range: rangeOverride ?? undefined, step: 'domain' });
    pushAssistantMessage(t('rightPanel.ai.guidedFlagged.question'));
    setFooterQuickReplies([
      {
        key: 'domain-timesheets',
        label: t('rightPanel.ai.guidedFlagged.timesheets'),
        onClick: () => {
          handleDomainSelection('timesheets', rangeOverride);
        },
      },
      {
        key: 'domain-expenses',
        label: t('rightPanel.ai.guidedFlagged.expenses'),
        onClick: () => {
          handleDomainSelection('expenses', rangeOverride);
        },
      },
      {
        key: 'domain-approvals',
        label: t('rightPanel.ai.guidedFlagged.approvals'),
        onClick: () => {
          handleDomainSelection('approvals', rangeOverride);
        },
      },
      {
        key: 'domain-planning',
        label: t('rightPanel.ai.guidedFlagged.planning'),
        onClick: () => {
          handleDomainSelection('planning', rangeOverride);
        },
      },
      {
        key: 'domain-projects',
        label: t('rightPanel.ai.guidedFlagged.projects'),
        onClick: () => {
          handleDomainSelection('projects', rangeOverride);
        },
      },
    ]);
    focusInput();
  };

  const handlePeriodSelection = (
    intent: 'flagged' | 'overtime' | 'projectBudget',
    domain: FlaggedDomain | undefined,
    range: FlaggedRange,
    periodLabel: string
  ) => {
    pushUserMessage(
      t('rightPanel.ai.guidedSelection.period', {
        period: periodLabel,
      })
    );
    if (intent === 'flagged') {
      if (!domain) {
        startGuidedDomainStep(range);
        return;
      }
      startGuidedFilterStep({ kind: 'flagged', domain, range });
      return;
    }
    if (intent === 'overtime') {
      startGuidedFilterStep({ kind: 'overtime', range });
      return;
    }
    startGuidedFilterStep({ kind: 'projectBudget', range });
  };

  const runOvertimeQuery = useCallback(
    async (range: FlaggedRange, filters?: OptionalFilters, limit = 5) => {
      try {
        // Match calendar warning logic: overtime = daily total above 8 hours.
        const response = await timesheetsApi.getAll({
          start_date: range.from,
          end_date: range.to,
        });
        const timesheets = normalizeArrayResponse<Timesheet>(response).filter((ts) =>
          isDateWithinRange(ts.date, range)
        );
        const scopedTimesheets = timesheets.filter((ts) =>
          matchesFilterValue(ts.technician?.name, filters?.technician) &&
          matchesFilterValue(ts.project?.name, filters?.project)
        );

        const perTechDay = new Map<string, { technician: string; total: number }>();
        scopedTimesheets.forEach((ts) => {
          if (!ts.date) return;
          const techName = ts.technician?.name ?? t('common.unknown');
          const key = `${ts.technician_id ?? 'unknown'}-${ts.date}`;
          const entry = perTechDay.get(key) ?? { technician: techName, total: 0 };
          entry.total += normalizeHours(ts.hours_worked);
          perTechDay.set(key, entry);
        });

        const perTechnician = new Map<string, number>();
        perTechDay.forEach((entry) => {
          const overtime = Math.max(0, entry.total - 8);
          if (overtime <= 0) return;
          const current = perTechnician.get(entry.technician) ?? 0;
          perTechnician.set(entry.technician, current + overtime);
        });

        const overtimeRows = Array.from(perTechnician.entries())
          .map(([technician, overtimeHours]) => ({ technician, overtimeHours }))
          .filter((row) => row.overtimeHours > 0)
          .sort((a, b) => b.overtimeHours - a.overtimeHours);

        const count = overtimeRows.length;
        const totalOvertime = overtimeRows.reduce((acc, row) => acc + row.overtimeHours, 0);
        const examples = overtimeRows.slice(0, limit).map((row) =>
          t('rightPanel.ai.overtimeResults.example', {
            technician: row.technician,
            hours: formatTenantNumber(row.overtimeHours, tenantContext, 1),
          })
        );

        const lines = [
          t('rightPanel.ai.overtimeResults.title', range),
          count > 0
            ? t('rightPanel.ai.overtimeResults.count', {
                count,
                total: formatTenantNumber(totalOvertime, tenantContext, 1),
              })
            : t('rightPanel.ai.overtimeResults.none'),
        ];
        if (examples.length > 0) {
          lines.push(t('rightPanel.ai.overtimeResults.examplesTitle'));
          examples.forEach((example) => lines.push(`- ${example}`));
        }
        pushAssistantText(lines.join('\n'));
        setGuidedState(null);
        setFooterRepliesForResults([
          {
            key: 'overtime-change-period',
            label: t('rightPanel.ai.followUps.changePeriod'),
            onClick: () => {
              pushUserMessage(t('rightPanel.ai.followUps.changePeriod'));
              startGuidedPeriodStep('overtime');
            },
          },
          {
            key: 'overtime-show-more',
            label: t('rightPanel.ai.followUps.showMore'),
            onClick: () => {
              pushUserMessage(t('rightPanel.ai.followUps.showMore'));
              void withLoading(() => runOvertimeQuery(range, filters, limit + 5));
            },
          },
          {
            key: 'overtime-open',
            label: t('rightPanel.ai.followUps.openPage'),
            onClick: () => navigate(`/timesheets?from=${range.from}&to=${range.to}`),
          },
        ]);
      } catch (e: unknown) {
        const message = e instanceof Error ? e.message : t('rightPanel.ai.errorFallback');
        setError(message);
        showError(message);
        pushAssistantText(`${t('aiTimesheet.previewErrors.generic.title')}\n${message}`);
      }
    },
    [
      navigate,
      pushAssistantText,
      pushUserMessage,
      setFooterRepliesForResults,
      showError,
      startGuidedPeriodStep,
      t,
      tenantContext,
      withLoading,
    ]
  );

  const runProjectBudgetQuery = useCallback(
    async (range: FlaggedRange, filters?: OptionalFilters, limit = 5) => {
      try {
        // Use existing planning tasks (estimated_hours) vs logged timesheets. A backend budget endpoint would improve accuracy.
        const [tasksResponse, timesheetResponse] = await Promise.all([
          tasksApi.getAll(),
          timesheetsApi.getAll({
            start_date: range.from,
            end_date: range.to,
          }),
        ]);

        const tasks = normalizeArrayResponse<Task>(tasksResponse).filter((task) =>
          matchesFilterValue(task.project?.name, filters?.project)
        );
        const timesheets = normalizeArrayResponse<Timesheet>(timesheetResponse)
          .filter((ts) => isDateWithinRange(ts.date, range))
          .filter((ts) => matchesFilterValue(ts.project?.name, filters?.project));

        const plannedByProject = new Map<number, number>();
        const projectNames = new Map<number, string>();

        tasks.forEach((task) => {
          if (!task.project_id) return;
          const planned = normalizeHours(task.estimated_hours ?? 0);
          if (planned <= 0) return;
          const current = plannedByProject.get(task.project_id) ?? 0;
          plannedByProject.set(task.project_id, current + planned);
          if (task.project?.name) {
            projectNames.set(task.project_id, task.project.name);
          }
        });

        const loggedByProject = new Map<number, number>();
        timesheets.forEach((ts) => {
          if (!ts.project_id) return;
          const current = loggedByProject.get(ts.project_id) ?? 0;
          loggedByProject.set(ts.project_id, current + normalizeHours(ts.hours_worked));
          if (ts.project?.name) {
            projectNames.set(ts.project_id, ts.project.name);
          }
        });

        const rows = Array.from(plannedByProject.entries())
          .map(([projectId, plannedHours]) => {
            const loggedHours = loggedByProject.get(projectId) ?? 0;
            const delta = loggedHours - plannedHours;
            if (delta <= 0) return null;
            return {
              projectId,
              project: projectNames.get(projectId) ?? t('common.unknown'),
              plannedHours,
              loggedHours,
              delta,
            };
          })
          .filter((row): row is NonNullable<typeof row> => Boolean(row))
          .sort((a, b) => b.delta - a.delta);

        const count = rows.length;
        const examples = rows.slice(0, limit).map((row) =>
          t('rightPanel.ai.projectBudgetResults.example', {
            project: row.project,
            delta: formatTenantNumber(row.delta, tenantContext, 1),
            logged: formatTenantNumber(row.loggedHours, tenantContext, 1),
            planned: formatTenantNumber(row.plannedHours, tenantContext, 1),
          })
        );

        const lines = [
          t('rightPanel.ai.projectBudgetResults.title', range),
          count > 0
            ? t('rightPanel.ai.projectBudgetResults.count', { count })
            : t('rightPanel.ai.projectBudgetResults.none'),
        ];
        if (examples.length > 0) {
          lines.push(t('rightPanel.ai.projectBudgetResults.examplesTitle'));
          examples.forEach((example) => lines.push(`- ${example}`));
        }
        pushAssistantText(lines.join('\n'));
        setGuidedState(null);
        setFooterRepliesForResults([
          {
            key: 'budget-change-period',
            label: t('rightPanel.ai.followUps.changePeriod'),
            onClick: () => {
              pushUserMessage(t('rightPanel.ai.followUps.changePeriod'));
              startGuidedPeriodStep('projectBudget');
            },
          },
          {
            key: 'budget-show-more',
            label: t('rightPanel.ai.followUps.showMore'),
            onClick: () => {
              pushUserMessage(t('rightPanel.ai.followUps.showMore'));
              void withLoading(() => runProjectBudgetQuery(range, filters, limit + 5));
            },
          },
          {
            key: 'budget-open',
            label: t('rightPanel.ai.followUps.openPage'),
            onClick: () => navigate(`/planning?from=${range.from}&to=${range.to}`),
          },
        ]);
      } catch (e: unknown) {
        const message = e instanceof Error ? e.message : t('rightPanel.ai.errorFallback');
        setError(message);
        showError(message);
        pushAssistantText(`${t('aiTimesheet.previewErrors.generic.title')}\n${message}`);
      }
    },
    [
      navigate,
      pushAssistantText,
      pushUserMessage,
      setFooterRepliesForResults,
      showError,
      startGuidedPeriodStep,
      t,
      tenantContext,
      withLoading,
    ]
  );

  const runPlanningQuery = useCallback(
    async (range: FlaggedRange, filters?: OptionalFilters, limit = 5) => {
      const hasFilters = Boolean(filters?.project || filters?.technician);
      const lines = [
        t('rightPanel.ai.planningResults.title', range),
        t('rightPanel.ai.planningResults.unsupported'),
      ];
      if (hasFilters) {
        lines.push(t('rightPanel.ai.planningResults.filtersNotice'));
      }
      pushAssistantText(lines.join('\n'));
      setGuidedState(null);
      setFooterRepliesForResults([
        {
          key: 'planning-change-domain',
          label: t('rightPanel.ai.followUps.changeDomain'),
          onClick: () => {
            pushUserMessage(t('rightPanel.ai.followUps.changeDomain'));
            startGuidedDomainStep(range);
          },
        },
        {
          key: 'planning-change-period',
          label: t('rightPanel.ai.followUps.changePeriod'),
          onClick: () => {
            pushUserMessage(t('rightPanel.ai.followUps.changePeriod'));
            startGuidedPeriodStep('flagged', 'planning');
          },
        },
        {
          key: 'planning-show-more',
          label: t('rightPanel.ai.followUps.showMore'),
          onClick: () => {
            pushUserMessage(t('rightPanel.ai.followUps.showMore'));
            void withLoading(() => runPlanningQuery(range, filters, limit + 5));
          },
        },
        {
          key: 'planning-open',
          label: t('rightPanel.ai.followUps.openPage'),
          onClick: () => navigate(`/planning?from=${range.from}&to=${range.to}`),
        },
      ]);
    },
    [
      navigate,
      pushAssistantText,
      pushUserMessage,
      setFooterRepliesForResults,
      startGuidedDomainStep,
      startGuidedPeriodStep,
      t,
      withLoading,
    ]
  );

  const runFlaggedQuery = useCallback(
    async (domain: FlaggedDomain, range: FlaggedRange, filters?: OptionalFilters, limit = 5) => {
      try {
        if (domain === 'projects') {
          await runProjectBudgetQuery(range, filters, limit);
          return;
        }

        if (domain === 'planning') {
          await runPlanningQuery(range, filters, limit);
          return;
        }

        const domainLabel = getFlaggedDomainLabel(domain);
        let count = 0;
        let examples: string[] = [];

        if (domain === 'timesheets') {
          // Reuse TimesheetCalendar data source; ai_flagged lives on timesheet rows.
          const response = await timesheetsApi.getAll({
            start_date: range.from,
            end_date: range.to,
          });
          const timesheets = normalizeArrayResponse<Timesheet>(response).filter((ts) =>
            isDateWithinRange(ts.date, range)
          );

          const totals = new Map<string, { hours: number; ids: number[] }>();
          timesheets.forEach((ts) => {
            if (!ts.technician_id || !ts.date) return;
            const key = `${ts.technician_id}-${ts.date}`;
            const entry = totals.get(key) ?? { hours: 0, ids: [] };
            entry.hours += normalizeHours(ts.hours_worked);
            entry.ids.push(ts.id);
            totals.set(key, entry);
          });

          const overCapIds = new Set<number>();
          totals.forEach(({ hours, ids }) => {
            if (hours > DAILY_HOUR_CAP) {
              ids.forEach((id) => overCapIds.add(id));
            }
          });

          const scopedTimesheets = timesheets.filter((ts) =>
            matchesFilterValue(ts.project?.name, filters?.project) &&
            matchesFilterValue(ts.technician?.name, filters?.technician)
          );

          const flagged = scopedTimesheets.filter((ts) => Boolean(ts.ai_flagged) || overCapIds.has(ts.id));
          const drafts = scopedTimesheets.filter((ts) => ts.status === 'draft');
          count = flagged.length;
          examples = flagged.slice(0, limit).map((ts) =>
            t('rightPanel.ai.flaggedResults.example.timesheet', {
              date: formatDateLabel(ts.date),
              technician: ts.technician?.name ?? t('common.unknown'),
              project: ts.project?.name ?? t('common.unknown'),
              reason: Array.isArray(ts.ai_feedback) && ts.ai_feedback.length > 0
                ? ts.ai_feedback[0]
                : overCapIds.has(ts.id)
                  ? t('rightPanel.ai.flaggedResults.reasonOvercap', { cap: DAILY_HOUR_CAP })
                : t('rightPanel.ai.flaggedResults.reasonFallback'),
            })
          );
          if (count === 0 && drafts.length > 0) {
            examples = [
              t('rightPanel.ai.flaggedResults.draftsHint', { count: drafts.length }),
              ...examples,
            ];
          }
        }

        if (domain === 'expenses') {
          // Reuse ExpenseManager feed; pending-like statuses already drive approvals UI.
          const response = await expensesApi.getAll({
            start_date: range.from,
            end_date: range.to,
          });
          const expenses = normalizeArrayResponse<Expense>(response).filter((expense) =>
            isDateWithinRange(expense.date, range)
          );
          const scopedExpenses = expenses.filter((expense) =>
            matchesFilterValue(expense.project?.name, filters?.project) &&
            matchesFilterValue(expense.technician?.name, filters?.technician)
          );
          const flagged = scopedExpenses.filter((expense) =>
            ['submitted', 'finance_review', 'rejected'].includes(expense.status)
          );
          count = flagged.length;
          examples = flagged.slice(0, limit).map((expense) =>
            t('rightPanel.ai.flaggedResults.example.expense', {
              date: formatDateLabel(expense.date),
              technician: expense.technician?.name ?? t('common.unknown'),
              project: expense.project?.name ?? t('common.unknown'),
              status: resolveExpenseStatusLabel(expense.status),
              amount: formatTenantMoney(Number(expense.amount || 0), tenantContext),
              title: expense.description || expense.category || t('common.unknown'),
            })
          );
        }

        if (domain === 'approvals') {
          // Reuse manager timesheet view + pending expenses endpoint used by approvals UI.
          const [managerView, pendingExpensesResponse] = await Promise.all([
            timesheetsApi.getManagerView({
              date_from: range.from,
              date_to: range.to,
              status: 'submitted',
            }),
            fetchWithAuth(`${API_URL}/api/expenses/pending`),
          ]);

          const pendingTimesheets = (managerView?.data ?? []).filter((row: TimesheetManagerRow) =>
            isDateWithinRange(row.date, range)
          ).filter((row) =>
            matchesFilterValue(row.project?.name ?? row.project_name, filters?.project) &&
            matchesFilterValue(row.technician?.name, filters?.technician)
          );

          let pendingExpenses: Expense[] = [];
          if (pendingExpensesResponse.ok) {
            const payload = await pendingExpensesResponse.json();
            pendingExpenses = normalizeArrayResponse<Expense>(payload)
              .filter((expense) => isDateWithinRange(expense.date, range))
              .filter((expense) =>
                matchesFilterValue(expense.project?.name, filters?.project) &&
                matchesFilterValue(expense.technician?.name, filters?.technician)
              );
          } else if (pendingExpensesResponse.status !== 404) {
            throw new Error(t('rightPanel.ai.errorFallback'));
          }

          count = pendingTimesheets.length + pendingExpenses.length;

          const timesheetExamples = pendingTimesheets.slice(0, limit).map((row) =>
            t('rightPanel.ai.flaggedResults.example.approvalTimesheet', {
              date: formatDateLabel(row.date),
              technician: row.technician?.name ?? t('common.unknown'),
              project: row.project?.name ?? t('common.unknown'),
            })
          );
          const remaining = Math.max(0, limit - timesheetExamples.length);
          const expenseExamples = pendingExpenses.slice(0, remaining).map((expense) =>
            t('rightPanel.ai.flaggedResults.example.approvalExpense', {
              date: formatDateLabel(expense.date),
              technician: expense.technician?.name ?? t('common.unknown'),
              project: expense.project?.name ?? t('common.unknown'),
              status: resolveExpenseStatusLabel(expense.status),
            })
          );

          examples = [...timesheetExamples, ...expenseExamples];
        }

        const ctaRoute =
          domain === 'approvals'
            ? `/approvals?from=${range.from}&to=${range.to}`
            : domain === 'expenses'
              ? `/expenses?from=${range.from}&to=${range.to}&status=pending`
              : `/timesheets?from=${range.from}&to=${range.to}&validation=ai_flagged`;
        const lines = [
          t('rightPanel.ai.flaggedResults.title', {
            domain: domainLabel,
            from: range.from,
            to: range.to,
          }),
          count > 0
            ? t('rightPanel.ai.flaggedResults.count', { count })
            : t('rightPanel.ai.flaggedResults.none', { domain: domainLabel }),
        ];
        if (examples.length > 0) {
          lines.push(t('rightPanel.ai.flaggedResults.examplesTitle'));
          examples.forEach((example) => lines.push(`- ${example}`));
        }
        pushAssistantText(lines.join('\n'));
        setGuidedState(null);
        setFooterRepliesForResults([
          {
            key: 'flagged-change-domain',
            label: t('rightPanel.ai.followUps.changeDomain'),
            onClick: () => {
              pushUserMessage(t('rightPanel.ai.followUps.changeDomain'));
              startGuidedDomainStep(range);
            },
          },
          {
            key: 'flagged-change-period',
            label: t('rightPanel.ai.followUps.changePeriod'),
            onClick: () => {
              pushUserMessage(t('rightPanel.ai.followUps.changePeriod'));
              startGuidedPeriodStep('flagged', domain);
            },
          },
          {
            key: 'flagged-show-more',
            label: t('rightPanel.ai.followUps.showMore'),
            onClick: () => {
              pushUserMessage(t('rightPanel.ai.followUps.showMore'));
              void withLoading(() => runFlaggedQuery(domain, range, filters, limit + 5));
            },
          },
          {
            key: 'flagged-open',
            label: t('rightPanel.ai.followUps.openPage'),
            onClick: () => navigate(ctaRoute),
          },
        ]);
      } catch (e: unknown) {
        const message = e instanceof Error ? e.message : t('rightPanel.ai.errorFallback');
        setError(message);
        showError(message);
        pushAssistantText(`${t('aiTimesheet.previewErrors.generic.title')}\n${message}`);
      }
    },
    [
      expenseStatusLabels,
      formatDateLabel,
      getFlaggedDomainLabel,
      navigate,
      pushAssistantText,
      pushUserMessage,
      setFooterRepliesForResults,
      resolveExpenseStatusLabel,
      runPlanningQuery,
      runProjectBudgetQuery,
      showError,
      startGuidedDomainStep,
      startGuidedPeriodStep,
      t,
      tenantContext,
      withLoading,
    ]
  );

  const executeGuidedIntent = useCallback(
    async (intent: GuidedIntent, filters?: OptionalFilters, limit?: number) => {
      if (intent.kind === 'flagged') {
        await runFlaggedQuery(intent.domain, intent.range, filters, limit);
        return;
      }
      if (intent.kind === 'overtime') {
        await runOvertimeQuery(intent.range, filters, limit);
        return;
      }
      await runProjectBudgetQuery(intent.range, filters, limit);
    },
    [runFlaggedQuery, runOvertimeQuery, runProjectBudgetQuery]
  );

  const canSend = useMemo(
    () => question.trim().length > 0 && !loading && aiState === 'enabled',
    [question, loading, aiState]
  );

  const handleSend = async () => {
    // Manual verification (DevTools):
    // Example prompt (builder-style):
    // DATE_RANGE=2026-02-10..2026-02-14
    // Projeto: "Mobile Banking App"
    // Bloco 1: 09:00-13:00
    // Bloco 2: 14:00-18:00
    // 2) Expect POST http://api.localhost/api/ai/timesheet/preview (200 JSON) with Authorization + X-Tenant headers.
    // 3) Preview card appears with summary + Confirm & Create button.
    // 4) Click Confirm & Create; expect POST /api/ai/timesheet/commit (200 JSON) and calendar refresh.
    const q = question.trim();
    if (!q || loading || aiState !== 'enabled') return;

    setError(null);
    setShowExamples(false);
    setQuestion('');
    setPreview(null);
    setPreviewPlan(null);
    setFooterQuickReplies([]);
    setMessages((prev) => [...prev, { role: 'user', content: q }]);

    try {
      if (pendingGuided && pendingFilterType) {
        const extracted = extractFilterValue(q, pendingFilterType);
        const fallbackValue = q.trim();
        const filterValue = extracted || fallbackValue;
        const normalizedFilter = normalizeMatchValue(filterValue).replace(/[:=]/g, '').trim();
        const isEmptyFilter =
          !normalizedFilter ||
          normalizedFilter === 'project' ||
          normalizedFilter === 'projeto' ||
          normalizedFilter === 'technician' ||
          normalizedFilter === 'tecnico';
        setPendingGuided(null);
        setPendingFilterType(null);
        const filters: OptionalFilters | undefined = isEmptyFilter
          ? undefined
          : pendingFilterType === 'project'
            ? { project: filterValue }
            : { technician: filterValue };
        await withLoading(() => executeGuidedIntent(pendingGuided, filters));
        return;
      }

      if (guidedState?.step === 'domain') {
        const domain = parseFlaggedDomain(q);
        if (domain) {
          handleDomainSelection(domain, guidedState.range ?? null);
          return;
        }
        startGuidedDomainStep(guidedState.range ?? null);
        return;
      }

      if (guidedState?.step === 'period') {
        const range = parseFlaggedRange(q);
        if (range) {
          if (guidedState.intent === 'flagged') {
            if (!guidedState.domain) {
              startGuidedDomainStep(range);
              return;
            }
            startGuidedFilterStep({ kind: 'flagged', domain: guidedState.domain, range });
            return;
          }
          if (guidedState.intent === 'overtime') {
            startGuidedFilterStep({ kind: 'overtime', range });
            return;
          }
          startGuidedFilterStep({ kind: 'projectBudget', range });
          return;
        }
        startGuidedPeriodStep(guidedState.intent, guidedState.domain);
        pushAssistantText(t('rightPanel.ai.guidedFlagged.customHelp'));
        return;
      }

      if (isFlaggedIntent(q)) {
        const flaggedDomain = parseFlaggedDomain(q);
        const flaggedRange = parseFlaggedRange(q);
        const filters: OptionalFilters = {
          project: extractFilterValue(q, 'project') ?? undefined,
          technician: extractFilterValue(q, 'technician') ?? undefined,
        };

        if (flaggedDomain && flaggedRange) {
          setShowExamples(false);
          await withLoading(() => runFlaggedQuery(flaggedDomain, flaggedRange, filters));
          return;
        }

        setShowExamples(true);

        if (flaggedDomain && !flaggedRange) {
          startGuidedPeriodStep('flagged', flaggedDomain);
          return;
        }

        if (!flaggedDomain && flaggedRange) {
          startGuidedDomainStep(flaggedRange);
          return;
        }

        startGuidedDomainStep();
        return;
      }

      if (isOvertimeIntent(q)) {
        const overtimeRange = parseFlaggedRange(q);
        const filters: OptionalFilters = {
          project: extractFilterValue(q, 'project') ?? undefined,
          technician: extractFilterValue(q, 'technician') ?? undefined,
        };
        if (overtimeRange) {
          await withLoading(() => runOvertimeQuery(overtimeRange, filters));
          return;
        }

        setShowExamples(true);
        startGuidedPeriodStep('overtime');
        return;
      }

      if (isProjectBudgetIntent(q)) {
        const budgetRange = parseFlaggedRange(q);
        const filters: OptionalFilters = {
          project: extractFilterValue(q, 'project') ?? undefined,
        };
        if (budgetRange) {
          await withLoading(() => runProjectBudgetQuery(budgetRange, filters));
          return;
        }

        setShowExamples(true);
        startGuidedPeriodStep('projectBudget');
        return;
      }

      if (looksLikeTimesheetBuilder(q)) {
        // Bypass insight chat for structured builder prompts so labels/time blocks stay intact.
        logAiTimesheet('timesheet builder prompt detected');
        const timezone = tenantContext?.timezone || Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
        const previewPayload = { prompt: q, timezone };
        logAiTimesheet('preview payload', previewPayload);
        const response = await withLoading(() => previewTimesheetPlan(previewPayload.prompt, previewPayload.timezone));
        setPreview(response);
        setPreviewPlan(response.plan);

        setMessages((prev) => [
          ...prev,
          {
            role: 'assistant',
            content: (
              <AiAssistantMessage severity="info" title={t('aiTimesheet.previewSummary.title')}>
                <AiTimesheetPreviewDetails plan={response.plan} />
              </AiAssistantMessage>
            ),
          },
        ]);

        if (response.warnings?.length) {
          response.warnings.forEach((warning) => {
            const mapped = mapAiTimesheetPreviewError({ message: warning, t });
            setMessages((prev) => [
              ...prev,
              {
                role: 'assistant',
                content: (
                  <AiAssistantMessage
                    severity="warning"
                    title={`⚠️ ${mapped.title}`}
                    message={mapped.message}
                    actions={mapped.actions}
                  />
                ),
              },
            ]);
          });
        }
      } else {
        logAiTimesheet('fallback to insights chat');
        await withLoading(() => new Promise<void>((resolve) => setTimeout(resolve, 600)));
        pushAssistantText(
          `${t('rightPanel.ai.unsupported.title')}\n${t('rightPanel.ai.unsupported.message')}`
        );
        setFooterQuickReplies(
          suggestionChips.map((chip) => ({
            key: `suggest-${chip}`,
            label: chip,
            onClick: () => {
              setQuestion(chip);
              focusInput();
            },
          }))
        );
      }
    } catch (e: unknown) {
      const responseStatus = (e as { response?: { status?: number } })?.response?.status;
      const responseData = (e as { response?: { data?: { message?: string; missing_fields?: string[] } } })?.response
        ?.data;
      logAiTimesheet('preview error', { status: responseStatus, data: responseData });
      const message = responseData?.message || (e instanceof Error ? e.message : t('rightPanel.ai.errorFallback'));

      if (responseStatus === 422) {
        const mapped = mapAiTimesheetPreviewError({ message, t });
        const missingFields = mapAiTimesheetMissingFields(responseData?.missing_fields ?? [], t);
        setMessages((prev) => [
          ...prev,
          {
            role: 'assistant',
            content: (
              <AiAssistantMessage
                severity={mapped.severity === 'warning' ? 'warning' : 'error'}
                title={mapped.title}
                message={mapped.message}
                actions={mapped.actions}
              />
            ),
          },
        ]);

        if (missingFields.length > 0) {
          setMessages((prev) => [
            ...prev,
            {
              role: 'assistant',
              content: (
                <AiAssistantMessage
                  severity="info"
                  title={t('aiTimesheet.missingFields.title')}
                  message={t('aiTimesheet.missingFields.message', { fields: missingFields.join(', ') })}
                />
              ),
            },
          ]);
        }
      } else {
        setMessages((prev) => [
          ...prev,
          {
            role: 'assistant',
            content: (
              <AiAssistantMessage
                severity="error"
                title={t('aiTimesheet.previewErrors.generic.title')}
                message={message}
              />
            ),
          },
        ]);
      }
    }
  };

  const handleApply = async () => {
    if (!previewPlan || applyLoading || !preview) return;

    setApplyLoading(true);
    setError(null);

    try {
      const clientRequestId = crypto?.randomUUID ? crypto.randomUUID() : `${Date.now()}-${Math.random()}`;
      const response = await commitTimesheetPlan(previewPlan, clientRequestId);

      showSuccess(`Created ${response.summary.created_count} entries as drafts.`);
      setPreview(null);
      setPreviewPlan(null);
      window.dispatchEvent(new Event('timesheets:refresh'));
    } catch (e: unknown) {
      const responseStatus = (e as { response?: { status?: number } })?.response?.status;
      const responseMessage = (e as { response?: { data?: { message?: string; errors?: string[] } } })?.response?.data;
      const message =
        responseMessage?.message ||
        (responseMessage?.errors?.length ? responseMessage.errors[0] : null) ||
        (e instanceof Error ? e.message : t('rightPanel.ai.errorFallback'));
      const finalMessage = responseStatus === 409 ? message : message;
      setError(finalMessage);
      showError(finalMessage);
    } finally {
      setApplyLoading(false);
    }
  };

  return (
    <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column', gap: 2 }}>
      <Box>
        <Typography variant="h6" sx={{ fontWeight: 800, display: 'flex', alignItems: 'center', gap: 1 }}>
          <SmartToyIcon fontSize="small" /> {t('rightPanel.ai.title')}
        </Typography>
        <Typography variant="body2" color="text.secondary">
          {t('rightPanel.ai.subtitle')}
        </Typography>
      </Box>

      {aiState === 'available_as_addon' ? (
        <Alert
          severity="warning"
          variant="outlined"
          action={
            <Button variant="outlined" size="small" onClick={() => void openCheckoutForAddon('ai')} sx={{ textTransform: 'none' }}>
              {t('rightPanel.ai.viewBilling')}
            </Button>
          }
        >
          {t('rightPanel.ai.addonNotice')}
        </Alert>
      ) : null}

      {aiState === 'disabled_by_tenant' ? (
        <Alert
          severity="info"
          variant="outlined"
          action={
            <Button variant="outlined" size="small" onClick={() => navigate('/billing')} sx={{ textTransform: 'none' }}>
              {t('rightPanel.ai.billingSettings')}
            </Button>
          }
        >
          <AlertTitle>{t('rightPanel.ai.disabledTitle')}</AlertTitle>
          {t('rightPanel.ai.disabledMessage')}
        </Alert>
      ) : null}

      {aiState === 'not_available' ? (
        <Alert severity="info" variant="outlined">
          {t('rightPanel.ai.unavailable')}
        </Alert>
      ) : null}

      {aiState === 'enabled' ? (
        <Alert severity="success" variant="outlined">
          {t('rightPanel.ai.enabledNotice')}
        </Alert>
      ) : null}

      {error ? (
        <Alert severity="error" variant="outlined">
          {t('rightPanel.ai.errorLabel', { message: error })}
        </Alert>
      ) : null}

      {/* Chat surface */}
      <Box
        sx={{
          flexGrow: 1,
          minHeight: 0,
          display: 'flex',
          flexDirection: 'column',
          border: '1px solid',
          borderColor: 'divider',
          borderRadius: 2,
          backgroundColor: 'background.paper',
        }}
      >
        {/* Transcript */}
        <Box
          ref={transcriptRef}
          onScroll={handleTranscriptScroll}
          sx={{
            flexGrow: 1,
            overflowY: 'auto',
            minHeight: 0,
            px: 2,
            py: 1.5,
            display: 'flex',
            flexDirection: 'column',
            justifyContent: 'flex-end',
          }}
        >
          {messages.length > 0 ? (
            <Stack spacing={1.5}>
              {messages.map((m, idx) => (
                <Box key={idx}>
                  <Typography variant="caption" color="text.secondary">
                    {m.role === 'user' ? t('rightPanel.ai.you') : t('rightPanel.ai.assistant')}
                  </Typography>
                  {typeof m.content === 'string' ? (
                    <Typography variant="body2" sx={{ whiteSpace: 'pre-wrap' }}>
                      {m.content}
                    </Typography>
                  ) : (
                    m.content
                  )}
                </Box>
              ))}
            </Stack>
          ) : (
            <Stack spacing={1.5}>
              <Typography variant="body2" color="text.secondary">
                {t('rightPanel.ai.placeholder')}
              </Typography>
            </Stack>
          )}
          <Box ref={bottomRef} />
        </Box>

        {/* Footer controls (inside chat surface) */}
        <Box
          sx={{
            px: 2,
            pt: 1.25,
            pb: 2,
            borderTop: '1px solid',
            borderColor: 'divider',
          }}
        >
          <Stack spacing={1.5}>
            {footerQuickReplies.length > 0 ? (
              <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
                {footerQuickReplies.map((reply) => (
                  <Chip
                    key={reply.key}
                    label={reply.label}
                    size="small"
                    variant="outlined"
                    onClick={reply.onClick}
                  />
                ))}
              </Stack>
            ) : null}

            {aiState === 'enabled' ? (
              <Stack spacing={1}>
                <Typography variant="caption" color="text.secondary">
                  {t('rightPanel.ai.examples.title')}
                </Typography>
                {showExamples ? (
                  <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
                    {expandedPrompts.map((prompt) => (
                      <Chip
                        key={prompt}
                        label={prompt}
                        size="small"
                        variant="outlined"
                        onClick={() => {
                          setQuestion(prompt);
                          inputRef.current?.focus();
                        }}
                      />
                    ))}
                  </Stack>
                ) : (
                  <Stack direction="row" spacing={1} sx={{ flexWrap: 'wrap' }}>
                    {collapsedPrompts.map((prompt) => (
                      <Chip
                        key={prompt}
                        label={prompt}
                        size="small"
                        variant="outlined"
                        onClick={() => {
                          setQuestion(prompt);
                          inputRef.current?.focus();
                        }}
                      />
                    ))}
                  </Stack>
                )}
                <Button
                  size="small"
                  variant="text"
                  onClick={() => setShowExamples((prev) => !prev)}
                  sx={{ alignSelf: 'flex-start', textTransform: 'none', px: 0 }}
                >
                  {showExamples ? t('rightPanel.ai.examples.less') : t('rightPanel.ai.examples.more')}
                </Button>
              </Stack>
            ) : null}

            {preview && previewPlan ? (
              <Button variant="contained" onClick={() => void handleApply()} disabled={applyLoading}>
                {applyLoading ? t('common.saving') : t('aiTimesheet.confirmCreate')}
              </Button>
            ) : null}

            <Stack spacing={1.5}>
              <TextField
                label={t('rightPanel.ai.inputLabel')}
                placeholder={t('rightPanel.ai.inputPlaceholder')}
                value={question}
                inputRef={inputRef}
                onChange={(e) => setQuestion(e.target.value)}
                onKeyDown={(e) => {
                  if (e.key === 'Enter' && !e.shiftKey) {
                    e.preventDefault();
                    void handleSend();
                  }
                }}
                multiline
                minRows={2}
                maxRows={5}
                disabled={aiState !== 'enabled' || loading}
              />

              <Button variant="contained" onClick={() => void handleSend()} disabled={!canSend}>
                {loading ? (
                  <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                    <CircularProgress size={18} color="inherit" />
                    <span>{t('rightPanel.ai.sending')}</span>
                  </Box>
                ) : (
                  t('rightPanel.ai.send')
                )}
              </Button>
            </Stack>
          </Stack>
        </Box>
      </Box>
    </Box>
  );
};
