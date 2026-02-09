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
  Fab,
  Chip,
  Checkbox,
  ListItemText,
  FormControl,
  InputLabel,
  Select,
  FormHelperText,
  Alert
} from '@mui/material';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Assignment as AssignmentIcon,
  LocationOn as LocationIcon
} from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRenderCellParams } from '@mui/x-data-grid';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs from 'dayjs';
import AdminLayout from './AdminLayout';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import EmptyState from '../Common/EmptyState';
import api, { taskLocationsApi } from '../../services/api';
import { useNotification } from '../../contexts/NotificationContext';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import { useAuth } from '../Auth/AuthContext';
import { formatTenantDate, getTenantDatePickerFormat } from '../../utils/tenantFormatting';
import { useTranslation } from 'react-i18next';

interface Task {
  id: number;
  name: string;
  description?: string;
  project_id?: number;
  task_type?: string;
  estimated_hours?: number | null;
  start_date?: string | null;
  end_date?: string | null;
  progress?: number;
  dependencies?: string[] | null;
  is_active?: boolean;
  locations?: Location[];
}

interface Location {
  id: number;
  name: string;
  city: string;
  country: string;
}

interface Project {
  id: number;
  name: string;
}

const normalizeApiResponse = <T,>(payload: any): T[] => {
  // If payload is already an array, return it
  if (Array.isArray(payload)) {
    return payload;
  }

  // If payload has a data property that's an array, return it
  if (payload && Array.isArray(payload.data)) {
    return payload.data;
  }

  // If payload is wrapped in success/data structure
  if (payload && payload.success && Array.isArray(payload.data)) {
    return payload.data;
  }

  return [];
};

const getTaskProgressRaw = (task: any): unknown => {
  if (!task || typeof task !== 'object') return undefined;
  return (
    task.progress ??
    task.progress_percent ??
    task.progress_percentage ??
    task.completion ??
    task.completion_percent
  );
};

const normalizeProgressToPercent = (raw: unknown): number => {
  if (raw == null) return 0;
  const n = typeof raw === 'number' ? raw : typeof raw === 'string' ? Number(raw) : NaN;
  if (!Number.isFinite(n)) return 0;

  const percent = n >= 0 && n <= 1 ? n * 100 : n;
  if (!Number.isFinite(percent)) return 0;

  return Math.max(0, Math.min(100, Math.round(percent)));
};

