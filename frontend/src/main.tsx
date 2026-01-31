import "./emotion-force-import";
import { StrictMode } from 'react';
import { createRoot } from 'react-dom/client';
import { BrowserRouter } from 'react-router-dom';
import './index.css';
import App from './App.tsx';
import './i18n';

// Force clear cache version check
const CACHE_VERSION = '2.0.1';
const currentVersion = localStorage.getItem('app_version');
if (currentVersion !== CACHE_VERSION) {
  console.log('[Cache] Clearing old version:', currentVersion, '→', CACHE_VERSION);
  localStorage.setItem('app_version', CACHE_VERSION);
  // Don't clear auth_token and tenant_slug
  const token = localStorage.getItem('auth_token');
  const tenant = localStorage.getItem('tenant_slug');
  localStorage.clear();
  if (token) localStorage.setItem('auth_token', token);
  if (tenant) localStorage.setItem('tenant_slug', tenant);
  localStorage.setItem('app_version', CACHE_VERSION);
}

createRoot(document.getElementById('root')!).render(
  <StrictMode>
    <BrowserRouter future={{ v7_relativeSplatPath: true, v7_startTransition: true }}>
      <App />
    </BrowserRouter>
  </StrictMode>,
);
