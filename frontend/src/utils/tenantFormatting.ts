import type { TenantContext } from '../components/Auth/AuthContext';

const KM_PER_MILE = 1.609344;

const isEuStyle = (tenantContext: TenantContext | null | undefined): boolean => {
  const df = tenantContext?.date_format;
  if (df === 'd/m/Y') return true;

  const region = (tenantContext?.region || '').toUpperCase();
  if (region === 'EU') return true;

  const locale = (tenantContext?.locale || '').toLowerCase();
  return locale.startsWith('pt-') || locale.startsWith('pt_') || locale.startsWith('de-') || locale.startsWith('de_');
};

const isUsStyle = (tenantContext: TenantContext | null | undefined): boolean => {
  const region = (tenantContext?.region || '').toUpperCase();
  if (region === 'US') return true;

  const locale = (tenantContext?.locale || '').toLowerCase();
  return locale === 'en-us' || locale.startsWith('en-us') || locale.endsWith('-us') || locale.endsWith('_us');
};

const isIsoDateOnly = (value: string): boolean => /^\d{4}-\d{2}-\d{2}$/.test(value);
const isIsoYearMonth = (value: string): boolean => /^\d{4}-\d{2}$/.test(value);

export const getTenantDayjsLocale = (tenantLocale?: string | null): string => {
  const locale = (tenantLocale || '').toLowerCase().replace(/_/g, '-');

  // Deterministic mapping from tenant locale -> dayjs locale key.
  // Never rely on browser defaults (navigator.language).
  if (locale.startsWith('en')) return 'en';
  if (locale.startsWith('pt')) return 'pt';
  if (locale.startsWith('es')) return 'es';
  if (locale.startsWith('fr')) return 'fr';
  if (locale.startsWith('de')) return 'de';

  // Safe default (dayjs always supports English).
  return 'en';
};

const normalizeIetfLocaleTag = (value: string): string => {
  const raw = value.trim();
  if (raw === '') return 'en-US';

  const normalized = raw.replace(/_/g, '-');
  const [languageRaw, regionRaw] = normalized.split('-');

  const language = (languageRaw || '').toLowerCase();
  if (language === '') return 'en-US';

  // If only a language is provided, prefer a reasonable default region.
  if (!regionRaw || regionRaw.trim() === '') {
    if (language === 'en') return 'en-GB';
    if (language === 'pt') return 'pt-PT';
    return language;
  }

  const region = regionRaw.length === 2 ? regionRaw.toUpperCase() : regionRaw;
  return `${language}-${region}`;
};

/**
 * UI language locale for Intl-based labels (e.g., weekday/month names).
 * Does NOT change numeric formatting rules.
 */
export const getTenantUiLocale = (tenantContext: TenantContext | null | undefined): string => {
  const override = (tenantContext?.ui_locale ?? '').toString().trim();
  if (override !== '') {
    return normalizeIetfLocaleTag(override);
  }

  // Soft default: EU-style tenants display UI labels in English.
  if (isEuStyle(tenantContext)) {
    return 'en-GB';
  }

  return normalizeIetfLocaleTag((tenantContext?.locale ?? '').toString() || 'en-US');
};

/**
 * Dayjs locale key used by MUI AdapterDayjs (e.g., 'en', 'pt').
 */
export const getTenantDayjsUiLocale = (tenantContext: TenantContext | null | undefined): string =>
  getTenantDayjsLocale(getTenantUiLocale(tenantContext));

