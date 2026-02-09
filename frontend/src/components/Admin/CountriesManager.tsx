import React, { useEffect, useState } from 'react';
import {
  Box,
  Button,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  TextField,
  IconButton,
  Fab,
} from '@mui/material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRenderCellParams } from '@mui/x-data-grid';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Public as PublicIcon,
} from '@mui/icons-material';
import AdminLayout from './AdminLayout';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import EmptyState from '../Common/EmptyState';
import { useNotification } from '../../contexts/NotificationContext';
import api from '../../services/api';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import { useTranslation } from 'react-i18next';

interface Country {
  id: number;
  name: string;
  iso2: string;
}

type ConfirmColor = 'error' | 'warning' | 'primary' | 'secondary' | 'info' | 'success';

interface ConfirmDialogState {
  open: boolean;
  title: string;
  message: string;
  recordDetails?: Record<string, unknown>;
  confirmText?: string;
  cancelText?: string;
  confirmColor?: ConfirmColor;
  action: () => void | Promise<void>;
}

const normalizeApiResponse = <T,>(payload: unknown): T[] => {
  if (Array.isArray(payload)) {
    return payload as T[];
  }

  if (
    payload &&
    typeof payload === 'object' &&
    Array.isArray((payload as { data?: T[] }).data)
  ) {
    return ((payload as { data?: T[] }).data ?? []) as T[];
  }

  return [];
};

