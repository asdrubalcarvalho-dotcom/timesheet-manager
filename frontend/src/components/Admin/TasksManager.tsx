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
  Fab
} from '@mui/material';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Assignment as AssignmentIcon
} from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRenderCellParams } from '@mui/x-data-grid';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs from 'dayjs';
import AdminLayout from './AdminLayout';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import EmptyState from '../Common/EmptyState';
import api from '../../services/api';
import { useNotification } from '../../contexts/NotificationContext';

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

const TasksManager: React.FC = () => {
  const { showSuccess, showError } = useNotification();
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
      const response = await api.get('/tasks');
      // Normalize is_active to boolean for all tasks
      setTasks(
        normalizeApiResponse<Task>(response.data).map(t => ({
          ...t,
          is_active: t.is_active === true,
        }))
      );
    } catch (error) {
      showError('Failed to load tasks');
    } finally {
      setLoading(false);
    }
  };

  const fetchProjects = async () => {
    try {
      const response = await api.get('/projects');
      setProjects(normalizeApiResponse<Project>(response.data));
    } catch (error) {
      console.error('Failed to load projects');
    }
  };

  const handleOpenDialog = (task?: Task) => {
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

  const handleSave = async (e?: React.FormEvent) => {
    if (e) {
      e.preventDefault();
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
        await api.put(`/tasks/${editingTask.id}`, payload);
        showSuccess('Task updated successfully');
      } else {
        await api.post('/tasks', payload);
        showSuccess('Task created successfully');
      }
      fetchTasks();
      handleCloseDialog();
    } catch (error) {
      showError('Failed to save task');
    }
  };

  const handleDelete = async (id: number) => {
    const task = tasks.find(t => t.id === id);
    setConfirmDialog({
      open: true,
      title: 'Delete Task',
      message: 'Are you sure you want to delete this task? This action cannot be undone.',
      recordDetails: {
        name: task?.name,
        description: task?.description,
        task_type: task?.task_type
      },
      action: async () => {
        try {
          await api.delete(`/tasks/${id}`);
          showSuccess('Task deleted successfully');
          fetchTasks();
        } catch (error) {
          showError('Failed to delete task');
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
      field: 'description',
      headerName: 'Description',
      flex: 1.2,
      minWidth: 220
    },
    {
      field: 'task_type',
      headerName: 'Type',
      width: 150
    },
    {
      field: 'start_date',
      headerName: 'Start',
      width: 140,
      renderCell: ({ value }) => {
        if (!value) return <span style={{ color: '#999' }}>-</span>;
        return <span>{dayjs(value).format('DD/MM/YYYY')}</span>;
      }
    },
    {
      field: 'end_date',
      headerName: 'End',
      width: 140,
      renderCell: ({ value }) => {
        if (!value) return <span style={{ color: '#999' }}>-</span>;
        return <span>{dayjs(value).format('DD/MM/YYYY')}</span>;
      }
    },
    {
      field: 'progress',
      headerName: 'Progress',
      width: 120,
      valueFormatter: (params?: { value?: number | null }) => {
        const value = params?.value ?? 0;
        return `${value}%`;
      }
    },
    {
      field: 'project_id',
      headerName: 'Project',
      width: 200,
      renderCell: (params: GridRenderCellParams<Task>) => {
        const project = projects.find(p => p.id === params.row.project_id);
        return <span>{project ? project.name : '-'}</span>;
      }
    },
    {
      field: 'is_active',
      headerName: 'Status',
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
            {isActive ? 'Active' : 'Inactive'}
          </Box>
        );
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
            onClick={() => handleOpenDialog(params.row as Task)}
            sx={{ color: '#43a047' }}
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
    <AdminLayout title="Tasks Management">
      {!loading && tasks.length === 0 ? (
        <EmptyState
          icon={AssignmentIcon}
          title="No tasks yet"
          subtitle="Create your first task to start tracking project activities"
          actionLabel="New Task"
          onAction={() => handleOpenDialog()}
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
          {editingTask ? 'Edit Task' : 'New Task'}
        </DialogTitle>
        <DialogContent>
          <Box component="form" onSubmit={handleSave} id="task-form" sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 1 }}>
            <TextField
              label="Name"
              fullWidth
              required
              value={formData.name}
              onChange={(e) => setFormData({ ...formData, name: e.target.value })}
            />
            <TextField
              label="Description"
              fullWidth
              multiline
              rows={3}
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
            />
            <TextField
              select
              label="Project"
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
              label="Task Type"
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
              label="Estimated Hours"
              type="number"
              inputProps={{ min: 0, step: 0.25 }}
              value={formData.estimated_hours}
              onChange={(e) => setFormData({ ...formData, estimated_hours: e.target.value })}
            />
            <Box sx={{ display: 'flex', gap: 2 }}>
              <DatePicker
                label="Start Date"
                value={formData.start_date ? dayjs(formData.start_date) : null}
                onChange={(newValue) => setFormData({ ...formData, start_date: newValue ? newValue.format('YYYY-MM-DD') : '' })}
                slotProps={{ textField: { fullWidth: true } }}
              />
              <DatePicker
                label="End Date"
                value={formData.end_date ? dayjs(formData.end_date) : null}
                onChange={(newValue) => setFormData({ ...formData, end_date: newValue ? newValue.format('YYYY-MM-DD') : '' })}
                slotProps={{ textField: { fullWidth: true } }}
              />
            </Box>
            <TextField
              label="Progress"
              type="number"
              inputProps={{ min: 0, max: 100 }}
              value={formData.progress}
              onChange={(e) => setFormData({ ...formData, progress: e.target.value })}
            />
            <TextField
              label="Dependencies (comma separated ids)"
              fullWidth
              value={formData.dependencies}
              onChange={(e) => setFormData({ ...formData, dependencies: e.target.value })}
            />
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <TextField
                select
                label="Status"
                value={formData.is_active ? 'active' : 'inactive'}
                onChange={(e) => setFormData({ ...formData, is_active: e.target.value === 'active' })}
                sx={{ width: 200 }}
              >
                <MenuItem value="active">Active</MenuItem>
                <MenuItem value="inactive">Inactive</MenuItem>
              </TextField>
            </Box>
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseDialog}>Cancel</Button>
          <Button
            type="submit"
            form="task-form"
            variant="contained"
            color="primary"
          >
            {editingTask ? 'Update' : 'Create'}
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

export default TasksManager;
