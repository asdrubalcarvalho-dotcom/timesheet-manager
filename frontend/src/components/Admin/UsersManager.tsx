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
  const { showSuccess, showError } = useNotification();
  const { user: currentUser } = useAuth();
  const { billingSummary, loading: billingLoading } = useBilling();
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
      showError('Failed to load users');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (user?: UserRecord) => {
    // Prevent editing Owner users (except by themselves)
    if (user?.is_owner && user.user_id !== currentUser?.id) {
      showError('Owner users can only be edited by themselves');
      return;
    }
    
    // Check license limit when creating NEW user (not editing)
    if (!user && billingSummary && !billingLoading) {
      const { user_count, user_limit, plan } = billingSummary;
      
      // If limit is defined and we're at or above it, block creation
      if (user_limit && user_limit > 0 && user_count >= user_limit) {
        const planName = plan.charAt(0).toUpperCase() + plan.slice(1);
        showError(
          `License limit reached: Your ${planName} plan allows ${user_limit} users maximum. You currently have ${user_count}. Please upgrade your plan in the Billing page to add more users.`
        );
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

  const handleSave = async (e?: React.FormEvent) => {
    if (e) {
      e.preventDefault();
    }

    try {
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
        showSuccess('User updated successfully');
      } else {
        await api.post('/api/technicians', payload);
        showSuccess('User created successfully');
      }
      fetchUsers();
      handleCloseDialog();
    } catch (error: any) {
      // Handle license limit error specifically
      if (error.response?.data?.code === 'user_limit_reached') {
        showError(
          error.response.data.message || 
          'User limit reached. Purchase more licenses in Billing before adding new users.'
        );
      } else {
        showError(error.response?.data?.message || 'Failed to save user');
      }
    }
  };

  const handleDelete = async (id: number) => {
    const user = users.find(u => u.id === id);
    
    // Prevent deleting Owner users
    if (user?.is_owner) {
      showError('Owner users cannot be deleted');
      return;
    }
    setConfirmDialog({
      open: true,
      title: 'Delete User',
      message: 'Are you sure you want to delete this user? This action cannot be undone.',
      recordDetails: {
        name: user?.name,
        email: user?.email,
        role: user?.role
      },
      action: async () => {
        try {
          await api.delete(`/api/technicians/${id}`);
          showSuccess('User deleted successfully');
          fetchUsers();
        } catch (error: any) {
          showError(error?.response?.data?.message || 'Failed to delete user');
        }
        setConfirmDialog({ ...confirmDialog, open: false });
      }
    });
  };

  const formatRole = (role?: string) => {
    if (!role) return '-';
    return role.charAt(0).toUpperCase() + role.slice(1);
  };

  const columns: GridColDef<UserRecord>[] = [
    {
      field: 'id',
      headerName: 'ID',
      width: 80
    },
    {
      field: 'name',
      headerName: 'Name',
      flex: 1,
      minWidth: 180,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => (
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
          <span>{row.name}</span>
          {row.role === 'Owner' && (
            <Chip
              label="Owner"
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
      headerName: 'Email',
      flex: 1.2,
      minWidth: 200
    },
    {
      field: 'role',
      headerName: 'Role',
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
            label={formatRole(isOwner ? 'Owner' : row.role)}
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
      headerName: 'Hourly Rate',
      width: 130,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => {
        const value = row?.hourly_rate;
        return <span>{value ? `€${value}/hr` : '-'}</span>;
      }
    },
    {
      field: 'worker_id',
      headerName: 'Worker ID',
      width: 150,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => (
        <span>{row?.worker_id || '-'}</span>
      )
    },
    {
      field: 'worker_name',
      headerName: 'Worker Name',
      flex: 1,
      minWidth: 180,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => (
        <span>{row?.worker_name || '-'}</span>
      )
    },
    {
      field: 'worker_contract_country',
      headerName: 'Contract Country',
      width: 160,
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => (
        <span>{(row as any)?.worker_contract_country || '-'}</span>
      )
    },
    {
      field: 'actions',
      headerName: 'Actions',
      width: 120,
      sortable: false,
      renderCell: (params: GridRenderCellParams) => {
        const user = params.row as UserRecord;
        const isOwner = user.is_owner;
        const isOwnUser = user.user_id === currentUser?.id;
        const canEdit = !isOwner || isOwnUser; // Owner can only edit themselves
        const canDelete = !isOwner; // Owner cannot be deleted
        
        return (
          <Box>
            <IconButton
              size="small"
              onClick={() => handleOpenDialog(user)}
              disabled={!canEdit}
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
              disabled={!canDelete}
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
    <AdminLayout title="Users Management">
      {!loading && users.length === 0 ? (
        <EmptyState
          icon={PeopleIcon}
          title="No users yet"
          subtitle="Create your first user to start managing your team"
          actionLabel="New User"
          onAction={() => handleOpenDialog()}
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
              Manage Access & Permissions
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
          sx={{
            position: 'fixed',
            bottom: 32,
            right: 32
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
          {editingUser?.is_owner ? 'Edit Owner Profile' : editingUser ? 'Edit User' : 'New User'}
        </DialogTitle>
        <DialogContent>
          <Box component="form" onSubmit={handleSave} id="user-form" sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 1 }}>
            <TextField
              label="Name"
              fullWidth
              required
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            />
            <TextField
              label="Email"
              type="email"
              fullWidth
              required
              disabled={editingUser?.is_owner === true}
              value={formData.email}
              onChange={(e) => setFormData({ ...formData, email: e.target.value })}
              helperText={editingUser?.is_owner ? "Owner email cannot be changed" : ""}
            />
            <TextField
              label="Role"
              select
              fullWidth
              required
              disabled={editingUser?.is_owner === true}
              value={formData.role}
              onChange={(e) => setFormData({ ...formData, role: e.target.value })}
              helperText={editingUser?.is_owner ? "Owner role cannot be changed" : ""}
            >
              <MenuItem value="technician">Technician</MenuItem>
              <MenuItem value="manager">Manager</MenuItem>
            </TextField>
            <TextField
              label="Hourly Rate"
              type="number"
              fullWidth
              value={formData.hourly_rate}
              onChange={(e) => setFormData({ ...formData, hourly_rate: e.target.value })}
            />
            <TextField
              label="Worker ID"
              fullWidth
              value={formData.worker_id}
              onChange={(e) => setFormData({ ...formData, worker_id: e.target.value })}
              helperText="Optional unique identifier used for payroll exports"
            />
            <TextField
              label="Worker Name"
              fullWidth
              value={formData.worker_name}
              onChange={(e) => setFormData({ ...formData, worker_name: e.target.value })}
              helperText="Optional legal name for payroll if different from display name"
            />
            <TextField
              label="Worker Contract Country"
              fullWidth
              value={formData.worker_contract_country}
              onChange={(e) => setFormData({ ...formData, worker_contract_country: e.target.value })}
              helperText="Country where the worker's contract is registered (e.g., Portugal, Spain)"
            />
            <TextField
              label={editingUser ? 'New Password (leave blank to keep current)' : 'Password'}
              type="password"
              fullWidth
              required={!editingUser}
              value={formData.password}
              onChange={(e) => setFormData({ ...formData, password: e.target.value })}
            />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseDialog}>Cancel</Button>
          <Button
            type="submit"
            form="user-form"
            variant="contained"
            color="primary"
          >
            {editingUser ? 'Update' : 'Create'}
          </Button>
        </DialogActions>
      </Dialog>

      <ConfirmationDialog
        open={confirmDialog.open}
        title={confirmDialog.title}
        message={confirmDialog.message}
        recordDetails={confirmDialog.recordDetails}
        confirmText="Delete"
        cancelText="Cancel"
        confirmColor="error"
        onConfirm={confirmDialog.action}
        onCancel={() => setConfirmDialog({ ...confirmDialog, open: false })}
      />
    </AdminLayout>
  );
};

export default UsersManager;
