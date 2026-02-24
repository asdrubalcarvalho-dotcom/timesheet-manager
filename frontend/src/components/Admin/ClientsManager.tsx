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
  Business as BusinessIcon,
} from '@mui/icons-material';
import AdminLayout from './AdminLayout';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import EmptyState from '../Common/EmptyState';
import { useNotification } from '../../contexts/NotificationContext';
import api from '../../services/api';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import { useTranslation } from 'react-i18next';
import useDataGridLocaleText from '../../hooks/useDataGridLocaleText';

interface Client {
  id: number;
  name: string;
  email?: string | null;
  tax_id?: string | null;
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

const ClientsManager: React.FC = () => {
  const { t } = useTranslation();
  const dataGridLocaleText = useDataGridLocaleText();
  const { showSuccess, showError } = useNotification();
  const { isReadOnly, ensureWritable } = useReadOnlyGuard('admin-projects');

  const [clients, setClients] = useState<Client[]>([]);
  const [loading, setLoading] = useState(true);
  const [openDialog, setOpenDialog] = useState(false);
  const [editingClient, setEditingClient] = useState<Client | null>(null);
  const [confirmDialog, setConfirmDialog] = useState({
    open: false,
    title: '',
    message: '',
    recordDetails: {} as any,
    action: (() => {}) as () => void | Promise<void>
  });

  const [formData, setFormData] = useState({
    name: '',
    email: '',
    tax_id: '',
  });

  useEffect(() => {
    fetchClients();
  }, []);

  const fetchClients = async () => {
    try {
      setLoading(true);
      const response = await api.get('/api/clients');
      setClients(normalizeApiResponse<Client>(response.data));
    } catch (error: any) {
      showError(error?.response?.data?.message || t('admin.clients.notifications.loadFailed'));
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (client?: Client) => {
    if (!ensureWritable()) {
      return;
    }

    if (client) {
      setEditingClient(client);
      setFormData({
        name: client.name,
        email: client.email || '',
        tax_id: client.tax_id || '',
      });
    } else {
      setEditingClient(null);
      setFormData({
        name: '',
        email: '',
        tax_id: '',
      });
    }

    setOpenDialog(true);
  };

  const handleCloseDialog = () => {
    setOpenDialog(false);
    setEditingClient(null);
  };

  const handleSave = async (e?: React.FormEvent) => {
    if (e) {
      e.preventDefault();
    }

    if (!ensureWritable()) {
      return;
    }

    const payload = {
      name: formData.name.trim(),
      email: formData.email.trim() || null,
      tax_id: formData.tax_id.trim() || null,
    };

    if (!payload.name) {
      showError(t('admin.clients.validation.nameRequired'));
      return;
    }

    try {
      if (editingClient) {
        await api.put(`/api/clients/${editingClient.id}`, payload);
        showSuccess(t('admin.clients.notifications.updateSuccess'));
      } else {
        await api.post('/api/clients', payload);
        showSuccess(t('admin.clients.notifications.createSuccess'));
      }

      fetchClients();
      handleCloseDialog();
    } catch (error: any) {
      showError(error?.response?.data?.message || t('admin.clients.notifications.saveFailed'));
    }
  };

  const handleDelete = async (id: number) => {
    if (!ensureWritable()) {
      return;
    }

    const client = clients.find((c) => c.id === id);

    setConfirmDialog({
      open: true,
      title: t('admin.clients.confirmDelete.title'),
      message: t('admin.clients.confirmDelete.message'),
      recordDetails: {
        name: client?.name,
        email: client?.email || '-',
        tax_id: client?.tax_id || '-',
      },
      action: async () => {
        if (!ensureWritable()) {
          return;
        }

        try {
          await api.delete(`/api/clients/${id}`);
          showSuccess(t('admin.clients.notifications.deleteSuccess'));
          fetchClients();
        } catch (error: any) {
          showError(error?.response?.data?.message || t('admin.clients.notifications.deleteFailed'));
        }

        setConfirmDialog((prev) => ({ ...prev, open: false }));
      }
    });
  };

  const columns: GridColDef[] = [
    {
      field: 'id',
      headerName: t('admin.shared.columns.id'),
      width: 80
    },
    {
      field: 'name',
      headerName: t('admin.clients.columns.name'),
      flex: 1,
      minWidth: 200
    },
    {
      field: 'email',
      headerName: t('admin.clients.columns.email'),
      flex: 1,
      minWidth: 220,
      renderCell: ({ row }: GridRenderCellParams<Client>) => row.email || '-',
    },
    {
      field: 'tax_id',
      headerName: t('admin.clients.columns.taxId'),
      width: 180,
      renderCell: ({ row }: GridRenderCellParams<Client>) => row.tax_id || '-',
    },
    {
      field: 'actions',
      headerName: t('admin.shared.columns.actions'),
      width: 140,
      sortable: false,
      renderCell: ({ row }: GridRenderCellParams<Client>) => (
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
    <AdminLayout title={t('admin.clients.title')}>
      {clients.length === 0 && !loading ? (
        <EmptyState
          icon={BusinessIcon}
          title={t('admin.clients.empty.title')}
          subtitle={t('admin.clients.empty.subtitle')}
          actionLabel={t('admin.clients.actions.new')}
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
            rows={clients}
            columns={columns}
            loading={loading}
            localeText={dataGridLocaleText}
            disableRowSelectionOnClick
            pageSizeOptions={[10, 25, 50]}
            initialState={{
              pagination: { paginationModel: { pageSize: 10, page: 0 } }
            }}
          />
        </Box>
      )}

      {clients.length > 0 && (
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
          {editingClient ? t('admin.clients.actions.edit') : t('admin.clients.actions.new')}
        </DialogTitle>
        <Box component="form" onSubmit={handleSave}>
          <DialogContent sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
            <TextField
              label={t('admin.clients.columns.name')}
              value={formData.name}
              onChange={(e) => setFormData((prev) => ({ ...prev, name: e.target.value }))}
              required
            />
            <TextField
              label={t('admin.clients.columns.email')}
              value={formData.email}
              onChange={(e) => setFormData((prev) => ({ ...prev, email: e.target.value }))}
            />
            <TextField
              label={t('admin.clients.columns.taxId')}
              value={formData.tax_id}
              onChange={(e) => setFormData((prev) => ({ ...prev, tax_id: e.target.value }))}
            />
          </DialogContent>
          <DialogActions>
            <Button onClick={handleCloseDialog} color="inherit">{t('common.cancel')}</Button>
            <Button type="submit" variant="contained" disabled={isReadOnly}>
              {editingClient ? t('common.update') : t('common.create')}
            </Button>
          </DialogActions>
        </Box>
      </Dialog>

      <ConfirmationDialog
        open={confirmDialog.open}
        title={confirmDialog.title}
        message={confirmDialog.message}
        recordDetails={confirmDialog.recordDetails}
        confirmText={t('admin.clients.actions.delete')}
        cancelText={t('common.cancel')}
        confirmColor="error"
        onConfirm={() => confirmDialog.action()}
        onCancel={() => setConfirmDialog((prev) => ({ ...prev, open: false }))}
      />
    </AdminLayout>
  );
};

export default ClientsManager;
