import { useMemo } from 'react';
import { useTranslation } from 'react-i18next';
import type { GridLocaleText } from '@mui/x-data-grid';

const useDataGridLocaleText = (): Partial<GridLocaleText> => {
  const { t, i18n } = useTranslation();

  return useMemo(
    () => ({
      MuiTablePagination: {
        labelRowsPerPage: t('common.rowsPerPage'),
      },
    }),
    [i18n.language, i18n.resolvedLanguage, t]
  );
};

export default useDataGridLocaleText;
