import type { TenantContext, WeekStart } from '../types/tenant';

const asOptionalString = (value: unknown): string | undefined =>
  typeof value === 'string' ? value : undefined;

const asWeekStart = (value: unknown): WeekStart | undefined => {
  if (value === 'mon' || value === 'sun') return value;
  return undefined;
};

export const normalizeTenantContext = (raw: unknown): TenantContext => {
  const source = raw && typeof raw === 'object' ? (raw as Record<string, unknown>) : {};

  return {
    region: asOptionalString(source.region),
    week_start: asWeekStart(source.week_start),
    state: asOptionalString(source.state),
    policy_key: asOptionalString(source.policy_key),
    timezone: asOptionalString(source.timezone),
    locale: asOptionalString(source.locale),
    ui_locale: asOptionalString(source.ui_locale),
    date_format: asOptionalString(source.date_format),
    currency: asOptionalString(source.currency),
    currency_symbol: asOptionalString(source.currency_symbol),
  };
};
