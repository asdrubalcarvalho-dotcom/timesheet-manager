import i18n from 'i18next';
import { initReactI18next } from 'react-i18next';

import enCommon from './locales/en/common.json';
import ptCommon from './locales/pt/common.json';

export const normalizeLang = (lang: unknown): 'en' | 'pt' => {
  const normalized = String(lang ?? '').trim().toLowerCase();
  return normalized === 'pt' ? 'pt' : 'en';
};

export const setLanguage = async (lang: unknown): Promise<void> => {
  await i18n.changeLanguage(normalizeLang(lang));
};

i18n.use(initReactI18next).init({
  resources: {
    en: { common: enCommon },
    pt: { common: ptCommon },
  },
  // Default language is always EN; tenant bootstrap will call setLanguage().
  // Resolution order: tenant.ui_language -> default 'en'.
  lng: 'en',
  fallbackLng: 'en',
  defaultNS: 'common',
  ns: ['common'],
  interpolation: {
    escapeValue: false,
  },
  returnEmptyString: false,
});

export default i18n;
