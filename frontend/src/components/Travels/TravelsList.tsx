import React, { useEffect, useMemo, useState } from 'react';
import { Box, Card, CardContent, Typography, IconButton, Chip, CircularProgress, Fab } from '@mui/material';
import { Add as AddIcon, Edit as EditIcon, Delete as DeleteIcon, Flight as FlightIcon } from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRenderCellParams } from '@mui/x-data-grid';
import { useTranslation } from 'react-i18next';

import ConfirmationDialog from '../Common/ConfirmationDialog';
import EmptyState from '../Common/EmptyState';
import PageHeader from '../Common/PageHeader';
import { useNotification } from '../../contexts/NotificationContext';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import { useAuth } from '../Auth/AuthContext';
import { formatTenantDateTime } from '../../utils/tenantFormatting';
import TravelForm from './TravelForm';
import { travelsApi } from '../../services/travels';
import type { TravelSegment } from '../../services/travels';

const extractRows = (payload: any): TravelSegment[] => {
  if (Array.isArray(payload)) {
    return payload;
  }

  if (Array.isArray(payload?.data)) {
    return payload.data;
  }

  return [];
};

const TravelsList: React.FC = () => {
  const { t } = useTranslation();
  const { showSuccess, showError } = useNotification();
  const { tenantContext } = useAuth();
  const { isReadOnly, ensureWritable, warn } = useReadOnlyGuard('travels');

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

  const fetchTravels = async () => {
    try {
      setLoading(true);
      const response = await travelsApi.getAll();
      setTravels(extractRows(response));
    } catch (error: any) {
      showError(error?.response?.data?.error || t('approvals.travels.errors.loadFailed'));
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchTravels();
  }, []);

  const handleOpenDialog = (travel?: TravelSegment) => {
    if (!ensureWritable()) {
      return;
    }
    setEditingTravel(travel ?? null);
    setOpenDialog(true);
  };

  const handleCloseDialog = () => {
    setOpenDialog(false);
    setEditingTravel(null);
  };

  const handleDelete = (id: number) => {
    if (!ensureWritable()) {
      return;
    }

    const travel = travels.find((item) => item.id === id);
    const originName =
      travel?.origin_location?.name ||
      travel?.origin_country ||
      t('approvals.travels.fallbacks.origin');
    const destName =
      travel?.destination_location?.name ||
      travel?.destination_country ||
      t('approvals.travels.fallbacks.destination');

    setConfirmDialog({
      open: true,
      title: t('approvals.travels.delete.title'),
      message: t('approvals.travels.delete.message', { origin: originName, destination: destName }),
      action: async () => {
        if (!ensureWritable()) {
          return;
        }
        try {
          await travelsApi.delete(id);
          showSuccess(t('approvals.travels.toast.deleted'));
          fetchTravels();
        } catch (error: any) {
          showError(error?.response?.data?.message || t('approvals.travels.errors.deleteFailed'));
        }
        setConfirmDialog((prev) => ({ ...prev, open: false }));
      },
    });
  };

  const getDirectionColor = (direction?: string) => {
    switch (direction) {
      case 'departure':
        return '#f44336';
      case 'arrival':
        return '#4caf50';
      case 'project_to_project':
        return '#2196f3';
      case 'internal':
        return '#ff9800';
      default:
        return '#757575';
    }
  };

  const getStatusColor = (status?: string) => {
    switch (status) {
      case 'completed':
        return '#4caf50';
      case 'planned':
        return '#ff9800';
      case 'cancelled':
      default:
        return '#9e9e9e';
    }
  };

  const columns = useMemo<GridColDef[]>(
    () => [
      {
        field: 'start_at',
        headerName: t('approvals.travels.table.start'),
        width: 160,
        renderCell: ({ row }: GridRenderCellParams<TravelSegment>) =>
          row.start_at ? formatTenantDateTime(row.start_at, tenantContext) : t('common.notAvailable'),
      },
      {
        field: 'end_at',
        headerName: t('approvals.travels.table.end'),
        width: 160,
        renderCell: ({ row }: GridRenderCellParams<TravelSegment>) =>
          row.end_at ? formatTenantDateTime(row.end_at, tenantContext) : t('common.notAvailable'),
      },
      {
        field: 'duration_minutes',
        headerName: t('approvals.travels.table.duration'),
        width: 120,
        valueGetter: (_value, row: TravelSegment) => row.duration_minutes ?? 0,
        renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => {
          const duration = Number(row.duration_minutes ?? 0);
          const hours = Math.floor(duration / 60);
          const minutes = duration % 60;
          return t('approvals.travels.durationValue', {
            hours,
            minutes: String(minutes).padStart(2, '0'),
          });
        },
      },
      {
        field: 'technician',
        headerName: t('approvals.travels.table.technician'),
        flex: 1,
        minWidth: 150,
        valueGetter: (_value, row: TravelSegment) => row.technician?.name || '',
        renderCell: ({ row }: GridRenderCellParams<TravelSegment>) =>
          row.technician?.name || t('common.notAvailable'),
      },
      {
        field: 'project',
        headerName: t('approvals.travels.table.project'),
        flex: 1,
        minWidth: 150,
        valueGetter: (_value, row: TravelSegment) => row.project?.name || '',
        renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => row.project?.name || t('common.notAvailable'),
      },
      {
        field: 'route',
        headerName: t('approvals.travels.table.route'),
        flex: 1.5,
        minWidth: 220,
        sortable: false,
        renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => (
          <Box>
            <Typography variant="body2" sx={{ fontWeight: 600 }}>
              {row.origin_country} → {row.destination_country}
            </Typography>
            {(row.origin_location || row.destination_location) && (
              <Typography variant="caption" color="text.secondary">
                {row.origin_location?.name || t('common.notAvailable')} →{' '}
                {row.destination_location?.name || t('common.notAvailable')}
              </Typography>
            )}
          </Box>
        ),
      },
      {
        field: 'direction',
        headerName: t('approvals.travels.table.direction'),
        width: 160,
        renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => (
          <Chip
            label={t(`approvals.travels.directionLabels.${row.direction}`)}
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
        headerName: t('approvals.travels.table.status'),
        width: 130,
        renderCell: ({ row }: GridRenderCellParams<TravelSegment>) => (
          <Chip
            label={t(`approvals.travels.statusLabels.${row.status}`)}
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
        headerName: t('approvals.travels.table.actions'),
        width: 120,
        sortable: false,
        renderCell: (params: GridRenderCellParams) => {
          const travel = params.row as TravelSegment;
          const isLocked = travel.status === 'completed';

          return (
            <Box>
              <IconButton
                size="small"
                onClick={() => handleOpenDialog(travel)}
                disabled={isReadOnly || isLocked}
                sx={{ color: isLocked ? '#ccc' : '#667eea' }}
              >
                <EditIcon fontSize="small" />
              </IconButton>
              <IconButton
                size="small"
                onClick={() => handleDelete(travel.id)}
                disabled={isReadOnly || isLocked}
                sx={{ color: isLocked ? '#ccc' : '#f44336' }}
              >
                <DeleteIcon fontSize="small" />
              </IconButton>
            </Box>
          );
        },
      },
    ],
    [t, tenantContext, isReadOnly, travels]
  );

  return (
    <Box sx={{ height: '100%', display: 'flex', flexDirection: 'column', overflow: 'hidden' }}>
      <PageHeader
        title={t('approvals.travels.management.title')}
        subtitle={t('approvals.travels.management.subtitle')}
      />

      <Box sx={{ flex: 1, overflow: 'auto', p: 2 }}>
        {loading ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', p: 4 }}>
            <CircularProgress />
          </Box>
        ) : travels.length === 0 ? (
          <EmptyState
            icon={FlightIcon}
            title={t('approvals.travels.management.emptyTitle')}
            subtitle={t('approvals.travels.management.emptySubtitle')}
            actionLabel={t('approvals.travels.management.newTravel')}
            onAction={isReadOnly ? warn : () => handleOpenDialog()}
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

      {travels.length > 0 && (
        <Fab
          color="primary"
          aria-label={t('approvals.travels.management.addAria')}
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
              background: 'linear-gradient(135deg, #5568d3 0%, #65408b 100%)',
            },
          }}
        >
          <AddIcon />
        </Fab>
      )}

      <TravelForm open={openDialog} onClose={handleCloseDialog} onSave={fetchTravels} editingTravel={editingTravel} />

      <ConfirmationDialog
        open={confirmDialog.open}
        title={confirmDialog.title}
        message={confirmDialog.message}
        confirmText={t('common.delete')}
        cancelText={t('common.cancel')}
        confirmColor="error"
        onConfirm={() => confirmDialog.action()}
        onCancel={() => setConfirmDialog((prev) => ({ ...prev, open: false }))}
      />
    </Box>
  );
};

export default TravelsList;
