import React, { useState, useEffect } from 'react';
import {
  Box,
  Button,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  TextField,
  IconButton
} from '@mui/material';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Group as GroupIcon
} from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRenderCellParams } from '@mui/x-data-grid';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs from 'dayjs';
import AdminLayout from './AdminLayout';
import ProjectMembersDialog from './ProjectMembersDialog';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import api from '../../services/api';
import { useNotification } from '../../contexts/NotificationContext';

interface Project {
  id: number;
  name: string;
  description?: string;
  start_date?: string;
  end_date?: string;
  status: 'active' | 'completed' | 'on_hold';
  manager_id?: number;
}

const formatDateForInput = (value?: string | null) => {
  if (!value) return '';
  const [dateAndTime] = value.split('T');
  const [dateOnly] = dateAndTime.split(' ');
  return dateOnly || '';
};

const ProjectsManager: React.FC = () => {
  const { showSuccess, showError } = useNotification();
  const [projects, setProjects] = useState<Project[]>([]);
  const [loading, setLoading] = useState(true);
  const [openDialog, setOpenDialog] = useState(false);
  const [editingProject, setEditingProject] = useState<Project | null>(null);
  const [memberDialogProject, setMemberDialogProject] = useState<Project | null>(null);
  const [confirmDialog, setConfirmDialog] = useState({ 
    open: false, 
    title: '', 
    message: '', 
    recordDetails: {} as any,
    action: (() => {}) as () => void | Promise<void>
  });
  
  const [formData, setFormData] = useState({
    name: '',
    description: '',
    start_date: '',
    end_date: '',
    status: 'active' as 'active' | 'completed' | 'on_hold'
  });

  useEffect(() => {
    fetchProjects();
  }, []);

  const fetchProjects = async () => {
    try {
      setLoading(true);
      const response = await api.get('/projects');
      setProjects(response.data);
    } catch {
      showError('Failed to load projects');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (project?: Project) => {
    if (project) {
      setEditingProject(project);
      setFormData({
        name: project.name,
        description: project.description || '',
        start_date: formatDateForInput(project.start_date),
        end_date: formatDateForInput(project.end_date),
        status: project.status
      });
    } else {
      setEditingProject(null);
      setFormData({
        name: '',
        description: '',
        start_date: '',
        end_date: '',
        status: 'active'
      });
    }
    setOpenDialog(true);
  };

  const handleCloseDialog = () => {
    setOpenDialog(false);
    setEditingProject(null);
  };

  const handleSave = async () => {
    try {
      if (editingProject) {
        await api.put(`/projects/${editingProject.id}`, formData);
        showSuccess('Project updated successfully');
      } else {
        await api.post('/projects', formData);
        showSuccess('Project created successfully');
      }
      fetchProjects();
      handleCloseDialog();
    } catch {
      showError('Failed to save project');
    }
  };

  const handleDelete = async (id: number) => {
    const project = projects.find(p => p.id === id);
    setConfirmDialog({
      open: true,
      title: 'Delete Project',
      message: 'Are you sure you want to delete this project? This action cannot be undone.',
      recordDetails: {
        name: project?.name,
        description: project?.description,
        status: project?.status
      },
      action: async () => {
        try {
          await api.delete(`/projects/${id}`);
          showSuccess('Project deleted successfully');
          fetchProjects();
        } catch {
          showError('Failed to delete project');
        }
        setConfirmDialog({ ...confirmDialog, open: false });
      }
    });
  };

  const handleOpenMembersDialog = (project: Project) => {
    setMemberDialogProject(project);
  };

  const handleCloseMembersDialog = () => {
    setMemberDialogProject(null);
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
      flex: 1.5,
      minWidth: 250
    },
    {
      field: 'start_date',
      headerName: 'Start Date',
      width: 130,
      renderCell: ({ value }: GridRenderCellParams) => 
        value ? dayjs(value).format('DD/MM/YYYY') : '-'
    },
    {
      field: 'end_date',
      headerName: 'End Date',
      width: 130,
      renderCell: ({ value }: GridRenderCellParams) => 
        value ? dayjs(value).format('DD/MM/YYYY') : '-'
    },
    {
      field: 'status',
      headerName: 'Status',
      width: 120,
      renderCell: (params) => (
        <Box
          sx={{
            px: 1.5,
            py: 0.5,
            borderRadius: 1,
            fontSize: '0.75rem',
            fontWeight: 600,
            textTransform: 'uppercase',
            bgcolor: params.value === 'active' ? '#4caf5015' : params.value === 'completed' ? '#2196f315' : '#ff980015',
            color: params.value === 'active' ? '#4caf50' : params.value === 'completed' ? '#2196f3' : '#ff9800'
          }}
        >
          {params.value}
        </Box>
      )
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
            onClick={() => handleOpenMembersDialog(params.row as Project)}
            sx={{ color: '#009688' }}
          >
            <GroupIcon fontSize="small" />
          </IconButton>
          <IconButton
            size="small"
            onClick={() => handleOpenDialog(params.row as Project)}
            sx={{ color: '#667eea' }}
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
    <AdminLayout title="Projects Management">
      <Box sx={{ mb: 2, display: 'flex', justifyContent: 'flex-end' }}>
        <Button
          variant="contained"
          startIcon={<AddIcon />}
          onClick={() => handleOpenDialog()}
          sx={{
            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            '&:hover': {
              background: 'linear-gradient(135deg, #5568d3 0%, #65408b 100%)'
            }
          }}
        >
          New Project
        </Button>
      </Box>

      <Box sx={{ height: 600, width: '100%' }}>
        <DataGrid
          rows={projects}
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
              bgcolor: 'rgba(102, 126, 234, 0.04)'
            }
          }}
        />
      </Box>

      <Dialog open={openDialog} onClose={handleCloseDialog} maxWidth="sm" fullWidth>
        <DialogTitle>
          {editingProject ? 'Edit Project' : 'New Project'}
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
              label="Description"
              fullWidth
              multiline
              rows={3}
              value={formData.description}
              onChange={(e) => setFormData({ ...formData, description: e.target.value })}
            />
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
            <TextField
              label="Status"
              select
              fullWidth
              required
              value={formData.status}
              onChange={(e) => setFormData({ ...formData, status: e.target.value as 'active' | 'completed' | 'on_hold' })}
              SelectProps={{ native: true }}
            >
              <option value="active">Active</option>
              <option value="completed">Completed</option>
              <option value="on_hold">On Hold</option>
            </TextField>
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseDialog}>Cancel</Button>
          <Button
            onClick={handleSave}
            variant="contained"
            disabled={!formData.name}
            sx={{
              background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)'
            }}
          >
            {editingProject ? 'Update' : 'Create'}
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

      <ProjectMembersDialog
        open={Boolean(memberDialogProject)}
        project={memberDialogProject}
        onClose={handleCloseMembersDialog}
      />
    </AdminLayout>
  );
};

export default ProjectsManager;
