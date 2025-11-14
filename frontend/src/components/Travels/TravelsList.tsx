import React, { useState, useEffect } from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  IconButton,
  Chip,
  CircularProgress,
  Fab,
} from '@mui/material';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Flight as FlightIcon,
} from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRenderCellParams } from '@mui/x-data-grid';
import { useNotification } from '../../contexts/NotificationContext';
import { travelsApi } from '../../services/travels';
import type { TravelSegment } from '../../services/travels';
import TravelForm from './TravelForm.tsx';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import EmptyState from '../Common/EmptyState';
import PageHeader from '../Common/PageHeader';

const TravelsList: React.FC = () => {
  const { showSuccess, showError } = useNotification();
  const [travels, setTravels] = useState<TravelSegment[]>([]);
  const [loading, setLoading] = useState(true);
  const [openDialog, setOpenDialog] = useState(false);
  const [editingTravel, setEditingTravel] = useState<TravelSegment | null>(null);
  const [confirmDialog, setConfirmDialog] = useState({
    open: false,
    title: '',
    message: '',
    action: (() => {}) as () => void | Promise<void>,
  });

  useEffect(() => {
    fetchTravels();
  }, []);

  const fetchTravels = async () => {
    try {
      setLoading(true);
      const response = await travelsApi.getAll();
      setTravels(response.data || []);
    } catch (error) {
      showError('Failed to load travel segments');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (travel?: TravelSegment) => {
    setEditingTravel(travel || null);
    setOpenDialog(true);
  };

  const handleCloseDialog = () => {
    setOpenDialog(false);
    setEditingTravel(null);
  };

  const handleDelete = (id: number) => {
    const travel = travels.find(t => t.id === id);
    const originName = travel?.origin_location?.name || travel?.origin_country || 'origin';
    const destName = travel?.destination_location?.name || travel?.destination_country || 'destination';
    
    setConfirmDialog({
      open: true,
      title: 'Delete Travel Segment',
      message: `Are you sure you want to delete this travel from ${originName} to ${destName}?`,
      action: async () => {
        try {
          await travelsApi.delete(id);
          showSuccess('Travel segment deleted successfully');
          fetchTravels();
        } catch (error) {
          showError('Failed to delete travel segment');
        }
        setConfirmDialog({ ...confirmDialog, open: false });
      },
    });
  };

  const getDirectionColor = (direction: string) => {
    switch (direction) {
      case 'departure': return '#f44336';
      case 'arrival': return '#4caf50';
      case 'project_to_project': return '#2196f3';
      case 'internal': return '#ff9800';
      default: return '#757575';
    }
  };

  const getStatusColor = (status: string) => {
    // Matching timesheet status colors (MUI theme palette)
    switch (status) {
      case 'completed': return '#4caf50';  // success (approved)
      case 'planned': return '#ff9800';    // warning (submitted)
      case 'cancelled': return '#9e9e9e';  // default (draft/closed)
      default: return '#9e9e9e';
    }
  };

  const columns: GridColDef[] = [
    {
      field: 'start_at',
      headerName: 'Start',
      width: 160,
      renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => 
        row.start_at ? new Date(row.start_at).toLocaleString() : '-',
    },
    {
      field: 'end_at',
      headerName: 'End',
      width: 160,
      renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => 
        row.end_at ? new Date(row.end_at).toLocaleString() : '-',
    },
    {
      field: 'duration_minutes',
      headerName: 'Duration',
      width: 100,
      renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => {
        if (!row.duration_minutes) return '-';
        const hours = Math.floor(row.duration_minutes / 60);
        const minutes = row.duration_minutes % 60;
        return `${hours}h ${String(minutes).padStart(2, '0')}m`;
      },
    },
    {
      field: 'technician',
      headerName: 'Technician',
      flex: 1,
      minWidth: 150,
      renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => row.technician?.name || '-',
    },
    {
      field: 'project',
      headerName: 'Project',
      flex: 1,
      minWidth: 150,
      renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => row.project?.name || '-',
    },
    {
      field: 'route',
      headerName: 'Route',
      flex: 1.5,
      minWidth: 200,
      renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => (
        <Box>
          <Typography variant="body2" sx={{ fontWeight: 600 }}>
            {row.origin_country} → {row.destination_country}
          </Typography>
          {(row.origin_location || row.destination_location) && (
            <Typography variant="caption" color="text.secondary">
              {row.origin_location?.name || '—'} → {row.destination_location?.name || '—'}
            </Typography>
          )}
        </Box>
      ),
    },
    {
      field: 'direction',
      headerName: 'Direction',
      width: 160,
      renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => (
        <Chip
          label={row.direction.replace('_', ' ')}
          size="small"
          sx={{
            bgcolor: `${getDirectionColor(row.direction)}15`,
            color: getDirectionColor(row.direction),
            fontWeight: 600,
            textTransform: 'capitalize',
          }}
        />
      ),
    },
    {
      field: 'status',
      headerName: 'Status',
      width: 130,
      renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => (
        <Chip
          label={row.status}
          size="small"
          sx={{
            bgcolor: `${getStatusColor(row.status)}15`,
            color: getStatusColor(row.status),
            fontWeight: 600,
            textTransform: 'capitalize',
          }}
        />
      ),
    },
    {
      field: 'actions',
      headerName: 'Actions',
      width: 120,
      sortable: false,
      renderCell: (params: GridRenderCellParams) => {
        const travel = params.row as TravelSegment;
        const canEdit = travel.status !== 'completed';
        const canDelete = travel.status !== 'completed';

        return (
          <Box>
            <IconButton
              size="small"
              onClick={() => handleOpenDialog(travel)}
              disabled={!canEdit}
              sx={{ color: canEdit ? '#667eea' : '#ccc' }}
            >
              <EditIcon fontSize="small" />
            </IconButton>
            <IconButton
              size="small"
              onClick={() => handleDelete(travel.id)}
              disabled={!canDelete}
              sx={{ color: canDelete ? '#f44336' : '#ccc' }}
            >
              <DeleteIcon fontSize="small" />
            </IconButton>
          </Box>
        );
      },
    },
  ];

  return (
    <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
      <PageHeader
        title="Travel Management"
        subtitle="Track technician travel segments and movement between project locations"
      />

      <Box sx={{ flex: 1, overflow: 'auto', p: 2 }}>
        {loading ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', p: 4 }}>
            <CircularProgress />
          </Box>
        ) : travels.length === 0 ? (
          <EmptyState
            icon={FlightIcon}
            title="No travel segments yet"
            subtitle="Create your first travel segment to start tracking technician movements"
            actionLabel="New Travel"
            onAction={() => handleOpenDialog()}
          />
        ) : (
          <Card>
            <CardContent>
            <DataGrid
              rows={travels}
              columns={columns}
              loading={loading}
              pageSizeOptions={[10, 25, 50]}
              initialState={{
                pagination: { paginationModel: { pageSize: 10 } },
              }}
              disableRowSelectionOnClick
              sx={{
                border: 'none',
                '& .MuiDataGrid-cell:focus': { outline: 'none' },
                '& .MuiDataGrid-row:hover': { bgcolor: 'rgba(102, 126, 234, 0.04)' },
                '& .MuiDataGrid-columnHeaders': {
                  bgcolor: 'rgba(102, 126, 234, 0.08)',
                  borderRadius: '8px 8px 0 0',
                },
              }}
              />
            </CardContent>
          </Card>
        )}
      </Box>

      {/* Floating Action Button - always visible when there are travels */}
      {travels.length > 0 && (
                <Fab
          color="primary"
          aria-label="add travel"
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

      <TravelForm
        open={openDialog}
        onClose={handleCloseDialog}
        onSave={fetchTravels}
        editingTravel={editingTravel}
      />

      <ConfirmationDialog
        open={confirmDialog.open}
        title={confirmDialog.title}
        message={confirmDialog.message}
        onConfirm={confirmDialog.action}
        onCancel={() => setConfirmDialog({ ...confirmDialog, open: false })}
      />
    </Box>
  );
};

export default TravelsList;
