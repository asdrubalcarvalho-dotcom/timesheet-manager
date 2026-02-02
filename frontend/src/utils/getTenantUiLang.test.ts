import { afterEach, describe, expect, it } from 'vitest';
import type { TenantContext } from '../types/tenant';
import { getTenantUiLang, UI_LANG_STORAGE_KEY } from './getTenantUiLang';

const ctx = (overrides: Partial<TenantContext> = {}): TenantContext => ({
  region: 'EU',
  locale: 'en-GB',
  ui_locale: undefined,
  ...overrides
});

const setNavigatorLanguage = (value: string) => {
  Object.defineProperty(navigator, 'language', {
    value,
    configurable: true
  });
};

const originalNavigatorLanguage = navigator.language;

afterEach(() => {
  localStorage.removeItem(UI_LANG_STORAGE_KEY);
  setNavigatorLanguage(originalNavigatorLanguage);
});

describe('getTenantUiLang', () => {
  it('prefers stored language override', () => {
    localStorage.setItem(UI_LANG_STORAGE_KEY, 'pt-PT');
    expect(getTenantUiLang(ctx({ ui_locale: 'en-GB' }))).toBe('pt-PT');
  });

  it('uses tenant ui_locale when no stored override', () => {
    expect(getTenantUiLang(ctx({ ui_locale: 'en-GB' }))).toBe('en-GB');
  });

  it('maps tenant locale to supported language', () => {
    expect(getTenantUiLang(ctx({ locale: 'pt-BR', ui_locale: undefined }))).toBe('pt-PT');
  });

  it('falls back to region defaults', () => {
    expect(getTenantUiLang(ctx({ locale: undefined, ui_locale: undefined, region: 'EU' }))).toBe('en-GB');
    expect(getTenantUiLang(ctx({ locale: undefined, ui_locale: undefined, region: 'US' }))).toBe('en-US');
  });

  it('falls back to browser language when tenant has none', () => {
    setNavigatorLanguage('pt-PT');
    expect(getTenantUiLang(ctx({ locale: undefined, ui_locale: undefined, region: undefined }))).toBe('pt-PT');
  });

  it('defaults to en-US as a last resort', () => {
    setNavigatorLanguage('xx-YY');
    expect(getTenantUiLang(ctx({ locale: undefined, ui_locale: undefined, region: undefined }))).toBe('en-US');
  });
});
