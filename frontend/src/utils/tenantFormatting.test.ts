import { describe, expect, it } from 'vitest';
import type { TenantContext } from '../components/Auth/AuthContext';
import { getTenantDayjsUiLocale, getTenantUiLocale, normalizeWeekStartIndex } from './tenantFormatting';

const ctx = (overrides: Partial<TenantContext> = {}): TenantContext => ({
  region: 'EU',
  timezone: 'UTC',
  locale: 'pt-PT',
  date_format: 'd/m/Y',
  currency: 'EUR',
  currency_symbol: '€',
  ...overrides,
});

describe('getTenantUiLocale / getTenantDayjsUiLocale', () => {
  it('EU + locale pt-PT + no ui_locale + EU date_format => UI locale en-GB and dayjs en', () => {
    const tenantContext = ctx({ region: 'EU', locale: 'pt-PT', date_format: 'd/m/Y', ui_locale: null });
    expect(getTenantUiLocale(tenantContext)).toBe('en-GB');
    expect(getTenantDayjsUiLocale(tenantContext)).toBe('en');
  });

  it('EU + ui_locale pt-PT => UI locale pt-PT and dayjs pt', () => {
    const tenantContext = ctx({ region: 'EU', locale: 'pt-PT', date_format: 'd/m/Y', ui_locale: 'pt-PT' });
    expect(getTenantUiLocale(tenantContext)).toBe('pt-PT');
    expect(getTenantDayjsUiLocale(tenantContext)).toBe('pt');
  });

  it('US + locale en-US => UI locale en-US and dayjs en', () => {
    const tenantContext = ctx({ region: 'US', locale: 'en-US', date_format: 'm/d/Y', ui_locale: null });
    expect(getTenantUiLocale(tenantContext)).toBe('en-US');
    expect(getTenantDayjsUiLocale(tenantContext)).toBe('en');
  });
});

describe('normalizeWeekStartIndex', () => {
  it('normalizes Sunday variants to 0', () => {
    expect(normalizeWeekStartIndex('Sunday')).toBe(0);
    expect(normalizeWeekStartIndex('sunday')).toBe(0);
    expect(normalizeWeekStartIndex('SUN')).toBe(0);
    expect(normalizeWeekStartIndex('0')).toBe(0);
    expect(normalizeWeekStartIndex(0)).toBe(0);
  });

  it('normalizes Monday variants to 1', () => {
    expect(normalizeWeekStartIndex('Monday')).toBe(1);
    expect(normalizeWeekStartIndex('mon')).toBe(1);
    expect(normalizeWeekStartIndex('1')).toBe(1);
    expect(normalizeWeekStartIndex(1)).toBe(1);
  });

  it('defaults to Monday (1) when unknown', () => {
    expect(normalizeWeekStartIndex(undefined)).toBe(1);
    expect(normalizeWeekStartIndex('')).toBe(1);
    expect(normalizeWeekStartIndex('???')).toBe(1);
  });
});
