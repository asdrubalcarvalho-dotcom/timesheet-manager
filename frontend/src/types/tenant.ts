export type WeekStart = 'mon' | 'sun';

export interface TenantContext {
  region?: string;
  week_start?: WeekStart;
  state?: string;
  policy_key?: string;
  timezone?: string;
  locale?: string;
  ui_locale?: string;
  date_format?: string;
  currency?: string;
  currency_symbol?: string;
}
