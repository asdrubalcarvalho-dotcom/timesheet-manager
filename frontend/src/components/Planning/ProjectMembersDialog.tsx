import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Box,
  Button,
  Checkbox,
  CircularProgress,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  List,
  ListItem,
  ListItemButton,
  ListItemIcon,
  ListItemText,
  Typography,
} from '@mui/material';
import api from '../../services/api';

type MemberRoleDefaults = {
  project_role: 'member' | 'manager';
  expense_role: 'member' | 'manager';
  finance_role: 'none' | 'member' | 'manager';
};

type ProjectRef = {
  id: number;
  name: string;
};

type UserRow = {
  id: number;
  name: string;
  email?: string;
  roles?: Array<{ name: string }>;
};

export type ProjectMembersDialogProps = {
  open: boolean;
  project: ProjectRef | null;
  initialMemberIds: number[];
  onClose: () => void;
  onSaved: () => Promise<void> | void;
};

const DEFAULT_ROLES: MemberRoleDefaults = {
  project_role: 'member',
  expense_role: 'member',
  finance_role: 'none',
};

const asSet = (ids: number[]) => new Set(ids.filter((v) => Number.isFinite(v)));

const ProjectMembersDialog: React.FC<ProjectMembersDialogProps> = ({
  open,
  project,
  initialMemberIds,
  onClose,
  onSaved,
}) => {
  const initialSet = useMemo(() => asSet(initialMemberIds), [initialMemberIds]);

  const [users, setUsers] = useState<UserRow[]>([]);
  const [loadingUsers, setLoadingUsers] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [checked, setChecked] = useState<Set<number>>(new Set());

  useEffect(() => {
    if (!open || !project) return;

    // Reset state on open to keep behavior predictable.
    setError(null);
    setChecked(new Set(initialSet));

    const loadUsers = async () => {
      try {
        setLoadingUsers(true);
        const res = await api.get('/api/access/users');
        const data = Array.isArray(res.data) ? res.data : res.data?.data || [];
        const normalized: UserRow[] = data
          .filter((u: any) => u && typeof u.id === 'number')
          .map((u: any) => ({
            id: u.id,
            name: u.name ?? `User ${u.id}`,
            email: u.email ?? '',
            roles: Array.isArray(u.roles) ? u.roles : [],
          }))
          .sort((a: UserRow, b: UserRow) => a.name.localeCompare(b.name));

        setUsers(normalized);
      } catch (e: any) {
        setError(e?.response?.data?.message || 'Failed to load users');
      } finally {
        setLoadingUsers(false);
      }
    };

    loadUsers();
  }, [open, project, initialSet]);

  const toggleUser = (userId: number) => {
    setChecked((prev) => {
      const next = new Set(prev);
      if (next.has(userId)) next.delete(userId);
      else next.add(userId);
      return next;
    });
  };

  const handleSave = async () => {
    if (!project) return;

    const nextSet = checked;

    const toAdd: number[] = [];
    const toRemove: number[] = [];

    nextSet.forEach((id) => {
      if (!initialSet.has(id)) toAdd.push(id);
    });

    initialSet.forEach((id) => {
      if (!nextSet.has(id)) toRemove.push(id);
    });

    if (toAdd.length === 0 && toRemove.length === 0) {
      onClose();
      return;
    }

    try {
      setSaving(true);
      setError(null);

      await Promise.all([
        ...toAdd.map((userId) =>
          api.post(`/api/projects/${project.id}/members`, {
            user_id: userId,
            ...DEFAULT_ROLES,
          })
        ),
        ...toRemove.map((userId) => api.delete(`/api/projects/${project.id}/members/${userId}`)),
      ]);

      await onSaved();
      onClose();
    } catch (e: any) {
      setError(e?.response?.data?.message || 'Failed to save members');
    } finally {
      setSaving(false);
    }
  };

  return (
    <Dialog open={open} onClose={saving ? undefined : onClose} maxWidth="sm" fullWidth>
      <DialogTitle>Manage Project Members</DialogTitle>

      <DialogContent dividers>
        {project ? (
          <Box sx={{ mb: 2 }}>
            <Typography variant="subtitle2" color="text.secondary">
              Project
            </Typography>
            <Typography variant="body1">{project.name}</Typography>
          </Box>
        ) : null}

        {error && (
          <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError(null)}>
            {error}
          </Alert>
        )}

        {loadingUsers ? (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}>
            <CircularProgress size={28} />
          </Box>
        ) : (
          <List dense disablePadding>
            {users.map((u) => {
              const isChecked = checked.has(u.id);
              const roles = (u.roles || []).map((r) => r.name).filter(Boolean);
              const secondaryParts = [u.email || '', roles.length ? `Roles: ${roles.join(', ')}` : '']
                .filter(Boolean)
                .join(' • ');

              return (
                <ListItem key={u.id} disablePadding>
                  <ListItemButton disabled={saving} onClick={() => toggleUser(u.id)}>
                    <ListItemIcon>
                      <Checkbox edge="start" checked={isChecked} tabIndex={-1} disableRipple />
                    </ListItemIcon>
                    <ListItemText primary={u.name} secondary={secondaryParts || undefined} />
                  </ListItemButton>
                </ListItem>
              );
            })}
          </List>
        )}
      </DialogContent>

      <DialogActions>
        <Button onClick={onClose} disabled={saving}>
          Cancel
        </Button>
        <Button variant="contained" onClick={handleSave} disabled={saving || !project}>
          {saving ? 'Saving…' : 'Save'}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default ProjectMembersDialog;
