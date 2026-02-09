import React, { useEffect, useMemo, useState } from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  Box,
  Typography,
  CircularProgress,
  Alert,
  TextField,
  MenuItem,
  IconButton,
  Snackbar,
  Divider,
  Stack
} from '@mui/material';
import { Delete as DeleteIcon } from '@mui/icons-material';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import api from '../../services/api';
import type { ProjectMember, Technician } from '../../types';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import { useTranslation } from 'react-i18next';

interface ProjectSummary {
  id: number;
  name: string;
}

interface ProjectMembersDialogProps {
  open: boolean;
  project: ProjectSummary | null;
  onClose: () => void;
}

type Role = 'member' | 'manager' | 'none';

const normalizeArray = <T,>(payload: unknown): T[] => {
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

const ProjectMembersDialog: React.FC<ProjectMembersDialogProps> = ({ open, project, onClose }) => {
  const { t } = useTranslation();
  const { isReadOnly, ensureWritable } = useReadOnlyGuard('admin-project-members');
  const [members, setMembers] = useState<ProjectMember[]>([]);
  const [loadingMembers, setLoadingMembers] = useState(false);
  const [availableUsers, setAvailableUsers] = useState<Technician[]>([]);
  const [updatingMemberId, setUpdatingMemberId] = useState<number | null>(null);
  const [savingNewMember, setSavingNewMember] = useState(false);
  const [snackbar, setSnackbar] = useState({ open: false, message: '', severity: 'success' as 'success' | 'error' });
  const [confirmDialog, setConfirmDialog] = useState({ 
    open: false, 
    title: '', 
    message: '', 
    recordDetails: {} as any,
    action: (() => {}) as () => void | Promise<void>
  });
  const [formData, setFormData] = useState<{ user_id: number | ''; project_role: Role; expense_role: Role; finance_role: Role }>({
    user_id: '',
    project_role: 'none',
    expense_role: 'none',
    finance_role: 'none'
  });

  useEffect(() => {
    if (open && project) {
      fetchMembers(project.id);
      fetchAvailableUsers();
      resetForm();
    }
  }, [open, project]);

  const resetForm = () => {
    setFormData({ user_id: '', project_role: 'none', expense_role: 'none', finance_role: 'none' });
  };

  const showSnackbar = (message: string, severity: 'success' | 'error') => {
    setSnackbar({ open: true, message, severity });
  };

  const fetchMembers = async (projectId: number) => {
    setLoadingMembers(true);
    try {
      const response = await api.get(`/api/projects/${projectId}/members`);
      setMembers(normalizeArray<ProjectMember>(response.data));
    } catch {
      setMembers([]);
      showSnackbar(t('admin.projects.membersDialog.notifications.loadMembersFailed'), 'error');
    } finally {
      setLoadingMembers(false);
    }
  };

  const fetchAvailableUsers = async () => {
    try {
      const response = await api.get('/api/technicians');
      setAvailableUsers(normalizeArray<Technician>(response.data));
    } catch {
      showSnackbar(t('admin.projects.membersDialog.notifications.loadUsersFailed'), 'error');
    }
  };

  const assignableUsers = useMemo(() => {
    return availableUsers.filter((user) => {
      if (!user.user_id) {
        return false;
      }
      return !members.some((member) => member.user_id === user.user_id);
    });
  }, [availableUsers, members]);

  const handleMemberRoleChange = async (member: ProjectMember, field: 'project_role' | 'expense_role' | 'finance_role', value: Role) => {
    if (!project) return;
    if (!ensureWritable()) {
      return;
    }

    setUpdatingMemberId(member.user_id);
    try {
      const payload = {
        project_role: field === 'project_role' ? value : member.project_role,
        expense_role: field === 'expense_role' ? value : member.expense_role,
        finance_role: field === 'finance_role' ? value : member.finance_role
      };
      const response = await api.put(`/api/projects/${project.id}/members/${member.user_id}`, payload);
      setMembers((prev) => prev.map((item) => (item.id === member.id ? response.data : item)));
      showSnackbar(t('admin.projects.membersDialog.notifications.rolesUpdated'), 'success');
    } catch {
      showSnackbar(t('admin.projects.membersDialog.notifications.updateRolesFailed'), 'error');
    } finally {
      setUpdatingMemberId(null);
    }
  };

  const handleRemoveMember = async (member: ProjectMember) => {
    if (!project) return;
    if (!ensureWritable()) {
      return;
    }
    
    setConfirmDialog({
      open: true,
      title: t('admin.projects.membersDialog.confirmRemove.title'),
      message: t('admin.projects.membersDialog.confirmRemove.message'),
      recordDetails: {
        name: member.user?.name,
        email: member.user?.email,
        project_role: member.project_role,
        expense_role: member.expense_role,
        finance_role: member.finance_role
      },
      action: async () => {
        if (!ensureWritable()) {
          return;
        }
        setUpdatingMemberId(member.user_id);
        try {
          await api.delete(`/api/projects/${project.id}/members/${member.user_id}`);
          setMembers((prev) => prev.filter((item) => item.id !== member.id));
          showSnackbar(t('admin.projects.membersDialog.notifications.memberRemoved'), 'success');
        } catch {
          showSnackbar(t('admin.projects.membersDialog.notifications.removeMemberFailed'), 'error');
        } finally {
          setUpdatingMemberId(null);
        }
        setConfirmDialog({ ...confirmDialog, open: false });
      }
    });
  };

  const handleAddMember = async () => {
    if (!project || formData.user_id === '') return;
    if (!ensureWritable()) {
      return;
    }

    setSavingNewMember(true);
    try {
      const response = await api.post(`/api/projects/${project.id}/members`, {
        user_id: formData.user_id,
        project_role: formData.project_role,
        expense_role: formData.expense_role,
        finance_role: formData.finance_role
      });
      setMembers((prev) => [...prev, response.data]);
      resetForm();
      showSnackbar(t('admin.projects.membersDialog.notifications.memberAdded'), 'success');
    } catch {
      showSnackbar(t('admin.projects.membersDialog.notifications.addMemberFailed'), 'error');
    } finally {
      setSavingNewMember(false);
    }
  };

  if (!project) {
    return null;
  }

  return (
    <Dialog 
      open={open} 
      onClose={onClose} 
      fullWidth 
      maxWidth="md"
      disableRestoreFocus
    >
      <DialogTitle>{t('admin.projects.membersDialog.title', { project: project.name })}</DialogTitle>
      <DialogContent dividers>
        {loadingMembers ? (
          <Box sx={{ py: 4, display: 'flex', justifyContent: 'center' }}>
            <CircularProgress size={32} />
          </Box>
        ) : members.length === 0 ? (
          <Alert severity="info">{t('admin.projects.membersDialog.empty')}</Alert>
        ) : (
          <Stack spacing={2}>
            {members.map((member) => (
              <Box
                key={member.id}
                sx={{
                  p: 2,
                  borderRadius: 2,
                  border: '1px solid',
                  borderColor: 'divider',
                  display: 'flex',
                  flexDirection: 'column',
                  gap: 1.5
                }}
              >
                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                  <Box>
                    <Typography variant="subtitle1" fontWeight={600}>
                      {member.user?.name ?? t('admin.projects.membersDialog.unnamedUser')}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      {member.user?.email ?? t('admin.projects.membersDialog.noEmail')}
                    </Typography>
                  </Box>
                  <IconButton
                    aria-label={t('admin.projects.membersDialog.removeAria')}
                    color="error"
                    size="small"
                    onClick={() => handleRemoveMember(member)}
                    disabled={isReadOnly || updatingMemberId === member.user_id}
                  >
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </Box>

                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                  <TextField
                    select
                    fullWidth
                    label={t('admin.projects.membersDialog.fields.timesheetRole')}
                    value={member.project_role}
                    onChange={(event) =>
                      handleMemberRoleChange(member, 'project_role', event.target.value as Role)
                    }
                    disabled={isReadOnly || updatingMemberId === member.user_id}
                  >
                    <MenuItem value="none">
                      <em>{t('approvals.roles.none')}</em>
                    </MenuItem>
                    <MenuItem value="member">{t('approvals.roles.member')}</MenuItem>
                    <MenuItem value="manager">{t('approvals.roles.manager')}</MenuItem>
                  </TextField>

                  <TextField
                    select
                    fullWidth
                    label={t('admin.projects.membersDialog.fields.expenseRole')}
                    value={member.expense_role}
                    onChange={(event) =>
                      handleMemberRoleChange(member, 'expense_role', event.target.value as Role)
                    }
                    disabled={isReadOnly || updatingMemberId === member.user_id}
                  >
                    <MenuItem value="none">
                      <em>{t('approvals.roles.none')}</em>
                    </MenuItem>
                    <MenuItem value="member">{t('approvals.roles.member')}</MenuItem>
                    <MenuItem value="manager">{t('approvals.roles.manager')}</MenuItem>
                  </TextField>

                  <TextField
                    select
                    fullWidth
                    label={t('admin.projects.membersDialog.fields.financeRole')}
                    value={member.finance_role || 'none'}
                    onChange={(event) =>
                      handleMemberRoleChange(member, 'finance_role', event.target.value as Role)
                    }
                    disabled={isReadOnly || updatingMemberId === member.user_id}
                  >
                    <MenuItem value="none">
                      <em>{t('approvals.roles.none')}</em>
                    </MenuItem>
                    <MenuItem value="member">{t('approvals.roles.member')}</MenuItem>
                    <MenuItem value="manager">{t('approvals.roles.manager')}</MenuItem>
                  </TextField>
                </Stack>
              </Box>
            ))}
          </Stack>
        )}

        <Divider sx={{ my: 3 }} />

        <Typography variant="subtitle1" fontWeight={600} gutterBottom>
          {t('admin.projects.membersDialog.addSectionTitle')}
        </Typography>

        {assignableUsers.length === 0 ? (
          <Alert severity="warning">
            {t('admin.projects.membersDialog.noAssignableUsers')}
          </Alert>
        ) : (
          <Stack spacing={2} mt={1}>
            <TextField
              select
              label={t('admin.projects.membersDialog.fields.user')}
              fullWidth
              value={formData.user_id}
              onChange={(event) =>
                setFormData({ ...formData, user_id: Number(event.target.value) })
              }
              disabled={isReadOnly || savingNewMember}
            >
              {assignableUsers.map((user) => (
                <MenuItem key={user.user_id} value={user.user_id}>
                  {user.name} — {user.email}
                </MenuItem>
              ))}
            </TextField>

            <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
              <TextField
                select
                fullWidth
                label={t('admin.projects.membersDialog.fields.timesheetRole')}
                value={formData.project_role}
                onChange={(event) =>
                  setFormData({ ...formData, project_role: event.target.value as Role })
                }
                disabled={isReadOnly || savingNewMember}
              >
                <MenuItem value="none">
                  <em>{t('approvals.roles.none')}</em>
                </MenuItem>
                <MenuItem value="member">{t('approvals.roles.member')}</MenuItem>
                <MenuItem value="manager">{t('approvals.roles.manager')}</MenuItem>
              </TextField>

              <TextField
                select
                fullWidth
                label={t('admin.projects.membersDialog.fields.expenseRole')}
                value={formData.expense_role}
                onChange={(event) =>
                  setFormData({ ...formData, expense_role: event.target.value as Role })
                }
                disabled={isReadOnly || savingNewMember}
              >
                <MenuItem value="none">
                  <em>{t('approvals.roles.none')}</em>
                </MenuItem>
                <MenuItem value="member">{t('approvals.roles.member')}</MenuItem>
                <MenuItem value="manager">{t('approvals.roles.manager')}</MenuItem>
              </TextField>

              <TextField
                select
                fullWidth
                label={t('admin.projects.membersDialog.fields.financeRole')}
                value={formData.finance_role}
                onChange={(event) =>
                  setFormData({ ...formData, finance_role: event.target.value as Role })
                }
                disabled={isReadOnly || savingNewMember}
              >
                <MenuItem value="none">
                  <em>{t('approvals.roles.none')}</em>
                </MenuItem>
                <MenuItem value="member">{t('approvals.roles.member')}</MenuItem>
                <MenuItem value="manager">{t('approvals.roles.manager')}</MenuItem>
              </TextField>
            </Stack>
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>{t('admin.projects.membersDialog.actions.close')}</Button>
        <Button
          variant="contained"
          onClick={handleAddMember}
          disabled={isReadOnly || savingNewMember || formData.user_id === ''}
        >
          {t('admin.projects.membersDialog.actions.assignUser')}
        </Button>
      </DialogActions>

      <Snackbar
        open={snackbar.open}
        autoHideDuration={4000}
        onClose={() => setSnackbar({ ...snackbar, open: false })}
        anchorOrigin={{ vertical: 'bottom', horizontal: 'right' }}
      >
        <Alert
          severity={snackbar.severity}
          onClose={() => setSnackbar({ ...snackbar, open: false })}
          sx={{ width: '100%' }}
        >
          {snackbar.message}
        </Alert>
      </Snackbar>

      <ConfirmationDialog
        open={confirmDialog.open}
        title={confirmDialog.title}
        message={confirmDialog.message}
        recordDetails={confirmDialog.recordDetails}
        confirmText={t('admin.projects.membersDialog.confirmRemove.confirm')}
        cancelText={t('common.cancel')}
        confirmColor="error"
        onConfirm={confirmDialog.action}
        onCancel={() => setConfirmDialog({ ...confirmDialog, open: false })}
      />
    </Dialog>
  );
};

export default ProjectMembersDialog;