export const normalizeWeekStartIndex = (
  weekStart: unknown,
  fallback: 0 | 1 | 2 | 3 | 4 | 5 | 6 = 1
): 0 | 1 | 2 | 3 | 4 | 5 | 6 => {
  if (typeof weekStart === 'number' && Number.isInteger(weekStart) && weekStart >= 0 && weekStart <= 6) {
    return weekStart as 0 | 1 | 2 | 3 | 4 | 5 | 6;
  }

  const raw = (weekStart ?? '').toString().trim().toLowerCase();
  if (raw === '') return fallback;

  // Numeric strings: '0'..'6'
  if (/^[0-6]$/.test(raw)) {
    return Number(raw) as 0 | 1 | 2 | 3 | 4 | 5 | 6;
  }

  const map: Record<string, 0 | 1 | 2 | 3 | 4 | 5 | 6> = {
    sun: 0,
    sunday: 0,
    mon: 1,
    monday: 1,
    tue: 2,
    tuesday: 2,
    wed: 3,
    wednesday: 3,
    thu: 4,
    thursday: 4,
    fri: 5,
    friday: 5,
    sat: 6,
    saturday: 6,
  };

  if (raw in map) return map[raw];
  const three = raw.slice(0, 3);
  if (three in map) return map[three];

  return fallback;
};

export const getTenantWeekStartIndex = (
  tenantContext: TenantContext | null | undefined
): 0 | 1 | 2 | 3 | 4 | 5 | 6 => normalizeWeekStartIndex(tenantContext?.week_start, 1);

export const getTenantWeekStartIndexRobust = (
  tenantContext: TenantContext | null | undefined,
  fallbackWeekStart?: unknown
): 0 | 1 | 2 | 3 | 4 | 5 | 6 => {
  const tc = tenantContext as unknown as {
    week_start?: unknown;
    weekStart?: unknown;
    week_start_day?: unknown;
  };

  const rawWeekStart = tc?.week_start ?? tc?.weekStart ?? tc?.week_start_day ?? fallbackWeekStart ?? null;
  return normalizeWeekStartIndex(rawWeekStart, 1);
};

export const getTenantDatePickerFormat = (tenantContext: TenantContext | null | undefined): string =>
  isEuStyle(tenantContext) ? 'DD/MM/YYYY' : 'MM/DD/YYYY';

export const formatTenantDate = (
  value: string | Date | null | undefined,
  tenantContext: TenantContext | null | undefined
): string => {
  if (!value) return '-';

  // Avoid timezone shifting for calendar-only dates coming from APIs (YYYY-MM-DD).
  // These represent a date without a time component and must render consistently.
  if (typeof value === 'string' && isIsoDateOnly(value)) {
    const [year, month, day] = value.split('-');
    const useEu = isEuStyle(tenantContext);
    return useEu ? `${day}/${month}/${year}` : `${month}/${day}/${year}`;
  }

  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return '-';

  const locale = tenantContext?.locale || 'en-US';
  const timeZone = tenantContext?.timezone || 'UTC';

  return new Intl.DateTimeFormat(locale, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    timeZone,
  }).format(date);
};

export const formatTenantDayMonth = (
  value: string | Date | null | undefined,
  tenantContext: TenantContext | null | undefined
): string => {
  if (!value) return '-';

  const locale = tenantContext?.locale || 'en-US';

  // For date-only values, format using UTC to avoid shifting the day.
  if (typeof value === 'string' && isIsoDateOnly(value)) {
    const [y, m, d] = value.split('-').map((p) => Number(p));
    const dateUtc = new Date(Date.UTC(y, m - 1, d));
    if (Number.isNaN(dateUtc.getTime())) return '-';
    return new Intl.DateTimeFormat(locale, {
      day: '2-digit',
      month: 'short',
      timeZone: 'UTC',
    }).format(dateUtc);
  }

  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return '-';

  const timeZone = tenantContext?.timezone || 'UTC';
  return new Intl.DateTimeFormat(locale, {
    day: '2-digit',
    month: 'short',
    timeZone,
  }).format(date);
};

export const formatTenantDateTime = (
  value: string | Date | null | undefined,
  tenantContext: TenantContext | null | undefined
): string => {
  if (!value) return '-';

  const date = value instanceof Date ? value : new Date(value);
  if (Number.isNaN(date.getTime())) return '-';

  const locale = tenantContext?.locale || 'en-US';
  const timeZone = tenantContext?.timezone || 'UTC';

  return new Intl.DateTimeFormat(locale, {
    day: '2-digit',
    month: '2-digit',
    year: 'numeric',
    hour: '2-digit',
    minute: '2-digit',
    hour12: !isEuStyle(tenantContext),
    timeZone,
  }).format(date);
};

