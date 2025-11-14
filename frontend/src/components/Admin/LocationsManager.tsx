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
  FormControlLabel,
  Switch,
  Chip,
  Fab
} from '@mui/material';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  LocationOn as LocationOnIcon
} from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRenderCellParams } from '@mui/x-data-grid';
import AdminLayout from './AdminLayout';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import EmptyState from '../Common/EmptyState';
import { useNotification } from '../../contexts/NotificationContext';
import api from '../../services/api';

interface Location {
  id: number;
  name: string;
  country: string;
  city: string;
  address?: string;
  postal_code?: string;
  latitude?: number | null;
  longitude?: number | null;
  is_active: boolean;
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

const LocationsManager: React.FC = () => {
  const { showSuccess, showError } = useNotification();
  const [locations, setLocations] = useState<Location[]>([]);
  const [loading, setLoading] = useState(true);
  const [openDialog, setOpenDialog] = useState(false);
  const [editingLocation, setEditingLocation] = useState<Location | null>(null);
  const [confirmDialog, setConfirmDialog] = useState({ 
    open: false, 
    title: '', 
    message: '', 
    recordDetails: {} as any,
    action: (() => {}) as () => void | Promise<void>
  });
  
  const [formData, setFormData] = useState({
    name: '',
    country: '',
    city: '',
    address: '',
    postal_code: '',
    latitude: '',
    longitude: '',
    is_active: true
  });

  useEffect(() => {
    fetchLocations();
  }, []);

  const fetchLocations = async () => {
    try {
      setLoading(true);
      const response = await api.get('/locations');
      setLocations(normalizeApiResponse<Location>(response.data));
    } catch {
      showError('Failed to load locations');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (location?: Location) => {
    if (location) {
      setEditingLocation(location);
      setFormData({
        name: location.name,
        country: location.country,
        city: location.city,
        address: location.address || '',
        postal_code: location.postal_code || '',
        latitude:
          location.latitude !== null && location.latitude !== undefined
            ? location.latitude.toString()
            : '',
        longitude:
          location.longitude !== null && location.longitude !== undefined
            ? location.longitude.toString()
            : '',
        is_active: location.is_active
      });
    } else {
      setEditingLocation(null);
      setFormData({
        name: '',
        country: '',
        city: '',
        address: '',
        postal_code: '',
        latitude: '',
        longitude: '',
        is_active: true
      });
    }
    setOpenDialog(true);
  };

  const handleCloseDialog = () => {
    setOpenDialog(false);
    setEditingLocation(null);
  };

  const handleSave = async (e?: React.FormEvent) => {
    if (e) {
      e.preventDefault();
    }

    // Frontend validation
    if (!formData.name || !formData.country || !formData.city) {
      showError('Name, Country, and City are required fields');
      return;
    }

    try {
      const payload = {
        name: formData.name,
        country: formData.country,
        city: formData.city,
        address: formData.address || null,
        postal_code: formData.postal_code || null,
        latitude: formData.latitude && formData.latitude.trim() !== '' ? parseFloat(formData.latitude) : null,
        longitude: formData.longitude && formData.longitude.trim() !== '' ? parseFloat(formData.longitude) : null,
        is_active: formData.is_active
      };

      if (editingLocation) {
        await api.put(`/locations/${editingLocation.id}`, payload);
        showSuccess('Location updated successfully');
      } else {
        await api.post('/locations', payload);
        showSuccess('Location created successfully');
      }
      fetchLocations();
      handleCloseDialog();
    } catch (error: any) {
      const errorMessage = error.response?.data?.message || 'Failed to save location';
      showError(errorMessage);
      console.error('Location save error:', error.response?.data);
    }
  };

  const handleDelete = async (id: number) => {
    const location = locations.find(l => l.id === id);
    setConfirmDialog({
      open: true,
      title: 'Delete Location',
      message: 'Are you sure you want to delete this location? This action cannot be undone.',
      recordDetails: {
        name: location?.name,
        city: location?.city,
        country: location?.country
      },
      action: async () => {
        try {
          await api.delete(`/locations/${id}`);
          showSuccess('Location deleted successfully');
          fetchLocations();
        } catch {
          showError('Failed to delete location');
        }
        setConfirmDialog({ ...confirmDialog, open: false });
      }
    });
  };

  const columns: GridColDef[] = [
    {
      field: 'id',
      headerName: 'ID',
      width: 80
    },
    {
      field: 'name',
      headerName: 'Name',
      flex: 1,
      minWidth: 200
    },
    {
      field: 'address',
      headerName: 'Address',
      flex: 1.5,
      minWidth: 250
    },
    {
      field: 'city',
      headerName: 'City',
      width: 140
    },
    {
      field: 'country',
      headerName: 'Country',
      width: 140
    },
    {
      field: 'postal_code',
      headerName: 'Postal Code',
      width: 140,
      renderCell: ({ row }: GridRenderCellParams<Location>) => (
        <span>{row?.postal_code || '-'}</span>
      )
    },
    {
      field: 'coordinates',
      headerName: 'Coordinates',
      width: 180,
      renderCell: ({ row }: GridRenderCellParams<Location>) => {
        const latitude = row?.latitude;
        const longitude = row?.longitude;

        if (
          latitude === null ||
          latitude === undefined ||
          longitude === null ||
          longitude === undefined
        ) {
          return <span style={{ color: '#999' }}>-</span>;
        }

        return (
          <span style={{ fontFamily: 'monospace', fontSize: '0.85rem' }}>
            {Number(latitude).toFixed(4)}, {Number(longitude).toFixed(4)}
          </span>
        );
      }
    },
    {
      field: 'is_active',
      headerName: 'Status',
      width: 120,
      renderCell: ({ row }: GridRenderCellParams<Location>) => (
        <Chip
          size="small"
          label={row.is_active ? 'Active' : 'Inactive'}
          sx={{
            bgcolor: row.is_active ? '#4caf5015' : '#f4433615',
            color: row.is_active ? '#4caf50' : '#f44336',
            fontWeight: 600
          }}
        />
      )
    },
    {
      field: 'actions',
      headerName: 'Actions',
      width: 140,
      sortable: false,
      renderCell: (params: GridRenderCellParams) => (
        <Box>
          <IconButton
            size="small"
            onClick={() => handleOpenDialog(params.row as Location)}
            sx={{ color: '#ff9800' }}
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
    <AdminLayout title="Locations Management">
      {!loading && locations.length === 0 ? (
        <EmptyState
          icon={LocationOnIcon}
          title="No locations yet"
          subtitle="Create your first location to track where work is performed"
          actionLabel="New Location"
          onAction={() => handleOpenDialog()}
        />
      ) : (
        <Box sx={{ width: '100%', overflowX: 'auto' }}>
          <DataGrid
            autoHeight
            rows={locations}
            columns={columns}
            loading={loading}
            pageSizeOptions={[10, 25, 50]}
            initialState={{
              pagination: { paginationModel: { pageSize: 10 } }
            }}
            disableRowSelectionOnClick
            sx={{
              minWidth: 900,
              border: 'none',
              '& .MuiDataGrid-cell:focus': {
                outline: 'none'
              },
              '& .MuiDataGrid-row:hover': {
                bgcolor: 'rgba(102, 126, 234, 0.04)'
              },
              '& .MuiDataGrid-columnHeaders': {
                bgcolor: 'rgba(102, 126, 234, 0.08)',
                borderRadius: '8px 8px 0 0',
                fontWeight: 600
              }
            }}
          />
        </Box>
      )}

      {/* Floating Action Button */}
      {locations.length > 0 && (
      <Fab
          color="primary"
          onClick={() => setOpenDialog(true)}
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
          {editingLocation ? 'Edit Location' : 'New Location'}
        </DialogTitle>
        <DialogContent>
          <Box component="form" onSubmit={handleSave} id="location-form" sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 1 }}>
            <TextField
              label="Name"
              fullWidth
              required
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            />
            <TextField
              label="Country"
              fullWidth
              required
              value={formData.country}
              onChange={(e) => setFormData({ ...formData, country: e.target.value })}
            />
            <TextField
              label="City"
              fullWidth
              required
              value={formData.city}
              onChange={(e) => setFormData({ ...formData, city: e.target.value })}
            />
            <TextField
              label="Address"
              fullWidth
              multiline
              rows={2}
              value={formData.address}
              onChange={(e) => setFormData({ ...formData, address: e.target.value })}
            />
            <TextField
              label="Postal Code"
              fullWidth
              value={formData.postal_code}
              onChange={(e) => setFormData({ ...formData, postal_code: e.target.value })}
            />
            <Box sx={{ display: 'flex', gap: 2, flexDirection: { xs: 'column', sm: 'row' } }}>
              <TextField
                label="Latitude"
                type="number"
                inputProps={{ step: '0.000001' }}
                fullWidth
                value={formData.latitude}
                onChange={(e) => setFormData({ ...formData, latitude: e.target.value })}
              />
              <TextField
                label="Longitude"
                type="number"
                inputProps={{ step: '0.000001' }}
                fullWidth
                value={formData.longitude}
                onChange={(e) => setFormData({ ...formData, longitude: e.target.value })}
              />
            </Box>
            <FormControlLabel
              control={
                <Switch
                  checked={formData.is_active}
                  onChange={(e) => setFormData({ ...formData, is_active: e.target.checked })}
                />
              }
              label="Location is active"
            />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseDialog}>Cancel</Button>
          <Button
            type="submit"
            form="location-form"
            variant="contained"
            color="primary"
          >
            {editingLocation ? 'Update' : 'Create'}
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

export default LocationsManager;
