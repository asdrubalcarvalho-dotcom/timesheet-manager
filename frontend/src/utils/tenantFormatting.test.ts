import { describe, expect, it } from 'vitest';
import dayjs from 'dayjs';
import type { TenantContext } from '../components/Auth/AuthContext';
import { formatTenantTime, getTenantHourCycle, getTenantTimeFormat, getTenantUiLocale } from './tenantFormatting';

const ctx = (overrides: Partial<TenantContext> = {}): TenantContext => ({
  region: 'EU',
  timezone: 'UTC',
  locale: 'pt-PT',
  date_format: 'd/m/Y',
  currency: 'EUR',
  currency_symbol: '€',
  ...overrides,
});

describe('getTenantUiLocale', () => {
  it('EU + locale pt-PT + no ui_locale => "en"', () => {
    expect(getTenantUiLocale(ctx({ region: 'EU', locale: 'pt-PT', ui_locale: null }))).toBe('en');
  });

  it('EU + ui_locale "pt" => "pt"', () => {
    expect(getTenantUiLocale(ctx({ region: 'EU', locale: 'pt-PT', ui_locale: 'pt' }))).toBe('pt');
  });

  it('US + locale en-US => "en"', () => {
    expect(getTenantUiLocale(ctx({ region: 'US', locale: 'en-US', date_format: 'm/d/Y', ui_locale: null }))).toBe('en');
  });
});

describe('Tenant time formatting (time-only)', () => {
  it('US tenants use 12h + hh:mm A', () => {
    const tenantContext = ctx({ region: 'US', locale: 'en-US', date_format: 'm/d/Y' });
    expect(getTenantHourCycle(tenantContext)).toBe(12);
    expect(getTenantTimeFormat(tenantContext)).toBe('hh:mm A');

    const d = dayjs('2023-01-01 13:05', 'YYYY-MM-DD HH:mm', true);
    expect(formatTenantTime(d, tenantContext)).toMatch(/01:05\s?PM/);
  });

  it('EU tenants use 24h + HH:mm', () => {
    const tenantContext = ctx({ region: 'EU', locale: 'pt-PT', date_format: 'd/m/Y' });
    expect(getTenantHourCycle(tenantContext)).toBe(24);
    expect(getTenantTimeFormat(tenantContext)).toBe('HH:mm');

    const d = dayjs('2023-01-01 13:05', 'YYYY-MM-DD HH:mm', true);
    expect(formatTenantTime(d, tenantContext)).toBe('13:05');
  });
});