const CountriesManager: React.FC = () => {
  const { t } = useTranslation();
  const { showSuccess, showError } = useNotification();
  const { isReadOnly, ensureWritable } = useReadOnlyGuard('admin-countries');

  const [countries, setCountries] = useState<Country[]>([]);
  const [loading, setLoading] = useState(true);

  const [openDialog, setOpenDialog] = useState(false);
  const [editingCountry, setEditingCountry] = useState<Country | null>(null);

  const [confirmDialog, setConfirmDialog] = useState<ConfirmDialogState>({
    open: false,
    title: '',
    message: '',
    recordDetails: undefined,
    confirmText: t('common.confirm'),
    cancelText: t('common.cancel'),
    confirmColor: 'error',
    action: () => {},
  });

  const [formData, setFormData] = useState({
    name: '',
    iso2: ''
  });

  useEffect(() => {
    fetchCountries();
  }, []);

  const fetchCountries = async () => {
    try {
      setLoading(true);
      const response = await api.get('/api/countries');
      setCountries(normalizeApiResponse<Country>(response.data));
    } catch {
      showError(t('admin.countries.notifications.loadFailed'));
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (country?: Country) => {
    if (!ensureWritable()) {
      return;
    }
    if (country) {
      setEditingCountry(country);
      setFormData({
        name: country.name,
        iso2: country.iso2
      });
    } else {
      setEditingCountry(null);
      setFormData({ name: '', iso2: '' });
    }
    setOpenDialog(true);
  };

  const handleCloseDialog = () => {
    setOpenDialog(false);
    setEditingCountry(null);
  };

  const handleSave = async (e?: React.FormEvent) => {
    if (e) {
      e.preventDefault();
    }

    if (!ensureWritable()) {
      return;
    }

    const name = formData.name.trim();
    const iso2 = formData.iso2.trim().toUpperCase();

    if (!name || iso2.length !== 2) {
      showError(t('admin.countries.validation.nameAndIso2Required'));
      return;
    }

    try {
      const payload = { name, iso2 };

      if (editingCountry) {
        await api.put(`/api/countries/${editingCountry.id}`, payload);
        showSuccess(t('admin.countries.notifications.updateSuccess'));
      } else {
        await api.post('/api/countries', payload);
        showSuccess(t('admin.countries.notifications.createSuccess'));
      }

      fetchCountries();
      handleCloseDialog();
    } catch (error: any) {
      const msg = error?.response?.data?.message || t('admin.countries.notifications.saveFailed');
      showError(msg);
    }
  };

  const attemptDelete = async (country: Country, force: boolean) => {
    const url = force ? `/api/countries/${country.id}?force=true` : `/api/countries/${country.id}`;
    await api.delete(url);
  };

  const handleDelete = async (id: number) => {
    if (!ensureWritable()) {
      return;
    }
    const country = countries.find(c => c.id === id);
    if (!country) return;

    // First attempt: normal delete
    try {
      await attemptDelete(country, false);
      showSuccess(t('admin.countries.notifications.deleteSuccess'));
      fetchCountries();
      return;
    } catch (error: any) {
      if (error?.response?.status === 409) {
        const locationsCount = error?.response?.data?.locations_count;

        setConfirmDialog({
          open: true,
          title: t('admin.countries.confirmForceDelete.title'),
          message: t('admin.countries.confirmForceDelete.message'),
          recordDetails: {
            name: country.name,
            code: country.iso2,
            locations_count: locationsCount
          },
          confirmText: t('admin.countries.confirmForceDelete.confirmText'),
          confirmColor: 'warning',
          action: async () => {
            if (!ensureWritable()) {
              return;
            }
            try {
              await attemptDelete(country, true);
              showSuccess(t('admin.countries.notifications.deleteSuccess'));
              fetchCountries();
            } catch (e: any) {
              showError(e?.response?.data?.message || t('admin.countries.notifications.deleteFailed'));
            }
            setConfirmDialog(prev => ({ ...prev, open: false }));
          }
        });
        return;
      }

      showError(error?.response?.data?.message || t('admin.countries.notifications.deleteFailed'));
    }
  };

  const columns: GridColDef[] = [
    { field: 'name', headerName: t('admin.shared.columns.name'), flex: 1, minWidth: 200 },
    { field: 'iso2', headerName: t('admin.countries.columns.iso2'), width: 120 },
    {
      field: 'actions',
      headerName: t('admin.shared.columns.actions'),
      width: 140,
      sortable: false,
      renderCell: ({ row }: GridRenderCellParams<Country>) => (
        <Box sx={{ display: 'flex', gap: 1 }}>
          <IconButton size="small" onClick={() => handleOpenDialog(row)} disabled={isReadOnly}>
            <EditIcon fontSize="small" />
          </IconButton>
          <IconButton size="small" color="error" onClick={() => handleDelete(row.id)} disabled={isReadOnly}>
            <DeleteIcon fontSize="small" />
          </IconButton>
        </Box>
      )
    }
  ];

  return (
    <AdminLayout title={t('admin.countries.title')}>
      {countries.length === 0 && !loading ? (
        <EmptyState
          icon={PublicIcon}
          title={t('admin.countries.empty.title')}
          subtitle={t('admin.countries.empty.subtitle')}
          actionLabel={t('admin.countries.actions.add')}
          onAction={() => {
            if (!ensureWritable()) {
              return;
            }
            handleOpenDialog();
          }}
        />
      ) : (
        <Box sx={{ height: 560, width: '100%' }}>
          <DataGrid
            rows={countries}
            columns={columns}
            loading={loading}
            disableRowSelectionOnClick
            pageSizeOptions={[10, 25, 50]}
            initialState={{
              pagination: { paginationModel: { pageSize: 25, page: 0 } }
            }}
          />
        </Box>
      )}

      {countries.length > 0 && (
        <Fab
          color="primary"
          onClick={() => handleOpenDialog()}
          disabled={isReadOnly}
          sx={{
            position: 'fixed',
            bottom: 32,
            right: 32,
            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            '&.Mui-disabled': {
              background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
              color: '#fff',
              opacity: 0.5,
            },
            '&:hover': {
              background: 'linear-gradient(135deg, #5568d3 0%, #65408b 100%)'
            }
          }}
        >
          <AddIcon />
        </Fab>
      )}

      <Dialog open={openDialog} onClose={handleCloseDialog} maxWidth="sm" fullWidth>
        <DialogTitle>
          {editingCountry ? t('admin.countries.dialog.editTitle') : t('admin.countries.dialog.newTitle')}
        </DialogTitle>
        <Box component="form" onSubmit={handleSave}>
          <DialogContent sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <TextField
              label={t('common.name')}
              value={formData.name}
              onChange={(e) => setFormData(prev => ({ ...prev, name: e.target.value }))}
              required
            />
            <TextField
              label={t('admin.countries.fields.iso2')}
              value={formData.iso2}
              inputProps={{ maxLength: 2 }}
              onChange={(e) => {
                const value = e.target.value.toUpperCase();
                setFormData(prev => ({ ...prev, iso2: value }));
              }}
              required
              helperText={t('admin.countries.helpers.iso2')}
            />
          </DialogContent>
          <DialogActions>
            <Button onClick={handleCloseDialog} color="inherit">{t('common.cancel')}</Button>
            <Button type="submit" variant="contained" disabled={isReadOnly}>
              {editingCountry ? t('common.update') : t('common.create')}
            </Button>
          </DialogActions>
        </Box>
      </Dialog>

      <ConfirmationDialog
        open={confirmDialog.open}
        title={confirmDialog.title}
        message={confirmDialog.message}
        recordDetails={confirmDialog.recordDetails}
        confirmText={confirmDialog.confirmText}
        cancelText={confirmDialog.cancelText}
        confirmColor={confirmDialog.confirmColor}
        onConfirm={() => confirmDialog.action()}
        onCancel={() => setConfirmDialog(prev => ({ ...prev, open: false }))}
      />
    </AdminLayout>
  );
};

export default CountriesManager;
