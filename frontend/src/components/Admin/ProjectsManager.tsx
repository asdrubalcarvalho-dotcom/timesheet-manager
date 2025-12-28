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
  Fab,
  Tabs,
  Tab,
  Divider,
  Table,
  TableBody,
  TableCell,
  TableHead,
  TableRow
} from '@mui/material';
import {
  Add as AddIcon,
  Edit as EditIcon,
  Delete as DeleteIcon,
  Group as GroupIcon,
  FolderOpen as FolderOpenIcon
} from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRenderCellParams } from '@mui/x-data-grid';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs from 'dayjs';
import AdminLayout from './AdminLayout';
import ProjectMembersDialog from './ProjectMembersDialog';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import EmptyState from '../Common/EmptyState';
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

interface ProjectTask {
  id: number;
  name: string;
  start_date?: string | null;
  end_date?: string | null;
  estimated_hours?: number | null;
  progress?: number | null;
  project_id: number;
}

const isSameOrAfterDay = (end: string, start: string): boolean => {
  const endDate = dayjs(end);
  const startDate = dayjs(start);
  if (!endDate.isValid() || !startDate.isValid()) return true;
  return endDate.isSame(startDate, 'day') || endDate.isAfter(startDate, 'day');
};

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
  const [editTab, setEditTab] = useState<'details' | 'tasks'>('details');

  const [projectTasks, setProjectTasks] = useState<ProjectTask[]>([]);
  const [loadingProjectTasks, setLoadingProjectTasks] = useState(false);
  const [tasksLoadedForProjectId, setTasksLoadedForProjectId] = useState<number | null>(null);

  const [openTaskDialog, setOpenTaskDialog] = useState(false);
  const [editingTask, setEditingTask] = useState<ProjectTask | null>(null);
  const [taskForm, setTaskForm] = useState({
    name: '',
    start_date: '',
    end_date: '',
    estimated_hours: '',
    progress: '0',
  });
  const [taskFormError, setTaskFormError] = useState<string | null>(null);
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
      const response = await api.get('/api/projects');
      console.log('[ProjectsManager] Projects response:', response.data);
      
      // Backend returns { data: [...], user_permissions: {...} }
      const projectsData = Array.isArray(response.data) ? response.data : response.data.data;
      console.log('[ProjectsManager] Extracted projects:', projectsData);
      setProjects(projectsData || []);
    } catch (error: any) {
      console.error('[ProjectsManager] Error fetching projects:', error);
      console.error('[ProjectsManager] Error response:', error.response?.data);
      showError(error.response?.data?.message || 'Failed to load projects');
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (project?: Project) => {
    if (project) {
      setEditingProject(project);
      setEditTab('details');
      setTasksLoadedForProjectId(null);
      setProjectTasks([]);
      setFormData({
        name: project.name,
        description: project.description || '',
        start_date: formatDateForInput(project.start_date),
        end_date: formatDateForInput(project.end_date),
        status: project.status
      });
    } else {
      setEditingProject(null);
      setEditTab('details');
      setTasksLoadedForProjectId(null);
      setProjectTasks([]);
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
    setEditTab('details');
  };

  const fetchProjectTasks = async (projectId: number) => {
    try {
      setLoadingProjectTasks(true);
      const response = await api.get(`/api/projects/${projectId}/tasks`);
      const list: ProjectTask[] = Array.isArray(response.data) ? response.data : response.data?.data || [];
      setProjectTasks(list);
      setTasksLoadedForProjectId(projectId);
    } catch (error: any) {
      console.error('[ProjectsManager] Error fetching project tasks:', error);
      showError(error.response?.data?.message || 'Failed to load project tasks');
      setProjectTasks([]);
      setTasksLoadedForProjectId(projectId);
    } finally {
      setLoadingProjectTasks(false);
    }
  };

  useEffect(() => {
    if (!openDialog) return;
    if (editTab !== 'tasks') return;
    if (!editingProject?.id) return;

    if (tasksLoadedForProjectId === editingProject.id) return;
    fetchProjectTasks(editingProject.id);
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [openDialog, editTab, editingProject?.id]);

  const openCreateTask = () => {
    if (!editingProject) return;
    setEditingTask(null);
    setTaskFormError(null);
    setTaskForm({
      name: '',
      start_date: '',
      end_date: '',
      estimated_hours: '',
      progress: '0',
    });
    setOpenTaskDialog(true);
  };

  const openEditTask = (task: ProjectTask) => {
    setEditingTask(task);
    setTaskFormError(null);
    setTaskForm({
      name: task.name ?? '',
      start_date: formatDateForInput(task.start_date ?? undefined),
      end_date: formatDateForInput(task.end_date ?? undefined),
      estimated_hours: task.estimated_hours != null ? String(task.estimated_hours) : '',
      progress: task.progress != null ? String(task.progress) : '0',
    });
    setOpenTaskDialog(true);
  };

  const closeTaskDialog = () => {
    setOpenTaskDialog(false);
    setEditingTask(null);
    setTaskFormError(null);
  };

  const saveTask = async () => {
    if (!editingProject) return;

    const name = taskForm.name.trim();
    const startDate = taskForm.start_date;
    const endDate = taskForm.end_date;

    const progressNum = Number(taskForm.progress);
    const estimatedHoursNum = taskForm.estimated_hours === '' ? null : Number(taskForm.estimated_hours);

    if (!name) {
      setTaskFormError('Task name is required');
      return;
    }
    if (!startDate) {
      setTaskFormError('Start date is required');
      return;
    }
    if (endDate && !isSameOrAfterDay(endDate, startDate)) {
      setTaskFormError('End date must be the same or after start date');
      return;
    }
    if (!Number.isFinite(progressNum) || progressNum < 0 || progressNum > 100) {
      setTaskFormError('Progress must be between 0 and 100');
      return;
    }
    if (estimatedHoursNum != null && (!Number.isFinite(estimatedHoursNum) || estimatedHoursNum < 0)) {
      setTaskFormError('Estimated hours must be a positive number');
      return;
    }

    const payload = {
      name,
      project_id: editingProject.id,
      start_date: startDate,
      end_date: endDate || null,
      estimated_hours: estimatedHoursNum,
      progress: Math.round(progressNum),
    };

    try {
      if (editingTask) {
        await api.put(`/api/tasks/${editingTask.id}`, payload);
        showSuccess('Task updated successfully');
      } else {
        await api.post('/api/tasks', payload);
        showSuccess('Task created successfully');
      }
      closeTaskDialog();
      await fetchProjectTasks(editingProject.id);
    } catch (error: any) {
      showError(error.response?.data?.message || 'Failed to save task');
    }
  };

  const confirmDeleteTask = (task: ProjectTask) => {
    setConfirmDialog({
      open: true,
      title: 'Delete Task',
      message: 'Are you sure you want to delete this task? This action cannot be undone.',
      recordDetails: {
        name: task.name,
        start_date: task.start_date,
        end_date: task.end_date,
      },
      action: async () => {
        try {
          await api.delete(`/api/tasks/${task.id}`);
          showSuccess('Task deleted successfully');
          if (editingProject) {
            await fetchProjectTasks(editingProject.id);
          }
        } catch (error: any) {
          showError(error.response?.data?.message || 'Failed to delete task');
        }
        setConfirmDialog((prev) => ({ ...prev, open: false }));
      }
    });
  };

  const handleSave = async (e?: React.FormEvent) => {
    if (e) {
      e.preventDefault(); // Prevent default form submission
    }

    try {
      const cleanData = {
        name: formData.name,
        description: formData.description || null,
        start_date: formData.start_date || null,
        end_date: formData.end_date || null,
        status: formData.status,
      };

      if (editingProject) {
        await api.put(`/api/projects/${editingProject.id}`, cleanData);
        showSuccess('Project updated successfully');
      } else {
        await api.post('/api/projects', cleanData);
        showSuccess('Project created successfully');
      }
      fetchProjects();
      handleCloseDialog();
    } catch (error: any) {
      showError(error.response?.data?.message || 'Failed to save project');
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
          await api.delete(`/api/projects/${id}`);
          showSuccess('Project deleted successfully');
          fetchProjects();
        } catch (error: any) {
          showError(error?.response?.data?.message || 'Failed to delete project');
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
      {!loading && projects.length === 0 ? (
        <EmptyState
          icon={FolderOpenIcon}
          title="No projects yet"
          subtitle="Create your first project to start organizing your team's work"
          actionLabel="New Project"
          onAction={() => handleOpenDialog()}
        />
      ) : (
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
              },
              border: 'none',
              '& .MuiDataGrid-columnHeaders': {
                bgcolor: 'rgba(102, 126, 234, 0.08)',
                borderRadius: '8px 8px 0 0'
              }
            }}
          />
        </Box>
      )}

      {/* Floating Action Button */}
      {projects.length > 0 && (
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
          {editingProject ? 'Edit Project' : 'New Project'}
        </DialogTitle>
        <DialogContent>
          {editingProject && (
            <>
              <Tabs
                value={editTab}
                onChange={(_e, value) => setEditTab(value)}
                sx={{ mb: 2 }}
              >
                <Tab value="details" label="Details" />
                <Tab value="tasks" label="Tasks" />
              </Tabs>
              <Divider sx={{ mb: 2 }} />
            </>
          )}

          {(!editingProject || editTab === 'details') && (
          <Box 
            component="form" 
            onSubmit={handleSave}
            id="project-form"
            sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 1 }}
          >
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
              slotProps={{ 
                textField: { 
                  fullWidth: true,
                  required: true 
                } 
              }}
            />
            <DatePicker
              label="End Date"
              value={formData.end_date ? dayjs(formData.end_date) : null}
              onChange={(newValue) => setFormData({ ...formData, end_date: newValue ? newValue.format('YYYY-MM-DD') : '' })}
              slotProps={{ 
                textField: { 
                  fullWidth: true,
                  required: true 
                } 
              }}
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
          )}

          {editingProject && editTab === 'tasks' && (
            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <Box>
                  <Box sx={{ fontWeight: 600 }}>{editingProject.name}</Box>
                  <Box sx={{ color: 'text.secondary', fontSize: '0.875rem' }}>Manage tasks for this project</Box>
                </Box>
                <Button variant="contained" onClick={openCreateTask}>Add Task</Button>
              </Box>

              <Box sx={{ border: '1px solid #e0e0e0', borderRadius: 1, overflow: 'hidden' }}>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>Name</TableCell>
                      <TableCell>Start</TableCell>
                      <TableCell>End</TableCell>
                      <TableCell align="right">Progress</TableCell>
                      <TableCell align="right">Actions</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {loadingProjectTasks ? (
                      <TableRow>
                        <TableCell colSpan={5}>
                          Loading...
                        </TableCell>
                      </TableRow>
                    ) : projectTasks.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={5}>
                          No tasks for this project.
                        </TableCell>
                      </TableRow>
                    ) : (
                      projectTasks.map((t) => (
                        <TableRow key={t.id} hover>
                          <TableCell>{t.name}</TableCell>
                          <TableCell>{t.start_date ? dayjs(t.start_date).format('DD/MM/YYYY') : '-'}</TableCell>
                          <TableCell>{t.end_date ? dayjs(t.end_date).format('DD/MM/YYYY') : '-'}</TableCell>
                          <TableCell align="right">{t.progress != null ? `${t.progress}%` : '0%'}</TableCell>
                          <TableCell align="right">
                            <IconButton size="small" onClick={() => openEditTask(t)} sx={{ color: '#667eea' }}>
                              <EditIcon fontSize="small" />
                            </IconButton>
                            <IconButton size="small" onClick={() => confirmDeleteTask(t)} sx={{ color: '#f44336' }}>
                              <DeleteIcon fontSize="small" />
                            </IconButton>
                          </TableCell>
                        </TableRow>
                      ))
                    )}
                  </TableBody>
                </Table>
              </Box>
            </Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseDialog}>Cancel</Button>
          <Button
            type="submit"
            form="project-form"
            variant="contained"
            color="primary"
            disabled={Boolean(editingProject && editTab === 'tasks')}
          >
            {editingProject ? 'Update' : 'Create'}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={openTaskDialog} onClose={closeTaskDialog} maxWidth="sm" fullWidth disableRestoreFocus>
        <DialogTitle>
          {editingTask ? 'Edit Task' : 'Add Task'}
        </DialogTitle>
        <DialogContent>
          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 1 }}>
            {taskFormError && (
              <Box sx={{ color: 'error.main', fontSize: '0.875rem' }}>{taskFormError}</Box>
            )}
            <TextField
              label="Name"
              fullWidth
              required
              value={taskForm.name}
              onChange={(e) => setTaskForm((p) => ({ ...p, name: e.target.value }))}
            />
            <DatePicker
              label="Start Date"
              value={taskForm.start_date ? dayjs(taskForm.start_date) : null}
              onChange={(newValue) => setTaskForm((p) => ({ ...p, start_date: newValue ? newValue.format('YYYY-MM-DD') : '' }))}
              slotProps={{
                textField: {
                  fullWidth: true,
                  required: true,
                },
              }}
            />
            <DatePicker
              label="End Date"
              value={taskForm.end_date ? dayjs(taskForm.end_date) : null}
              onChange={(newValue) => setTaskForm((p) => ({ ...p, end_date: newValue ? newValue.format('YYYY-MM-DD') : '' }))}
              slotProps={{
                textField: {
                  fullWidth: true,
                },
              }}
            />
            <TextField
              label="Estimated Hours"
              fullWidth
              value={taskForm.estimated_hours}
              onChange={(e) => setTaskForm((p) => ({ ...p, estimated_hours: e.target.value }))}
              inputProps={{ inputMode: 'decimal' }}
            />
            <TextField
              label="Progress (0..100)"
              fullWidth
              value={taskForm.progress}
              onChange={(e) => setTaskForm((p) => ({ ...p, progress: e.target.value }))}
              inputProps={{ inputMode: 'numeric' }}
            />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={closeTaskDialog}>Cancel</Button>
          <Button variant="contained" onClick={saveTask}>Save</Button>
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
