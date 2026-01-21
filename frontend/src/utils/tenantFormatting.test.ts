import { describe, expect, it } from 'vitest';
import type { TenantContext } from '../components/Auth/AuthContext';
import { getTenantUiLocale } from './tenantFormatting';

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
