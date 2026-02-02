import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import enUsCommon from './locales/en-US/common.json';
import enGbCommon from './locales/en-GB/common.json';
import ptPtCommon from './locales/pt-PT/common.json';
import {
  normalizeUiLanguage,
  resolveUiLanguage,
  SUPPORTED_UI_LANGS,
  type UiLanguage
} from './utils/getTenantUiLang';

export const normalizeLang = (lang: unknown): UiLanguage =>
  normalizeUiLanguage(lang) ?? 'en-US';

export const setLanguage = async (lang: unknown): Promise<void> => {
  await i18n.changeLanguage(normalizeLang(lang));
};

i18n.use(initReactI18next).init({
  resources: {
    'en-US': { common: enUsCommon },
    'en-GB': { common: enGbCommon },
    'pt-PT': { common: ptPtCommon },
  },
  supportedLngs: [...SUPPORTED_UI_LANGS],
  // Default language is resolved from localStorage + browser; tenant bootstrap can override.
  lng: resolveUiLanguage(),
  fallbackLng: 'en-US',
  defaultNS: 'common',
  ns: ['common'],
  interpolation: {
    escapeValue: false,
  },
  returnEmptyString: false,
});

export default i18n;
