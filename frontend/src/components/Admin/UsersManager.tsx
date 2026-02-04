import React, { useState, useEffect } from 'react';
import {
  Box,
  Button,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  TextField,
  IconButton,
  MenuItem,
  Chip,
  Fab
} from '@mui/material';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  People as PeopleIcon
} from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRenderCellParams } from '@mui/x-data-grid';
import AdminLayout from './AdminLayout';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import EmptyState from '../Common/EmptyState';
import api from '../../services/api';
import { Link } from 'react-router-dom';
import { useNotification } from '../../contexts/NotificationContext';
import { useAuth } from '../Auth/AuthContext';
import { useBilling } from '../../contexts/BillingContext';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import { formatTenantMoney } from '../../utils/tenantFormatting';
import { useTranslation } from 'react-i18next';

interface UserRecord {
  id: number;
  name: string;
  email: string;
  role: string;
  hourly_rate?: number;
  user_id?: number;
  worker_id?: string | null;
  worker_name?: string | null;
  is_owner?: boolean;
}

const extractRows = (payload: any): UserRecord[] => {
  if (Array.isArray(payload)) {
    return payload;
  }

  if (Array.isArray(payload?.data)) {
    return payload.data;
  }

  return [];
};

const UsersManager: React.FC = () => {
  const { t } = useTranslation();
  const { showSuccess, showError } = useNotification();
  const { user: currentUser, tenantContext } = useAuth();
  const { billingSummary, loading: billingLoading } = useBilling();
  const { isReadOnly, ensureWritable } = useReadOnlyGuard('admin-users');
  const [users, setUsers] = useState<UserRecord[]>([]);
  const [loading, setLoading] = useState(true);
  const [openDialog, setOpenDialog] = useState(false);
  const [editingUser, setEditingUser] = useState<UserRecord | null>(null);
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
    role: 'technician',
    hourly_rate: '',
    worker_id: '',
    worker_name: '',
    worker_contract_country: '',
    password: ''
  });

  useEffect(() => {
    fetchUsers();
  }, []);

  const fetchUsers = async () => {
    try {
      setLoading(true);
      const response = await api.get('/api/technicians');
      setUsers(extractRows(response.data));
    } catch (error) {
      showError(t('admin.users.notifications.loadFailed'));
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (user?: UserRecord) => {
    if (!ensureWritable()) {
      return;
    }
    // Prevent editing Owner users (except by themselves)
    if (user?.is_owner && user.user_id !== currentUser?.id) {
      showError(t('admin.users.errors.ownerEditSelfOnly'));
      return;
    }
    
    // Check license limit when creating NEW user (not editing)
    if (!user && billingSummary && !billingLoading) {
      const { user_count, user_limit, plan } = billingSummary;
      
      // If limit is defined and we're at or above it, block creation
      if (user_limit && user_limit > 0 && user_count >= user_limit) {
        const planName = plan.charAt(0).toUpperCase() + plan.slice(1);
        showError(t('admin.users.errors.licenseLimitReached', { planName, user_limit, user_count }));
        return;
      }
    }
    
    if (user) {
      setEditingUser(user);
      setFormData({
        name: user.name,
        email: user.email,
        role: user.role,
        hourly_rate: user.hourly_rate?.toString() || '',
        worker_id: user.worker_id || '',
        worker_name: user.worker_name || '',
        worker_contract_country: (user as any).worker_contract_country || '',
        password: ''
      });
    } else {
      setEditingUser(null);
      setFormData({
        name: '',
        email: '',
        role: 'technician',
        hourly_rate: '',
        worker_id: '',
        worker_name: '',
        worker_contract_country: '',
        password: ''
      });
    }
    setOpenDialog(true);
  };

  const handleCloseDialog = () => {
    setOpenDialog(false);
    setEditingUser(null);
  };

  const handleSave = async (e?: React.FormEvent<HTMLFormElement>) => {
    if (e) {
      e.preventDefault();
    }

    if (!ensureWritable()) {
      return;
    }

    try {
      // By default, keep existing behavior: create user AND send invite email.
      // If the user clicks the secondary button, submitter will include data-send-invite="false".
      let sendInvite = true;
      if (e) {
        const nativeEvent = e.nativeEvent as any;
        const submitter = nativeEvent?.submitter as HTMLButtonElement | undefined;
        if (submitter?.dataset?.sendInvite === 'false') {
          sendInvite = false;
        }
      }

      const payload: any = {
        name: formData.name,
      };

      // Owner can only edit their name, hourly_rate, worker_id, worker_name, worker_contract_country, and password
      if (!editingUser?.is_owner || editingUser.user_id === currentUser?.id) {
        payload.email = formData.email;
        payload.hourly_rate = formData.hourly_rate ? parseFloat(formData.hourly_rate) : null;
        payload.worker_id = formData.worker_id || null;
        payload.worker_name = formData.worker_name || null;
        payload.worker_contract_country = formData.worker_contract_country || null;
        
        // Normal users can have role changed (Owner role is protected)
        if (!editingUser?.is_owner) {
          payload.role = formData.role;
        }
        
        // Allow password change for Owner (self-edit) and normal users
        if (formData.password) {
          payload.password = formData.password;
        }
      }

      if (!editingUser && formData.password) {
        payload.password = formData.password;
      }

      if (editingUser) {
        await api.put(`/api/technicians/${editingUser.id}`, payload);
        showSuccess(t('admin.users.notifications.updateSuccess'));
      } else {
        // Use the same endpoint, but allow opting out of invite email.
        payload.send_invite = sendInvite;
        await api.post('/api/technicians', payload);
        showSuccess(t('admin.users.notifications.createSuccess'));
      }
      fetchUsers();
      handleCloseDialog();
    } catch (error: any) {
      // Handle license limit error specifically
      if (error.response?.data?.code === 'user_limit_reached') {
        showError(
          error.response.data.message || 
          t('admin.users.errors.userLimitReachedDefault')
        );
      } else {
        showError(error.response?.data?.message || t('admin.users.notifications.saveFailed'));
      }
    }
  };

  const handleDelete = async (id: number) => {
    if (!ensureWritable()) {
      return;
    }
    const user = users.find(u => u.id === id);
    
    // Prevent deleting Owner users
    if (user?.is_owner) {
      showError(t('admin.users.errors.ownerDeleteBlocked'));
      return;
    }
    setConfirmDialog({
      open: true,
      title: t('admin.users.confirmDelete.title'),
      message: t('admin.users.confirmDelete.message'),
      recordDetails: {
        name: user?.name,
        email: user?.email,
        role: user?.role
      },
      action: async () => {
        if (!ensureWritable()) {
          return;
        }
        try {
          await api.delete(`/api/technicians/${id}`);
          showSuccess(t('admin.users.notifications.deleteSuccess'));
          fetchUsers();
        } catch (error: any) {
          showError(error?.response?.data?.message || t('admin.users.notifications.deleteFailed'));
        }
        setConfirmDialog({ ...confirmDialog, open: false });
      }
    });
  };

  const formatRole = (role?: string) => {
    if (!role) return '-';
    const normalized = role.toLowerCase();
    const knownRoleKeys = ['owner', 'admin', 'manager', 'technician'];
    if (knownRoleKeys.includes(normalized)) {
      return t(`admin.users.roles.${normalized}`);
    }
    return role.charAt(0).toUpperCase() + role.slice(1);
  };

  const columns: GridColDef<UserRecord>[] = [
    {
      field: 'id',
      headerName: t('admin.shared.columns.id'),
      width: 80
    },
    {
      field: 'name',
      headerName: t('admin.shared.columns.name'),
      flex: 1,
      minWidth: 180,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => (
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <span>{row.name}</span>
          {row.role === 'Owner' && (
            <Chip
              label={t('admin.users.roles.owner')}
              size="small"
              sx={{
                height: 18,
                fontSize: '0.65rem',
                fontWeight: 700,
                bgcolor: '#fbbf24',
                color: '#78350f',
                '& .MuiChip-label': {
                  px: 1
                }
              }}
            />
          )}
        </Box>
      )
    },
    {
      field: 'email',
      headerName: t('admin.users.columns.email'),
      flex: 1.2,
      minWidth: 200
    },
    {
      field: 'role',
      headerName: t('admin.users.columns.role'),
      width: 140,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => {
        const isOwner = row.is_owner || row.role?.toLowerCase() === 'owner';
        const bgcolor = isOwner 
          ? '#fbbf2415' 
          : row.role === 'admin' 
          ? '#e91e6315' 
          : row.role === 'manager' 
          ? '#2196f315' 
          : '#43a04715';
        const color = isOwner
          ? '#fbbf24'
          : row.role === 'admin'
          ? '#e91e63'
          : row.role === 'manager'
          ? '#2196f3'
          : '#43a047';
        
        return (
          <Chip
            label={formatRole(isOwner ? 'owner' : row.role)}
            size="small"
            sx={{
              bgcolor,
              color,
              fontWeight: 600
            }}
          />
        );
      }
    },
    {
      field: 'hourly_rate',
      headerName: t('admin.users.columns.hourlyRate'),
      width: 130,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => {
        const value = row?.hourly_rate;
        const amount = Number(value);
        return (
          <span>
            {Number.isFinite(amount)
              ? `${formatTenantMoney(amount, tenantContext)}${t('admin.users.perHourSuffix')}`
              : '-'}
          </span>
        );
      }
    },
    {
      field: 'worker_id',
      headerName: t('admin.users.columns.workerId'),
      width: 150,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => (
        <span>{row?.worker_id || '-'}</span>
      )
    },
    {
      field: 'worker_name',
      headerName: t('admin.users.columns.workerName'),
      flex: 1,
      minWidth: 180,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => (
        <span>{row?.worker_name || '-'}</span>
      )
    },
    {
      field: 'worker_contract_country',
      headerName: t('admin.users.columns.contractCountry'),
      width: 160,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => (
        <span>{(row as any)?.worker_contract_country || '-'}</span>
      )
    },
    {
      field: 'actions',
      headerName: t('admin.shared.columns.actions'),
      width: 120,
      sortable: false,
      renderCell: (params: GridRenderCellParams) => {
        const user = params.row as UserRecord;
        const isOwner = user.is_owner;
        const isOwnUser = user.user_id === currentUser?.id;
        const canEdit = !isOwner || isOwnUser; // Owner can only edit themselves
        const canDelete = !isOwner; // Owner cannot be deleted
        const canWrite = !isReadOnly;
        
        return (
          <Box>
            <IconButton
              size="small"
              onClick={() => handleOpenDialog(user)}
              disabled={!canEdit || !canWrite}
              sx={{ 
                color: canEdit ? '#667eea' : '#ccc',
                '&:disabled': { color: '#ccc' }
              }}
            >
              <EditIcon fontSize="small" />
            </IconButton>
            <IconButton
              size="small"
              onClick={() => handleDelete(user.id)}
              disabled={!canDelete || !canWrite}
              sx={{ 
                color: canDelete ? '#f44336' : '#ccc',
                '&:disabled': { color: '#ccc' }
              }}
            >
              <DeleteIcon fontSize="small" />
            </IconButton>
          </Box>
        );
      }
    }
  ];

  return (
    <AdminLayout title={t('admin.users.managementTitle')}>
      {!loading && users.length === 0 ? (
        <EmptyState
          icon={PeopleIcon}
          title={t('admin.users.empty.title')}
          subtitle={t('admin.users.empty.subtitle')}
          actionLabel={t('admin.users.actions.new')}
          onAction={() => {
            if (!ensureWritable()) {
              return;
            }
            handleOpenDialog();
          }}
        />
      ) : (
        <>
          <Box sx={{ mb: 2, display: 'flex', justifyContent: 'flex-end' }}>
            <Button
              component={Link}
              to="/admin/access"
              variant="outlined"
              sx={{ 
                borderColor: 'rgba(102, 126, 234, 0.5)',
                color: '#667eea',
                '&:hover': {
                  borderColor: '#667eea',
                  bgcolor: 'rgba(102, 126, 234, 0.04)'
                }
              }}
            >
              {t('admin.users.actions.manageAccess')}
            </Button>
          </Box>

          <Box sx={{ height: 600, width: '100%' }}>
            <DataGrid
              rows={users}
              columns={columns}
              loading={loading}
              pageSizeOptions={[10, 25, 50]}
              initialState={{
                pagination: { paginationModel: { pageSize: 10 } }
              }}
              disableRowSelectionOnClick
              sx={{
                border: 'none',
                '& .MuiDataGrid-cell:focus': {
                  outline: 'none'
                },
                '& .MuiDataGrid-row:hover': {
                  bgcolor: 'rgba(102, 126, 234, 0.04)'
                },
                '& .MuiDataGrid-columnHeaders': {
                  bgcolor: 'rgba(102, 126, 234, 0.08)',
                  borderRadius: '8px 8px 0 0'
                }
              }}
            />
          </Box>
        </>
      )}

      {/* Floating Action Button */}
      {users.length > 0 && (
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

      <Dialog 
        open={openDialog} 
        onClose={handleCloseDialog} 
        maxWidth="sm" 
        fullWidth
        disableRestoreFocus
      >
        <DialogTitle>
          {editingUser?.is_owner
            ? t('admin.users.dialog.editOwnerProfile')
            : editingUser
              ? t('admin.users.dialog.editUser')
              : t('admin.users.dialog.newUser')}
        </DialogTitle>
        <DialogContent>
          <Box component="form" onSubmit={handleSave} id="user-form" sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 1 }}>
            <TextField
              label={t('common.name')}
              fullWidth
              required
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            />
            <TextField
              label={t('admin.users.fields.email')}
              type="email"
              fullWidth
              required
              disabled={editingUser?.is_owner === true}
              value={formData.email}
              onChange={(e) => setFormData({ ...formData, email: e.target.value })}
              helperText={editingUser?.is_owner ? t('admin.users.helpers.ownerEmailLocked') : ""}
            />
            <TextField
              label={t('admin.users.fields.role')}
              select
              fullWidth
              required
              disabled={editingUser?.is_owner === true}
              value={formData.role}
              onChange={(e) => setFormData({ ...formData, role: e.target.value })}
              helperText={editingUser?.is_owner ? t('admin.users.helpers.ownerRoleLocked') : ""}
            >
              <MenuItem value="technician">{t('admin.users.roles.technician')}</MenuItem>
              <MenuItem value="manager">{t('admin.users.roles.manager')}</MenuItem>
            </TextField>
            <TextField
              label={t('admin.users.fields.hourlyRate')}
              type="number"
              fullWidth
              value={formData.hourly_rate}
              onChange={(e) => setFormData({ ...formData, hourly_rate: e.target.value })}
            />
            <TextField
              label={t('admin.users.fields.workerId')}
              fullWidth
              value={formData.worker_id}
              onChange={(e) => setFormData({ ...formData, worker_id: e.target.value })}
              helperText={t('admin.users.helpers.workerId')}
            />
            <TextField
              label={t('admin.users.fields.workerName')}
              fullWidth
              value={formData.worker_name}
              onChange={(e) => setFormData({ ...formData, worker_name: e.target.value })}
              helperText={t('admin.users.helpers.workerName')}
            />
            <TextField
              label={t('admin.users.fields.contractCountry')}
              fullWidth
              value={formData.worker_contract_country}
              onChange={(e) => setFormData({ ...formData, worker_contract_country: e.target.value })}
              helperText={t('admin.users.helpers.contractCountry')}
            />
            <TextField
              label={
                editingUser
                  ? t('admin.users.fields.newPasswordKeepCurrent')
                  : t('admin.users.fields.password')
              }
              type="password"
              fullWidth
              required={!editingUser}
              value={formData.password}
              onChange={(e) => setFormData({ ...formData, password: e.target.value })}
            />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseDialog}>{t('common.cancel')}</Button>
          {editingUser ? (
            <Button
              type="submit"
              form="user-form"
              variant="contained"
              color="primary"
              disabled={isReadOnly}
            >
              {t('common.update')}
            </Button>
          ) : (
            <>
              <Button
                type="submit"
                form="user-form"
                variant="outlined"
                color="primary"
                disabled={isReadOnly}
                data-send-invite="false"
              >
                {t('admin.users.actions.createWithoutEmail')}
              </Button>
              <Button
                type="submit"
                form="user-form"
                variant="contained"
                color="primary"
                disabled={isReadOnly}
              >
                {t('common.create')}
              </Button>
            </>
          )}
        </DialogActions>
      </Dialog>

      <ConfirmationDialog
        open={confirmDialog.open}
        title={confirmDialog.title}
        message={confirmDialog.message}
        recordDetails={confirmDialog.recordDetails}
        confirmText={t('common.delete')}
        cancelText={t('common.cancel')}
        confirmColor="error"
        onConfirm={confirmDialog.action}
        onCancel={() => setConfirmDialog({ ...confirmDialog, open: false })}
      />
    </AdminLayout>
  );
};

export default UsersManager;