export const formatTenantMonth = (
  value: string | Date | null | undefined,
  tenantContext: TenantContext | null | undefined
): string => {
  if (!value) return '-';

  let date: Date;

  if (typeof value === 'string' && isIsoYearMonth(value)) {
    const [year, month] = value.split('-');
    const y = Number(year);
    const m = Number(month);
    if (!Number.isFinite(y) || !Number.isFinite(m) || m < 1 || m > 12) return '-';
    date = new Date(Date.UTC(y, m - 1, 1));
  } else {
    date = value instanceof Date ? value : new Date(value);
  }

  if (Number.isNaN(date.getTime())) return '-';

  const locale = tenantContext?.locale || 'en-US';
  const timeZone = tenantContext?.timezone || 'UTC';

  return new Intl.DateTimeFormat(locale, {
    month: 'short',
    year: 'numeric',
    timeZone,
  }).format(date);
};

export const formatTenantNumber = (
  value: number,
  tenantContext: TenantContext | null | undefined,
  decimals = 2
): string => {
  if (!Number.isFinite(value)) return '0';

  const useEu = isEuStyle(tenantContext);
  const decimal = useEu ? ',' : '.';
  const thousands = useEu ? '.' : ',';

  const negative = value < 0;
  const abs = Math.abs(value);

  const fixed = abs.toFixed(decimals);
  const [intPart, fracPart] = fixed.split('.');

  const withThousands = intPart.replace(/\B(?=(\d{3})+(?!\d))/g, thousands);
  const joined = decimals > 0 ? `${withThousands}${decimal}${fracPart ?? ''}` : withThousands;

  return negative ? `-${joined}` : joined;
};

export const formatTenantMoney = (
  amount: number,
  tenantContext: TenantContext | null | undefined
): string => {
  const currencySymbol = tenantContext?.currency_symbol || '$';
  const useEu = isEuStyle(tenantContext);

  const number = formatTenantNumber(amount, tenantContext, 2);
  const space = useEu ? ' ' : '';

  return `${currencySymbol}${space}${number}`;
};

export const getTenantDistanceUnit = (tenantContext: TenantContext | null | undefined): 'km' | 'mi' =>
  isUsStyle(tenantContext) ? 'mi' : 'km';

export const kmToDisplayDistance = (km: number, tenantContext: TenantContext | null | undefined): number =>
  isUsStyle(tenantContext) ? km / KM_PER_MILE : km;

export const displayDistanceToKm = (distance: number, tenantContext: TenantContext | null | undefined): number =>
  isUsStyle(tenantContext) ? distance * KM_PER_MILE : distance;

export const ratePerKmToDisplayRate = (ratePerKm: number, tenantContext: TenantContext | null | undefined): number =>
  isUsStyle(tenantContext) ? ratePerKm * KM_PER_MILE : ratePerKm;

export const displayRateToRatePerKm = (rate: number, tenantContext: TenantContext | null | undefined): number =>
  isUsStyle(tenantContext) ? rate / KM_PER_MILE : rate;

export const formatTenantDistanceKm = (
  km: number,
  tenantContext: TenantContext | null | undefined,
  decimals = 2
): string => {
  const unit = getTenantDistanceUnit(tenantContext);
  const displayValue = kmToDisplayDistance(km, tenantContext);
  return `${formatTenantNumber(displayValue, tenantContext, decimals)} ${unit}`;
};

export const formatTenantMoneyPerDistanceKm = (
  ratePerKm: number,
  tenantContext: TenantContext | null | undefined
): string => {
  const unit = getTenantDistanceUnit(tenantContext);
  const displayRate = ratePerKmToDisplayRate(ratePerKm, tenantContext);
  return `${formatTenantMoney(displayRate, tenantContext)}/${unit}`;
};
