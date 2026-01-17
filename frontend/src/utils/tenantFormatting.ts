import type { TenantContext } from '../components/Auth/AuthContext';

const isEuStyle = (tenantContext: TenantContext | null | undefined): boolean => {
  const df = tenantContext?.date_format;
  if (df === 'd/m/Y') return true;

  const region = (tenantContext?.region || '').toUpperCase();
  if (region === 'EU') return true;

  const locale = (tenantContext?.locale || '').toLowerCase();
  return locale.startsWith('pt-') || locale.startsWith('pt_') || locale.startsWith('de-') || locale.startsWith('de_');
};

export const formatTenantDate = (
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
