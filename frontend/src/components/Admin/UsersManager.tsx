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
  Chip
} from '@mui/material';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon
} from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRenderCellParams } from '@mui/x-data-grid';
import AdminLayout from './AdminLayout';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import api from '../../services/api';
import { Link } from 'react-router-dom';
import { useNotification } from '../../contexts/NotificationContext';

interface UserRecord {
  id: number;
  name: string;
  email: string;
  role: string;
  hourly_rate?: number;
  user_id?: number;
  worker_id?: string | null;
  worker_name?: string | null;
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
    password: ''
  });

  useEffect(() => {
    fetchUsers();
  }, []);

  const fetchUsers = async () => {
    try {
      setLoading(true);
      const response = await api.get('/technicians');
      setUsers(extractRows(response.data));
    } catch (error) {
      showError('Failed to load users');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (user?: UserRecord) => {
    if (user) {
      setEditingUser(user);
      setFormData({
        name: user.name,
        email: user.email,
        role: user.role,
        hourly_rate: user.hourly_rate?.toString() || '',
        worker_id: user.worker_id || '',
        worker_name: user.worker_name || '',
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
        password: ''
      });
    }
    setOpenDialog(true);
  };

  const handleCloseDialog = () => {
    setOpenDialog(false);
    setEditingUser(null);
  };

  const handleSave = async () => {
    try {
      const payload: any = {
        name: formData.name,
        email: formData.email,
        role: formData.role,
        hourly_rate: formData.hourly_rate ? parseFloat(formData.hourly_rate) : null,
        worker_id: formData.worker_id || null,
        worker_name: formData.worker_name || null
      };

      if (!editingUser && formData.password) {
        payload.password = formData.password;
      } else if (editingUser && formData.password) {
        payload.password = formData.password;
      }

      if (editingUser) {
        await api.put(`/technicians/${editingUser.id}`, payload);
        showSuccess('User updated successfully');
      } else {
        await api.post('/technicians', payload);
        showSuccess('User created successfully');
      }
      fetchUsers();
      handleCloseDialog();
    } catch (error) {
      showError('Failed to save user');
    }
  };

  const handleDelete = async (id: number) => {
    const user = users.find(u => u.id === id);
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
          await api.delete(`/technicians/${id}`);
          showSuccess('User deleted successfully');
          fetchUsers();
        } catch (error) {
          showError('Failed to delete user');
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
      minWidth: 180
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
      renderCell: ({ row }: GridRenderCellParams<UserRecord>) => (
        <Chip
          label={formatRole(row.role)}
          size="small"
          sx={{
            bgcolor: row.role === 'admin' ? '#e91e6315' : row.role === 'manager' ? '#2196f315' : '#43a04715',
            color: row.role === 'admin' ? '#e91e63' : row.role === 'manager' ? '#2196f3' : '#43a047',
            fontWeight: 600
          }}
        />
      )
    },
    {
      field: 'hourly_rate',
      headerName: 'Hourly Rate',
      width: 130,
      valueFormatter: ({ value }) => (value ? `$${value}/hr` : '-')
    },
    {
      field: 'worker_id',
      headerName: 'Worker ID',
      width: 150,
      valueGetter: ({ row }) => {
        const safeRow = row as Partial<UserRecord> | undefined;
        return safeRow?.worker_id || '-';
      }
    },
    {
      field: 'worker_name',
      headerName: 'Worker Name',
      flex: 1,
      minWidth: 180,
      valueGetter: ({ row }) => {
        const safeRow = row as Partial<UserRecord> | undefined;
        return safeRow?.worker_name || '-';
      }
    },
    {
      field: 'actions',
      headerName: 'Actions',
      width: 120,
      sortable: false,
      renderCell: (params: GridRenderCellParams) => (
        <Box>
          <IconButton
            size="small"
            onClick={() => handleOpenDialog(params.row as UserRecord)}
            sx={{ color: '#e91e63' }}
          >
            <EditIcon fontSize="small" />
          </IconButton>
          <IconButton
            size="small"
            onClick={() => handleDelete(params.row.id)}
            sx={{ color: '#f44336' }}
          >
            <DeleteIcon fontSize="small" />
          </IconButton>
        </Box>
      )
    }
  ];

  return (
    <AdminLayout title="Users Management">
      <Box sx={{ mb: 2, display: 'flex', justifyContent: 'flex-end', gap: 2 }}>
        <Button
          variant="contained"
          startIcon={<AddIcon />}
          onClick={() => handleOpenDialog()}
          sx={{ bgcolor: '#e91e63', '&:hover': { bgcolor: '#c2185b' } }}
        >
          New User
        </Button>
        <Button
          component={Link}
          to="/admin/access"
          variant="outlined"
          sx={{ color: '#1976d2', borderColor: '#1976d2' }}
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
            '& .MuiDataGrid-cell:focus': {
              outline: 'none'
            },
            '& .MuiDataGrid-row:hover': {
              bgcolor: 'rgba(233, 30, 99, 0.04)'
            }
          }}
        />
      </Box>

      <Dialog open={openDialog} onClose={handleCloseDialog} maxWidth="sm" fullWidth>
        <DialogTitle>
          {editingUser ? 'Edit User' : 'New User'}
        </DialogTitle>
        <DialogContent>
          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 1 }}>
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
              value={formData.email}
              onChange={(e) => setFormData({ ...formData, email: e.target.value })}
            />
            <TextField
              label="Role"
              select
              fullWidth
              required
              value={formData.role}
              onChange={(e) => setFormData({ ...formData, role: e.target.value })}
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
            onClick={handleSave}
            variant="contained"
            disabled={!formData.name || !formData.email || (!editingUser && !formData.password)}
            sx={{ bgcolor: '#e91e63', '&:hover': { bgcolor: '#c2185b' } }}
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
