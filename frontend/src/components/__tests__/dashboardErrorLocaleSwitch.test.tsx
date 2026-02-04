import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { I18nextProvider } from 'react-i18next';
import { MemoryRouter } from 'react-router-dom';
import { describe, it, vi, expect, beforeEach } from 'vitest';

import i18n from '../../i18n';
import Dashboard from '../Dashboard/Dashboard';
import { dashboardApi } from '../../services/api';

vi.mock('../../services/api', () => ({
  dashboardApi: {
    getStatistics: vi.fn(),
  },
}));

vi.mock('../Auth/AuthContext', () => ({
  useAuth: () => ({
    user: { name: 'Tester' },
    tenantContext: null,
  }),
}));

describe('Dashboard error i18n reactivity', () => {
  beforeEach(async () => {
    vi.mocked(dashboardApi.getStatistics).mockReset();
    await i18n.changeLanguage('en-US');
  });

  it('updates fallback error text when language changes (no reload)', async () => {
    vi.mocked(dashboardApi.getStatistics).mockRejectedValueOnce(new Error('boom'));

    render(
      <I18nextProvider i18n={i18n}>
        <MemoryRouter>
          <Dashboard />
        </MemoryRouter>
      </I18nextProvider>
    );

    await waitFor(() => {
      expect(screen.getByText('Failed to load dashboard statistics')).toBeInTheDocument();
    });

    await i18n.changeLanguage('pt-PT');

    await waitFor(() => {
      expect(screen.getByText('Falha ao carregar estatísticas do painel')).toBeInTheDocument();
    });
  });
});
