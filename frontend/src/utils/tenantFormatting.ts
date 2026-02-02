import type { TenantContext } from '../types/tenant';
import dayjs from 'dayjs';
import customParseFormat from 'dayjs/plugin/customParseFormat';

dayjs.extend(customParseFormat);

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
  const locale = (tenantLocale || '').toLowerCase().replace('_', '-');

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

export const getTenantUiLocale = (tenantContext: TenantContext | null | undefined): string => {
  const override = (tenantContext?.ui_locale ?? '').toString().trim();
  if (override !== '') {
    return getTenantDayjsLocale(override);
  }

  const region = (tenantContext?.region ?? '').toString().trim().toUpperCase();
  if (region === 'EU') {
    return 'en';
  }

  return getTenantDayjsLocale(tenantContext?.locale);
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

// ----------------------
// Time-only (UI display)
// ----------------------

export const getTenantHourCycle = (tenantContext: TenantContext | null | undefined): 12 | 24 =>
  isUsStyle(tenantContext) ? 12 : 24;

export const getTenantTimeFormat = (tenantContext: TenantContext | null | undefined): 'hh:mm A' | 'HH:mm' =>
  getTenantHourCycle(tenantContext) === 12 ? 'hh:mm A' : 'HH:mm';

export const formatTenantTime = (
  value: dayjs.Dayjs | Date | string | null | undefined,
  tenantContext: TenantContext | null | undefined
): string => {
  if (!value) return '-';

  const format = getTenantTimeFormat(tenantContext);

  const parsed = dayjs.isDayjs(value)
    ? value
    : value instanceof Date
      ? dayjs(value)
      : dayjs(String(value).trim(), ['HH:mm', 'H:mm', 'HH:mm:ss', 'YYYY-MM-DD HH:mm:ss', 'YYYY-MM-DD HH:mm'], true);

  if (!parsed.isValid()) return '-';
  return parsed.format(format);
};
