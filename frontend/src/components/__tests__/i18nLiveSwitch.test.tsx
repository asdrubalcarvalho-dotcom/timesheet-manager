import React from 'react';
import { render, screen, waitFor } from '@testing-library/react';
import { I18nextProvider, useTranslation } from 'react-i18next';
import i18n from '../../i18n';

const SampleText: React.FC = () => {
  const { t } = useTranslation();
  return <span>{t('common.save')}</span>;
};

describe('i18n live language switching', () => {
  it('updates rendered text after changeLanguage', async () => {
    await i18n.changeLanguage('en-US');

    render(
      <I18nextProvider i18n={i18n}>
        <SampleText />
      </I18nextProvider>
    );

    expect(screen.getByText('Save')).toBeInTheDocument();

    await i18n.changeLanguage('pt-PT');

    await waitFor(() => {
      expect(screen.getByText('Guardar')).toBeInTheDocument();
    });
  });
});
