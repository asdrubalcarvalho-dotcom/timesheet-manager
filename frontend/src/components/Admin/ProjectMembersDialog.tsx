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
      const response = await api.get(`/projects/${projectId}/members`);
      setMembers(normalizeArray<ProjectMember>(response.data));
    } catch {
      setMembers([]);
      showSnackbar('Failed to load project members', 'error');
    } finally {
      setLoadingMembers(false);
    }
  };

  const fetchAvailableUsers = async () => {
    try {
      const response = await api.get('/technicians');
      setAvailableUsers(normalizeArray<Technician>(response.data));
    } catch {
      showSnackbar('Failed to load available users', 'error');
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

    setUpdatingMemberId(member.user_id);
    try {
      const payload = {
        project_role: field === 'project_role' ? value : member.project_role,
        expense_role: field === 'expense_role' ? value : member.expense_role,
        finance_role: field === 'finance_role' ? value : member.finance_role
      };
      const response = await api.put(`/projects/${project.id}/members/${member.user_id}`, payload);
      setMembers((prev) => prev.map((item) => (item.id === member.id ? response.data : item)));
      showSnackbar('Roles updated successfully', 'success');
    } catch {
      showSnackbar('Unable to update roles', 'error');
    } finally {
      setUpdatingMemberId(null);
    }
  };

  const handleRemoveMember = async (member: ProjectMember) => {
    if (!project) return;
    
    setConfirmDialog({
      open: true,
      title: 'Remove Member',
      message: `Are you sure you want to remove this member from the project?`,
      recordDetails: {
        name: member.user?.name,
        email: member.user?.email,
        project_role: member.project_role,
        expense_role: member.expense_role,
        finance_role: member.finance_role
      },
      action: async () => {
        setUpdatingMemberId(member.user_id);
        try {
          await api.delete(`/projects/${project.id}/members/${member.user_id}`);
          setMembers((prev) => prev.filter((item) => item.id !== member.id));
          showSnackbar('Member removed', 'success');
        } catch {
          showSnackbar('Failed to remove member', 'error');
        } finally {
          setUpdatingMemberId(null);
        }
        setConfirmDialog({ ...confirmDialog, open: false });
      }
    });
  };

  const handleAddMember = async () => {
    if (!project || formData.user_id === '') return;

    setSavingNewMember(true);
    try {
      const response = await api.post(`/projects/${project.id}/members`, {
        user_id: formData.user_id,
        project_role: formData.project_role,
        expense_role: formData.expense_role,
        finance_role: formData.finance_role
      });
      setMembers((prev) => [...prev, response.data]);
      resetForm();
      showSnackbar('Member added to the project', 'success');
    } catch {
      showSnackbar('Failed to add member', 'error');
    } finally {
      setSavingNewMember(false);
    }
  };

  if (!project) {
    return null;
  }

  return (
    <Dialog open={open} onClose={onClose} fullWidth maxWidth="md">
      <DialogTitle>Manage members — {project.name}</DialogTitle>
      <DialogContent dividers>
        {loadingMembers ? (
          <Box sx={{ py: 4, display: 'flex', justifyContent: 'center' }}>
            <CircularProgress size={32} />
          </Box>
        ) : members.length === 0 ? (
          <Alert severity="info">No members are linked to this project yet.</Alert>
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
                      {member.user?.name ?? 'Unnamed user'}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      {member.user?.email ?? 'No email provided'}
                    </Typography>
                  </Box>
                  <IconButton
                    aria-label="Remove member"
                    color="error"
                    size="small"
                    onClick={() => handleRemoveMember(member)}
                    disabled={updatingMemberId === member.user_id}
                  >
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </Box>

                <Stack direction={{ xs: 'column', sm: 'row' }} spacing={2}>
                  <TextField
                    select
                    fullWidth
                    label="Timesheet Role"
                    value={member.project_role}
                    onChange={(event) =>
                      handleMemberRoleChange(member, 'project_role', event.target.value as Role)
                    }
                    disabled={updatingMemberId === member.user_id}
                  >
                    <MenuItem value="none">
                      <em>None</em>
                    </MenuItem>
                    <MenuItem value="member">Member</MenuItem>
                    <MenuItem value="manager">Manager</MenuItem>
                  </TextField>

                  <TextField
                    select
                    fullWidth
                    label="Expense Role"
                    value={member.expense_role}
                    onChange={(event) =>
                      handleMemberRoleChange(member, 'expense_role', event.target.value as Role)
                    }
                    disabled={updatingMemberId === member.user_id}
                  >
                    <MenuItem value="none">
                      <em>None</em>
                    </MenuItem>
                    <MenuItem value="member">Member</MenuItem>
                    <MenuItem value="manager">Manager</MenuItem>
                  </TextField>

                  <TextField
                    select
                    fullWidth
                    label="Finance Role"
                    value={member.finance_role || 'none'}
                    onChange={(event) =>
                      handleMemberRoleChange(member, 'finance_role', event.target.value as Role)
                    }
                    disabled={updatingMemberId === member.user_id}
                  >
                    <MenuItem value="none">
                      <em>None</em>
                    </MenuItem>
                    <MenuItem value="member">Member</MenuItem>
                    <MenuItem value="manager">Manager</MenuItem>
                  </TextField>
                </Stack>
              </Box>
            ))}
          </Stack>
        )}

        <Divider sx={{ my: 3 }} />

        <Typography variant="subtitle1" fontWeight={600} gutterBottom>
          Add new member
        </Typography>

        {assignableUsers.length === 0 ? (
          <Alert severity="warning">
            No users are available to assign. Create a technician first.
          </Alert>
        ) : (
          <Stack spacing={2} mt={1}>
            <TextField
              select
              label="User"
              fullWidth
              value={formData.user_id}
              onChange={(event) =>
                setFormData({ ...formData, user_id: Number(event.target.value) })
              }
              disabled={savingNewMember}
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
                label="Timesheet Role"
                value={formData.project_role}
                onChange={(event) =>
                  setFormData({ ...formData, project_role: event.target.value as Role })
                }
                disabled={savingNewMember}
              >
                <MenuItem value="none">
                  <em>None</em>
                </MenuItem>
                <MenuItem value="member">Member</MenuItem>
                <MenuItem value="manager">Manager</MenuItem>
              </TextField>

              <TextField
                select
                fullWidth
                label="Expense Role"
                value={formData.expense_role}
                onChange={(event) =>
                  setFormData({ ...formData, expense_role: event.target.value as Role })
                }
                disabled={savingNewMember}
              >
                <MenuItem value="none">
                  <em>None</em>
                </MenuItem>
                <MenuItem value="member">Member</MenuItem>
                <MenuItem value="manager">Manager</MenuItem>
              </TextField>

              <TextField
                select
                fullWidth
                label="Finance Role"
                value={formData.finance_role}
                onChange={(event) =>
                  setFormData({ ...formData, finance_role: event.target.value as Role })
                }
                disabled={savingNewMember}
              >
                <MenuItem value="none">
                  <em>None</em>
                </MenuItem>
                <MenuItem value="member">Member</MenuItem>
                <MenuItem value="manager">Manager</MenuItem>
              </TextField>
            </Stack>
          </Stack>
        )}
      </DialogContent>
      <DialogActions>
        <Button onClick={onClose}>Close</Button>
        <Button
          variant="contained"
          onClick={handleAddMember}
          disabled={savingNewMember || formData.user_id === ''}
        >
          Assign user
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
        confirmText="Remove"
        cancelText="Cancel"
        confirmColor="error"
        onConfirm={confirmDialog.action}
        onCancel={() => setConfirmDialog({ ...confirmDialog, open: false })}
      />
    </Dialog>
  );
};

export default ProjectMembersDialog;