const TasksManager: React.FC = () => {
  const { t } = useTranslation();
  const { tenantContext } = useAuth();
  const datePickerFormat = getTenantDatePickerFormat(tenantContext);
  const { showSuccess, showError } = useNotification();
  const { isReadOnly, ensureWritable } = useReadOnlyGuard('admin-tasks');
  const [tasks, setTasks] = useState<Task[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);
  const [openDialog, setOpenDialog] = useState(false);
  const [editingTask, setEditingTask] = useState<Task | null>(null);
  const [confirmDialog, setConfirmDialog] = useState({ 
    open: false, 
    title: '', 
    message: '', 
    recordDetails: {} as any,
    action: (() => {}) as () => void | Promise<void>
  });
  
  // Location management state
  const [locationDialog, setLocationDialog] = useState({
    open: false,
    task: null as Task | null
  });
  const [allLocations, setAllLocations] = useState<Location[]>([]);
  const [selectedLocationIds, setSelectedLocationIds] = useState<number[]>([]);
  
  const taskTypes = [
    'retrofit',
    'inspection',
    'commissioning',
    'maintenance',
    'installation',
    'testing',
    'documentation',
    'training'
  ];

  const [formData, setFormData] = useState({
    name: '',
    description: '',
    project_id: '',
    task_type: 'maintenance',
    estimated_hours: '',
    start_date: '',
    end_date: '',
    progress: '0',
    dependencies: '',
    is_active: true as boolean
  });

  useEffect(() => {
    fetchTasks();
    fetchProjects();
  }, []);

  const fetchTasks = async () => {
    try {
      setLoading(true);
      const response = await api.get('/api/tasks');
      // Normalize is_active to boolean for all tasks
      setTasks(
        normalizeApiResponse<Task>(response.data).map(t => ({
          ...t,
          is_active: t.is_active === true,
        }))
      );
    } catch (error) {
      showError(t('admin.tasks.notifications.loadFailed'));
    } finally {
      setLoading(false);
    }
  };

  const fetchProjects = async () => {
    try {
      const response = await api.get('/api/projects');
      setProjects(normalizeApiResponse<Project>(response.data));
      
      // Load all locations for the location management dialog
      const locationsResponse = await api.get('/api/locations');
      setAllLocations(normalizeApiResponse<Location>(locationsResponse.data));
    } catch (error) {
      console.error('Failed to load projects and locations');
      showError(t('admin.tasks.notifications.loadProjectsLocationsFailed'));
    }
  };

  const handleOpenDialog = (task?: Task) => {
    if (!ensureWritable()) {
      return;
    }
    if (task) {
      setEditingTask(task);
      setFormData({
        name: task.name,
        description: task.description || '',
        project_id: task.project_id?.toString() || '',
        task_type: task.task_type || 'maintenance',
        estimated_hours: task.estimated_hours?.toString() || '',
        start_date: task.start_date ?? '',
        end_date: task.end_date ?? '',
        progress: (task.progress ?? 0).toString(),
        dependencies: (task.dependencies ?? []).join(', '),
        is_active: !!task.is_active
      });
    } else {
      setEditingTask(null);
      setFormData({
        name: '',
        description: '',
        project_id: '',
        task_type: 'maintenance',
        estimated_hours: '',
        start_date: '',
        end_date: '',
        progress: '0',
        dependencies: '',
        is_active: true
      });
    }
    setOpenDialog(true);
  };

  const handleCloseDialog = () => {
    setOpenDialog(false);
    setEditingTask(null);
  };
  
  // Location management handlers
  const handleManageLocations = async (task: Task) => {
    if (!ensureWritable()) {
      return;
    }
    try {
      // Load current locations for this task
      const response = await taskLocationsApi.get(task.id);
      const currentLocations = response.data.locations || [];
      const currentLocationIds = currentLocations.map((loc: Location) => loc.id);
      
      setSelectedLocationIds(currentLocationIds);
      setLocationDialog({ open: true, task });
    } catch (error) {
      console.error('Error loading task locations:', error);
      showError(t('admin.tasks.locationDialog.notifications.loadTaskLocationsFailed'));
    }
  };
  
  const handleSaveLocations = async () => {
    if (!locationDialog.task) return;
    if (!ensureWritable()) {
      return;
    }
    
    try {
      setLoading(true);
      await taskLocationsApi.sync(locationDialog.task.id, selectedLocationIds);
      
      // Refresh tasks to show updated location count
      await fetchTasks();
      
      showSuccess(
        selectedLocationIds.length === 0 
          ? t('admin.tasks.locationDialog.notifications.allLocationsRemoved')
          : t('admin.tasks.locationDialog.notifications.updatedWithLocations', { count: selectedLocationIds.length })
      );
      
      setLocationDialog({ open: false, task: null });
      setSelectedLocationIds([]);
    } catch (error) {
      console.error('Error updating task locations:', error);
      showError(t('admin.tasks.locationDialog.notifications.updateFailed'));
    } finally {
      setLoading(false);
    }
  };
  
  const handleCloseLocationDialog = () => {
    setLocationDialog({ open: false, task: null });
    setSelectedLocationIds([]);
  };

  const handleSave = async (e?: React.FormEvent) => {
    if (e) {
      e.preventDefault();
    }

    if (!ensureWritable()) {
      return;
    }

    try {
      const payload = {
        name: formData.name,
        description: formData.description || null,
        project_id: formData.project_id ? parseInt(formData.project_id, 10) : null,
        task_type: formData.task_type,
        estimated_hours: formData.estimated_hours ? parseFloat(formData.estimated_hours) : null,
        start_date: formData.start_date || null,
        end_date: formData.end_date || null,
        progress: formData.progress ? parseInt(formData.progress, 10) : 0,
        dependencies: formData.dependencies
          ? formData.dependencies.split(',').map(dep => dep.trim()).filter(Boolean)
          : [],
        is_active: formData.is_active === true,
      };

      if (editingTask) {
        await api.put(`/api/tasks/${editingTask.id}`, payload);
        showSuccess(t('admin.tasks.notifications.updateSuccess'));
      } else {
        await api.post('/api/tasks', payload);
        showSuccess(t('admin.tasks.notifications.createSuccess'));
      }
      fetchTasks();
      handleCloseDialog();
    } catch (error) {
      showError(t('admin.tasks.notifications.saveFailed'));
    }
  };

  const handleDelete = async (id: number) => {
    if (!ensureWritable()) {
      return;
    }
    const task = tasks.find(t => t.id === id);
    setConfirmDialog({
      open: true,
      title: t('admin.tasks.confirmDelete.title'),
      message: t('admin.tasks.confirmDelete.message'),
      recordDetails: {
        name: task?.name,
        description: task?.description,
        task_type: task?.task_type
      },
      action: async () => {
        if (!ensureWritable()) {
          return;
        }
        try {
          await api.delete(`/api/tasks/${id}`);
          showSuccess(t('admin.tasks.notifications.deleteSuccess'));
          fetchTasks();
        } catch (error: any) {
          showError(error?.response?.data?.message || t('admin.tasks.notifications.deleteFailed'));
        }
        setConfirmDialog({ ...confirmDialog, open: false });
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
      headerName: t('admin.shared.columns.name'),
      flex: 1,
      minWidth: 200
    },
    {
      field: 'description',
      headerName: t('admin.shared.columns.description'),
      flex: 1.2,
      minWidth: 220
    },
    {
      field: 'task_type',
      headerName: t('admin.tasks.columns.type'),
      width: 150
    },
    {
      field: 'start_date',
      headerName: t('admin.tasks.columns.start'),
      width: 140,
      renderCell: ({ value }) => {
        if (!value) return <span style={{ color: '#999' }}>-</span>;
        return <span>{formatTenantDate(value, tenantContext)}</span>;
      }
    },
    {
      field: 'end_date',
      headerName: t('admin.tasks.columns.end'),
      width: 140,
      renderCell: ({ value }) => {
        if (!value) return <span style={{ color: '#999' }}>-</span>;
        return <span>{formatTenantDate(value, tenantContext)}</span>;
      }
    },
    {
      field: 'progress',
      headerName: t('admin.tasks.columns.progress'),
      width: 120,
      type: 'number',
      valueGetter: (params: any) => normalizeProgressToPercent(getTaskProgressRaw(params?.row)),
      renderCell: (params: GridRenderCellParams) => {
        const value = normalizeProgressToPercent(getTaskProgressRaw(params.row));
        return <span>{value}%</span>;
      }
    },
    {
      field: 'project_id',
      headerName: t('admin.tasks.columns.project'),
      width: 200,
      renderCell: (params: GridRenderCellParams<Task>) => {
        const project = projects.find(p => p.id === params.row.project_id);
        return <span>{project ? project.name : '-'}</span>;
      }
    },
    {
      field: 'locations',
      headerName: t('admin.tasks.columns.locations'),
      width: 140,
      sortable: false,
      renderCell: (params: GridRenderCellParams<Task>) => {
        const count = params.row.locations?.length || 0;
        return (
          <Chip
            icon={<LocationIcon />}
            label={
              count === 0
                ? t('admin.tasks.locations.none')
                : t('admin.tasks.locations.count', { count })
            }
            size="small"
            color={count > 0 ? 'primary' : 'default'}
            variant={count > 0 ? 'filled' : 'outlined'}
            onClick={
              isReadOnly
                ? undefined
                : (e) => {
                    e.stopPropagation();
                    handleManageLocations(params.row);
                  }
            }
            sx={{ cursor: isReadOnly ? 'default' : 'pointer' }}
          />
        );
      }
    },
    {
      field: 'is_active',
      headerName: t('admin.shared.columns.status'),
      width: 120,
      renderCell: (params: GridRenderCellParams) => {
        const isActive = params.row.is_active === true || params.row.is_active === 1 || params.row.is_active === '1';
        return (
          <Box
            sx={{
              px: 1.5,
              py: 0.5,
              borderRadius: 1,
              fontSize: '0.75rem',
              fontWeight: 600,
              textTransform: 'uppercase',
              bgcolor: isActive ? '#4caf5015' : '#ff980015',
              color: isActive ? '#4caf50' : '#ff9800'
            }}
          >
            {isActive ? t('admin.shared.status.active') : t('admin.shared.status.inactive')}
          </Box>
        );
      }
    },
    {
      field: 'actions',
      headerName: t('admin.shared.columns.actions'),
      width: 160,
      sortable: false,
      renderCell: (params: GridRenderCellParams) => (
        <Box>
          <IconButton
            size="small"
            disabled={isReadOnly}
            onClick={(e) => {
              e.stopPropagation();
              handleManageLocations(params.row as Task);
            }}
            sx={{ color: '#2196f3' }}
            title={t('admin.tasks.actions.manageLocations')}
          >
            <LocationIcon fontSize="small" />
          </IconButton>
          <IconButton
            size="small"
            disabled={isReadOnly}
            onClick={(e) => {
              e.stopPropagation();
              handleOpenDialog(params.row as Task);
            }}
            sx={{ color: '#43a047' }}
          >
            <EditIcon fontSize="small" />
          </IconButton>
          <IconButton
            size="small"
            disabled={isReadOnly}
            onClick={(e) => {
              e.stopPropagation();
              handleDelete(params.row.id);
            }}
            sx={{ color: '#f44336' }}
          >
            <DeleteIcon fontSize="small" />
          </IconButton>
        </Box>
      )
    }
  ];

  return (
    <AdminLayout title={t('admin.tasks.managementTitle')}>
      {!loading && tasks.length === 0 ? (
        <EmptyState
          icon={AssignmentIcon}
          title={t('admin.tasks.empty.title')}
          subtitle={t('admin.tasks.empty.subtitle')}
          actionLabel={t('admin.tasks.actions.new')}
          onAction={() => {
            if (!ensureWritable()) {
              return;
            }
            handleOpenDialog();
          }}
        />
      ) : (
        <Box sx={{ width: '100%', overflowX: 'auto' }}>
          <DataGrid
            autoHeight
            rows={tasks}
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
      {tasks.length > 0 && (
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
            {editingTask ? t('admin.tasks.actions.edit') : t('admin.tasks.actions.new')}
        </DialogTitle>
        <DialogContent>
          <Box component="form" onSubmit={handleSave} id="task-form" sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 1 }}>
            <TextField
                label={t('common.name')}
              fullWidth
              required
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            />
            <TextField
                label={t('common.description')}
              fullWidth
              multiline
              rows={3}
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
            />
            <TextField
              select
                label={t('admin.tasks.fields.project')}
              fullWidth
              required
              value={formData.project_id}
              onChange={(e) => setFormData({ ...formData, project_id: e.target.value })}
            >
              {projects.map((project) => (
                <MenuItem key={project.id} value={project.id.toString()}>
                  {project.name}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              select
              label={t('admin.tasks.fields.taskType')}
              value={formData.task_type}
              onChange={(e) => setFormData({ ...formData, task_type: e.target.value })}
            >
              {taskTypes.map((type) => (
                <MenuItem key={type} value={type}>
                  {type.charAt(0).toUpperCase() + type.slice(1)}
                </MenuItem>
              ))}
            </TextField>
            <TextField
              label={t('admin.tasks.fields.estimatedHours')}
              type="number"
              inputProps={{ min: 0, step: 0.25 }}
              value={formData.estimated_hours}
              onChange={(e) => setFormData({ ...formData, estimated_hours: e.target.value })}
            />
            <Box sx={{ display: 'flex', gap: 2 }}>
              <DatePicker
                label={t('admin.tasks.fields.startDate')}
                value={formData.start_date ? dayjs(formData.start_date) : null}
                onChange={(newValue) => setFormData({ ...formData, start_date: newValue ? newValue.format('YYYY-MM-DD') : '' })}
                format={datePickerFormat}
                slotProps={{ textField: { fullWidth: true } }}
              />
              <DatePicker
                label={t('admin.tasks.fields.endDate')}
                value={formData.end_date ? dayjs(formData.end_date) : null}
                onChange={(newValue) => setFormData({ ...formData, end_date: newValue ? newValue.format('YYYY-MM-DD') : '' })}
                format={datePickerFormat}
                slotProps={{ textField: { fullWidth: true } }}
              />
            </Box>
            <TextField
              label={t('admin.tasks.fields.progress')}
              type="number"
              inputProps={{ min: 0, max: 100 }}
              value={formData.progress}
              onChange={(e) => setFormData({ ...formData, progress: e.target.value })}
            />
            <TextField
              label={t('admin.tasks.fields.dependencies')}
              fullWidth
              value={formData.dependencies}
              onChange={(e) => setFormData({ ...formData, dependencies: e.target.value })}
            />
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <TextField
                select
                label={t('admin.shared.columns.status')}
                value={formData.is_active ? 'active' : 'inactive'}
                onChange={(e) => setFormData({ ...formData, is_active: e.target.value === 'active' })}
                sx={{ width: 200 }}
              >
                <MenuItem value="active">{t('admin.shared.status.active')}</MenuItem>
                <MenuItem value="inactive">{t('admin.shared.status.inactive')}</MenuItem>
              </TextField>
            </Box>
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseDialog}>{t('common.cancel')}</Button>
          <Button
            type="submit"
            form="task-form"
            variant="contained"
            color="primary"
            disabled={isReadOnly}
          >
            {editingTask ? t('common.update') : t('common.create')}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Location Management Dialog */}
      <Dialog
        open={locationDialog.open}
        onClose={handleCloseLocationDialog}
        maxWidth="sm"
        fullWidth
      >
        <DialogTitle>
          {t('admin.tasks.locationDialog.title', { taskName: locationDialog.task?.name || '' })}
        </DialogTitle>
        <DialogContent>
          <Box sx={{ pt: 2 }}>
            {allLocations.length === 0 ? (
              <Alert severity="info">
                {t('admin.tasks.locationDialog.emptyLocations')}
              </Alert>
            ) : (
              <FormControl fullWidth>
                <InputLabel id="location-select-label">{t('admin.tasks.locationDialog.selectLabel')}</InputLabel>
                <Select
                  labelId="location-select-label"
                  id="location-select"
                  multiple
                  value={selectedLocationIds}
                  disabled={isReadOnly}
                  onChange={(e) => {
                    const value = e.target.value;
                    setSelectedLocationIds(
                      typeof value === 'string' ? [] : value
                    );
                  }}
                  label={t('admin.tasks.locationDialog.selectLabel')}
                  renderValue={(selected) => {
                    if (selected.length === 0) {
                      return <em>{t('admin.tasks.locationDialog.noneSelected')}</em>;
                    }
                    return t('admin.tasks.locationDialog.selectedCount', { count: selected.length });
                  }}
                >
                  {allLocations.map((location) => (
                    <MenuItem key={location.id} value={location.id}>
                      <Checkbox checked={selectedLocationIds.indexOf(location.id) > -1} />
                      <ListItemText
                        primary={location.name}
                        secondary={`${location.city}, ${location.country}`}
                      />
                    </MenuItem>
                  ))}
                </Select>
                <FormHelperText>
                  {t('admin.tasks.locationDialog.helper')}
                </FormHelperText>
              </FormControl>
            )}
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseLocationDialog}>{t('common.cancel')}</Button>
          {selectedLocationIds.length > 0 && (
            <Button
              onClick={() => {
                setSelectedLocationIds([]);
              }}
              color="warning"
              disabled={isReadOnly}
            >
              {t('common.clearAll')}
            </Button>
          )}
          <Button
            onClick={handleSaveLocations}
            variant="contained"
            color="primary"
            disabled={allLocations.length === 0 || isReadOnly}
          >
            {t('common.save')}
          </Button>
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

export default TasksManager;
