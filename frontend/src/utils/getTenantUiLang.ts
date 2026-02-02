import type { TenantContext } from '../types/tenant';

export const UI_LANG_STORAGE_KEY = 'ui_lang';

export const SUPPORTED_UI_LANGS = ['en-US', 'en-GB', 'pt-PT'] as const;
export type UiLanguage = (typeof SUPPORTED_UI_LANGS)[number];

const normalizeRaw = (value: unknown): string =>
  String(value ?? '').trim().toLowerCase().replace('_', '-');

export const normalizeUiLanguage = (value: unknown): UiLanguage | null => {
  const normalized = normalizeRaw(value);
  if (!normalized) return null;

  if (normalized === 'en' || normalized.startsWith('en-us')) return 'en-US';
  if (normalized === 'en-gb' || normalized === 'en-uk' || normalized.startsWith('en-gb')) return 'en-GB';
  if (normalized === 'pt' || normalized.startsWith('pt')) return 'pt-PT';

  return null;
};

const normalizeRegionDefault = (region: unknown): UiLanguage | null => {
  const normalized = normalizeRaw(region);
  if (!normalized) return null;

  if (normalized === 'us' || normalized === 'na' || normalized === 'usa') return 'en-US';
  if (normalized === 'uk' || normalized === 'gb' || normalized === 'eu') return 'en-GB';
  if (normalized === 'pt') return 'pt-PT';

  return null;
};

const getNavigatorLanguage = (): string | null => {
  if (typeof navigator === 'undefined') return null;
  return navigator.languages?.[0] ?? navigator.language ?? null;
};

export const getStoredUiLanguage = (): UiLanguage | null => {
  if (typeof window === 'undefined') return null;
  return normalizeUiLanguage(window.localStorage.getItem(UI_LANG_STORAGE_KEY));
};

export const resolveUiLanguage = (tenant?: TenantContext | null): UiLanguage => {
  const stored = getStoredUiLanguage();
  if (stored) return stored;

  const tenantOverride = normalizeUiLanguage(tenant?.ui_locale);
  if (tenantOverride) return tenantOverride;

  const tenantLocale = normalizeUiLanguage(tenant?.locale);
  if (tenantLocale) return tenantLocale;

  const regionDefault = normalizeRegionDefault(tenant?.region);
  if (regionDefault) return regionDefault;

  const navigatorDefault = normalizeUiLanguage(getNavigatorLanguage());
  if (navigatorDefault) return navigatorDefault;

  return 'en-US';
};

export const getTenantUiLang = (tenant?: TenantContext | null): UiLanguage => resolveUiLanguage(tenant);
