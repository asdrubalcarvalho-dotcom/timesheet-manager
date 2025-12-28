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
  const { showSuccess, showError } = useNotification();

  const [countries, setCountries] = useState<Country[]>([]);
  const [loading, setLoading] = useState(true);

  const [openDialog, setOpenDialog] = useState(false);
  const [editingCountry, setEditingCountry] = useState<Country | null>(null);

  const [confirmDialog, setConfirmDialog] = useState<ConfirmDialogState>({
    open: false,
    title: '',
    message: '',
    recordDetails: undefined,
    confirmText: 'Confirm',
    cancelText: 'Cancel',
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
      showError('Failed to load countries');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (country?: Country) => {
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

    const name = formData.name.trim();
    const iso2 = formData.iso2.trim().toUpperCase();

    if (!name || iso2.length !== 2) {
      showError('Name is required and ISO-2 must be 2 letters');
      return;
    }

    try {
      const payload = { name, iso2 };

      if (editingCountry) {
        await api.put(`/api/countries/${editingCountry.id}`, payload);
        showSuccess('Country updated successfully');
      } else {
        await api.post('/api/countries', payload);
        showSuccess('Country created successfully');
      }

      fetchCountries();
      handleCloseDialog();
    } catch (error: any) {
      const msg = error?.response?.data?.message || 'Failed to save country';
      showError(msg);
    }
  };

  const attemptDelete = async (country: Country, force: boolean) => {
    const url = force ? `/api/countries/${country.id}?force=true` : `/api/countries/${country.id}`;
    await api.delete(url);
  };

  const handleDelete = async (id: number) => {
    const country = countries.find(c => c.id === id);
    if (!country) return;

    // First attempt: normal delete
    try {
      await attemptDelete(country, false);
      showSuccess('Country deleted successfully');
      fetchCountries();
      return;
    } catch (error: any) {
      if (error?.response?.status === 409) {
        const locationsCount = error?.response?.data?.locations_count;

        setConfirmDialog({
          open: true,
          title: 'Country is in use',
          message:
            'This country is referenced by existing locations. Do you want to force delete it? Locations will NOT be deleted; their country_id may become null.',
          recordDetails: {
            name: country.name,
            code: country.iso2,
            locations_count: locationsCount
          },
          confirmText: 'Force delete',
          confirmColor: 'warning',
          action: async () => {
            try {
              await attemptDelete(country, true);
              showSuccess('Country deleted successfully');
              fetchCountries();
            } catch (e: any) {
              showError(e?.response?.data?.message || 'Failed to delete country');
            }
            setConfirmDialog(prev => ({ ...prev, open: false }));
          }
        });
        return;
      }

      showError(error?.response?.data?.message || 'Failed to delete country');
    }
  };

  const columns: GridColDef[] = [
    { field: 'name', headerName: 'Name', flex: 1, minWidth: 200 },
    { field: 'iso2', headerName: 'ISO-2', width: 120 },
    {
      field: 'actions',
      headerName: 'Actions',
      width: 140,
      sortable: false,
      renderCell: ({ row }: GridRenderCellParams<Country>) => (
        <Box sx={{ display: 'flex', gap: 1 }}>
          <IconButton size="small" onClick={() => handleOpenDialog(row)}>
            <EditIcon fontSize="small" />
          </IconButton>
          <IconButton size="small" color="error" onClick={() => handleDelete(row.id)}>
            <DeleteIcon fontSize="small" />
          </IconButton>
        </Box>
      )
    }
  ];

  return (
    <AdminLayout title="Countries">
      {countries.length === 0 && !loading ? (
        <EmptyState
          icon={PublicIcon}
          title="No countries"
          subtitle="Create your first country to use it in dropdowns."
          actionLabel="Add Country"
          onAction={() => handleOpenDialog()}
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
          sx={{
            position: 'fixed',
            bottom: 32,
            right: 32,
          }}
        >
          <AddIcon />
        </Fab>
      )}

      <Dialog open={openDialog} onClose={handleCloseDialog} maxWidth="sm" fullWidth>
        <DialogTitle>{editingCountry ? 'Edit Country' : 'New Country'}</DialogTitle>
        <Box component="form" onSubmit={handleSave}>
          <DialogContent sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <TextField
              label="Name"
              value={formData.name}
              onChange={(e) => setFormData(prev => ({ ...prev, name: e.target.value }))}
              required
            />
            <TextField
              label="ISO-2"
              value={formData.iso2}
              inputProps={{ maxLength: 2 }}
              onChange={(e) => {
                const value = e.target.value.toUpperCase();
                setFormData(prev => ({ ...prev, iso2: value }));
              }}
              required
              helperText="2 letters (e.g. PT, ES, FR)"
            />
          </DialogContent>
          <DialogActions>
            <Button onClick={handleCloseDialog} color="inherit">Cancel</Button>
            <Button type="submit" variant="contained">
              {editingCountry ? 'Update' : 'Create'}
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
