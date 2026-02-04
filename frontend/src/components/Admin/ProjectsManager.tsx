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
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import { useAuth } from '../Auth/AuthContext';
import { formatTenantDate, getTenantDatePickerFormat } from '../../utils/tenantFormatting';
import { useTranslation } from 'react-i18next';

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
  const { t } = useTranslation();
  const { tenantContext } = useAuth();
  const datePickerFormat = getTenantDatePickerFormat(tenantContext);
  const { showSuccess, showError } = useNotification();
  const { isReadOnly, ensureWritable } = useReadOnlyGuard('admin-projects');
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
      showError(error.response?.data?.message || t('admin.projects.notifications.loadFailed'));
    } finally {
      setLoading(false);
    }
  };

  const handleOpenDialog = (project?: Project) => {
    if (!ensureWritable()) {
      return;
    }
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
      showError(error.response?.data?.message || t('admin.projects.notifications.loadTasksFailed'));
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
    if (!ensureWritable()) {
      return;
    }
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
    if (!ensureWritable()) {
      return;
    }
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
    if (!ensureWritable()) {
      return;
    }

    const name = taskForm.name.trim();
    const startDate = taskForm.start_date;
    const endDate = taskForm.end_date;

    const progressNum = Number(taskForm.progress);
    const estimatedHoursNum = taskForm.estimated_hours === '' ? null : Number(taskForm.estimated_hours);

    if (!name) {
      setTaskFormError(t('admin.projects.tasksDialog.validation.nameRequired'));
      return;
    }
    if (!startDate) {
      setTaskFormError(t('admin.projects.tasksDialog.validation.startDateRequired'));
      return;
    }
    if (endDate && !isSameOrAfterDay(endDate, startDate)) {
      setTaskFormError(t('admin.projects.tasksDialog.validation.endDateAfterStart'));
      return;
    }
    if (!Number.isFinite(progressNum) || progressNum < 0 || progressNum > 100) {
      setTaskFormError(t('admin.projects.tasksDialog.validation.progressRange'));
      return;
    }
    if (estimatedHoursNum != null && (!Number.isFinite(estimatedHoursNum) || estimatedHoursNum < 0)) {
      setTaskFormError(t('admin.projects.tasksDialog.validation.estimatedHoursPositive'));
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
        showSuccess(t('admin.projects.tasksDialog.notifications.updateSuccess'));
      } else {
        await api.post('/api/tasks', payload);
        showSuccess(t('admin.projects.tasksDialog.notifications.createSuccess'));
      }
      closeTaskDialog();
      await fetchProjectTasks(editingProject.id);
    } catch (error: any) {
      showError(error.response?.data?.message || t('admin.projects.tasksDialog.notifications.saveFailed'));
    }
  };

  const confirmDeleteTask = (task: ProjectTask) => {
    if (!ensureWritable()) {
      return;
    }
    setConfirmDialog({
      open: true,
      title: t('admin.projects.tasksDialog.confirmDelete.title'),
      message: t('admin.projects.tasksDialog.confirmDelete.message'),
      recordDetails: {
        name: task.name,
        start_date: task.start_date,
        end_date: task.end_date,
      },
      action: async () => {
        if (!ensureWritable()) {
          return;
        }
        try {
          await api.delete(`/api/tasks/${task.id}`);
          showSuccess(t('admin.projects.tasksDialog.notifications.deleteSuccess'));
          if (editingProject) {
            await fetchProjectTasks(editingProject.id);
          }
        } catch (error: any) {
          showError(error.response?.data?.message || t('admin.projects.tasksDialog.notifications.deleteFailed'));
        }
        setConfirmDialog((prev) => ({ ...prev, open: false }));
      }
    });
  };

  const handleSave = async (e?: React.FormEvent) => {
    if (e) {
      e.preventDefault(); // Prevent default form submission
    }

    if (!ensureWritable()) {
      return;
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
        showSuccess(t('admin.projects.notifications.updateSuccess'));
      } else {
        await api.post('/api/projects', cleanData);
        showSuccess(t('admin.projects.notifications.createSuccess'));
      }
      fetchProjects();
      handleCloseDialog();
    } catch (error: any) {
      showError(error.response?.data?.message || t('admin.projects.notifications.saveFailed'));
    }
  };

  const handleDelete = async (id: number) => {
    if (!ensureWritable()) {
      return;
    }
    const project = projects.find(p => p.id === id);
    setConfirmDialog({
      open: true,
      title: t('admin.projects.confirmDelete.title'),
      message: t('admin.projects.confirmDelete.message'),
      recordDetails: {
        name: project?.name,
        description: project?.description,
        status: project?.status
      },
      action: async () => {
        if (!ensureWritable()) {
          return;
        }
        try {
          await api.delete(`/api/projects/${id}`);
          showSuccess(t('admin.projects.notifications.deleteSuccess'));
          fetchProjects();
        } catch (error: any) {
          showError(error?.response?.data?.message || t('admin.projects.notifications.deleteFailed'));
        }
        setConfirmDialog({ ...confirmDialog, open: false });
      }
    });
  };

  const handleOpenMembersDialog = (project: Project) => {
    if (!ensureWritable()) {
      return;
    }
    setMemberDialogProject(project);
  };

  const handleCloseMembersDialog = () => {
    setMemberDialogProject(null);
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
      flex: 1.5,
      minWidth: 250
    },
    {
      field: 'start_date',
      headerName: t('admin.projects.columns.startDate'),
      width: 130,
      renderCell: ({ value }: GridRenderCellParams) => 
        value ? formatTenantDate(value, tenantContext) : '-'
    },
    {
      field: 'end_date',
      headerName: t('admin.projects.columns.endDate'),
      width: 130,
      renderCell: ({ value }: GridRenderCellParams) => 
        value ? formatTenantDate(value, tenantContext) : '-'
    },
    {
      field: 'status',
      headerName: t('admin.shared.columns.status'),
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
          {t(`admin.projects.status.${String(params.value)}`)}
        </Box>
      )
    },
    {
      field: 'actions',
      headerName: t('admin.shared.columns.actions'),
      width: 120,
      sortable: false,
      renderCell: (params: GridRenderCellParams) => (
        <Box>
          <IconButton
            size="small"
            onClick={() => handleOpenMembersDialog(params.row as Project)}
            disabled={isReadOnly}
            sx={{ color: '#009688' }}
          >
            <GroupIcon fontSize="small" />
          </IconButton>
          <IconButton
            size="small"
            onClick={() => handleOpenDialog(params.row as Project)}
            disabled={isReadOnly}
            sx={{ color: '#667eea' }}
          >
            <EditIcon fontSize="small" />
          </IconButton>
          <IconButton
            size="small"
            onClick={() => handleDelete(params.row.id)}
            disabled={isReadOnly}
            sx={{ color: '#f44336' }}
          >
            <DeleteIcon fontSize="small" />
          </IconButton>
        </Box>
      )
    }
  ];

  return (
    <AdminLayout title={t('admin.projects.managementTitle')}>
      {!loading && projects.length === 0 ? (
        <EmptyState
          icon={FolderOpenIcon}
          title={t('admin.projects.empty.title')}
          subtitle={t('admin.projects.empty.subtitle')}
          actionLabel={t('admin.projects.actions.new')}
          onAction={() => {
            if (!ensureWritable()) {
              return;
            }
            handleOpenDialog();
          }}
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
          {editingProject ? t('admin.projects.actions.edit') : t('admin.projects.actions.new')}
        </DialogTitle>
        <DialogContent>
          {editingProject && (
            <>
              <Tabs
                value={editTab}
                onChange={(_e, value) => setEditTab(value)}
                sx={{ mb: 2 }}
              >
                <Tab value="details" label={t('admin.projects.tabs.details')} />
                <Tab value="tasks" label={t('admin.projects.tabs.tasks')} />
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
            <DatePicker
              label={t('admin.projects.fields.startDate')}
              value={formData.start_date ? dayjs(formData.start_date) : null}
              onChange={(newValue) => setFormData({ ...formData, start_date: newValue ? newValue.format('YYYY-MM-DD') : '' })}
              format={datePickerFormat}
              slotProps={{ 
                textField: { 
                  fullWidth: true,
                  required: true 
                } 
              }}
            />
            <DatePicker
              label={t('admin.projects.fields.endDate')}
              value={formData.end_date ? dayjs(formData.end_date) : null}
              onChange={(newValue) => setFormData({ ...formData, end_date: newValue ? newValue.format('YYYY-MM-DD') : '' })}
              format={datePickerFormat}
              slotProps={{ 
                textField: { 
                  fullWidth: true,
                  required: true 
                } 
              }}
            />
            <TextField
              label={t('admin.shared.columns.status')}
              select
              fullWidth
              required
              value={formData.status}
              onChange={(e) => setFormData({ ...formData, status: e.target.value as 'active' | 'completed' | 'on_hold' })}
              SelectProps={{ native: true }}
            >
              <option value="active">{t('admin.projects.status.active')}</option>
              <option value="completed">{t('admin.projects.status.completed')}</option>
              <option value="on_hold">{t('admin.projects.status.on_hold')}</option>
            </TextField>
          </Box>
          )}

          {editingProject && editTab === 'tasks' && (
            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <Box>
                  <Box sx={{ fontWeight: 600 }}>{editingProject.name}</Box>
                  <Box sx={{ color: 'text.secondary', fontSize: '0.875rem' }}>{t('admin.projects.tasksTab.subtitle')}</Box>
                </Box>
                <Button variant="contained" onClick={openCreateTask} disabled={isReadOnly}>{t('admin.projects.tasksTab.addTask')}</Button>
              </Box>

              <Box sx={{ border: '1px solid #e0e0e0', borderRadius: 1, overflow: 'hidden' }}>
                <Table size="small">
                  <TableHead>
                    <TableRow>
                      <TableCell>{t('admin.shared.columns.name')}</TableCell>
                      <TableCell>{t('admin.projects.tasksTab.columns.start')}</TableCell>
                      <TableCell>{t('admin.projects.tasksTab.columns.end')}</TableCell>
                      <TableCell align="right">{t('admin.projects.tasksTab.columns.progress')}</TableCell>
                      <TableCell align="right">{t('admin.shared.columns.actions')}</TableCell>
                    </TableRow>
                  </TableHead>
                  <TableBody>
                    {loadingProjectTasks ? (
                      <TableRow>
                        <TableCell colSpan={5}>
                          {t('common.loading')}
                        </TableCell>
                      </TableRow>
                    ) : projectTasks.length === 0 ? (
                      <TableRow>
                        <TableCell colSpan={5}>
                          {t('admin.projects.tasksTab.empty')}
                        </TableCell>
                      </TableRow>
                    ) : (
                      projectTasks.map((t) => (
                        <TableRow key={t.id} hover>
                          <TableCell>{t.name}</TableCell>
                          <TableCell>{t.start_date ? formatTenantDate(t.start_date, tenantContext) : '-'}</TableCell>
                          <TableCell>{t.end_date ? formatTenantDate(t.end_date, tenantContext) : '-'}</TableCell>
                          <TableCell align="right">{t.progress != null ? `${t.progress}%` : '0%'}</TableCell>
                          <TableCell align="right">
                            <IconButton size="small" onClick={() => openEditTask(t)} disabled={isReadOnly} sx={{ color: '#667eea' }}>
                              <EditIcon fontSize="small" />
                            </IconButton>
                            <IconButton size="small" onClick={() => confirmDeleteTask(t)} disabled={isReadOnly} sx={{ color: '#f44336' }}>
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
          <Button onClick={handleCloseDialog}>{t('common.cancel')}</Button>
          <Button
            type="submit"
            form="project-form"
            variant="contained"
            color="primary"
            disabled={Boolean(editingProject && editTab === 'tasks') || isReadOnly}
          >
            {editingProject ? t('common.update') : t('common.create')}
          </Button>
        </DialogActions>
      </Dialog>

      <Dialog open={openTaskDialog} onClose={closeTaskDialog} maxWidth="sm" fullWidth disableRestoreFocus>
        <DialogTitle>
          {editingTask ? t('admin.projects.tasksDialog.editTask') : t('admin.projects.tasksDialog.addTask')}
        </DialogTitle>
        <DialogContent>
          <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 1 }}>
            {taskFormError && (
              <Box sx={{ color: 'error.main', fontSize: '0.875rem' }}>{taskFormError}</Box>
            )}
            <TextField
              label={t('common.name')}
              fullWidth
              required
              value={taskForm.name}
              onChange={(e) => setTaskForm((p) => ({ ...p, name: e.target.value }))}
            />
            <DatePicker
              label={t('admin.projects.fields.startDate')}
              value={taskForm.start_date ? dayjs(taskForm.start_date) : null}
              onChange={(newValue) => setTaskForm((p) => ({ ...p, start_date: newValue ? newValue.format('YYYY-MM-DD') : '' }))}
              format={datePickerFormat}
              slotProps={{
                textField: {
                  fullWidth: true,
                  required: true,
                },
              }}
            />
            <DatePicker
              label={t('admin.projects.fields.endDate')}
              value={taskForm.end_date ? dayjs(taskForm.end_date) : null}
              onChange={(newValue) => setTaskForm((p) => ({ ...p, end_date: newValue ? newValue.format('YYYY-MM-DD') : '' }))}
              format={datePickerFormat}
              slotProps={{
                textField: {
                  fullWidth: true,
                },
              }}
            />
            <TextField
              label={t('admin.projects.tasksDialog.fields.estimatedHours')}
              fullWidth
              value={taskForm.estimated_hours}
              onChange={(e) => setTaskForm((p) => ({ ...p, estimated_hours: e.target.value }))}
              inputProps={{ inputMode: 'decimal' }}
            />
            <TextField
              label={t('admin.projects.tasksDialog.fields.progress')}
              fullWidth
              value={taskForm.progress}
              onChange={(e) => setTaskForm((p) => ({ ...p, progress: e.target.value }))}
              inputProps={{ inputMode: 'numeric' }}
            />
          </Box>
        </DialogContent>
        <DialogActions>
          <Button onClick={closeTaskDialog}>{t('common.cancel')}</Button>
          <Button variant="contained" onClick={saveTask} disabled={isReadOnly}>{t('common.save')}</Button>
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

      <ProjectMembersDialog
        open={Boolean(memberDialogProject)}
        project={memberDialogProject}
        onClose={handleCloseMembersDialog}
      />
    </AdminLayout>
  );
};

export default ProjectsManager;
