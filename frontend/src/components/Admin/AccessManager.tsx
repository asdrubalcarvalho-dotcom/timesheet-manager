import React, { useCallback, useEffect, useState } from 'react';
import {
  Box,
  Button,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  CircularProgress,
  Checkbox,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
  Typography
} from '@mui/material';
import api from '../../services/api';
import AdminLayout from './AdminLayout';
import { useNotification } from '../../contexts/NotificationContext';

interface Role {
  id: number;
  name: string;
}
interface Permission {
  id: number;
  name: string;
}
interface RolePermissions {
  [roleName: string]: string[]; // permission names
}

const AccessManager: React.FC = () => {
  const { showSuccess, showError } = useNotification();
  const [roles, setRoles] = useState<Role[]>([]);
  const [permissions, setPermissions] = useState<Permission[]>([]);
  const [rolePermissions, setRolePermissions] = useState<RolePermissions>({});
  const [loading, setLoading] = useState(true);
  const [matrixLoading, setMatrixLoading] = useState(false);
  const [matrixOpen, setMatrixOpen] = useState(false);
  const [aiSuggestion, setAiSuggestion] = useState<string | null>(null);

  useEffect(() => {
    fetchBaseData();
  }, []);

  const fetchBaseData = async () => {
    setLoading(true);
    try {
      const [rolesRes, permsRes] = await Promise.all([
        api.get('/access/roles'),
        api.get('/access/permissions')
      ]);
      const rolesData: Role[] = Array.isArray(rolesRes.data) ? rolesRes.data : rolesRes.data?.data ?? [];
      const permissionsData: Permission[] = Array.isArray(permsRes.data) ? permsRes.data : permsRes.data?.data ?? [];

      setRoles(rolesData);
      setPermissions(permissionsData);
    } catch (err) {
      showError('Failed to load roles/permissions');
    } finally {
      setLoading(false);
    }
  };

  const fetchRolePermissionsMatrix = useCallback(async () => {
    if (!roles.length) {
      return;
    }
    setMatrixLoading(true);
    try {
      const entries = await Promise.all(
        roles.map(async (role) => {
          const perms = await api.get(`/access/roles/${encodeURIComponent(role.name)}/permissions`);
          const permsArray: Permission[] = Array.isArray(perms.data) ? perms.data : perms.data?.data ?? [];
          return [role.name, permsArray.map((p) => p.name)] as const;
        })
      );
      setRolePermissions(Object.fromEntries(entries));
    } catch (err) {
      showError('Failed to load role permissions');
    } finally {
      setMatrixLoading(false);
    }
  }, [roles]);

  useEffect(() => {
    if (matrixOpen) {
      fetchRolePermissionsMatrix();
    }
  }, [matrixOpen, fetchRolePermissionsMatrix]);

  const handleMatrixOpen = () => setMatrixOpen(true);
  const handleMatrixClose = () => setMatrixOpen(false);

  const handleTogglePermission = async (role: string, permission: string) => {
    setMatrixLoading(true);
    try {
      const hasPerm = rolePermissions[role]?.includes(permission);
      if (hasPerm) {
        await api.post(`/access/roles/${role}/remove-permission`, { permission });
        showSuccess(`Removed ${permission} from ${role}`);
      } else {
        await api.post(`/access/roles/${role}/assign-permission`, { permission });
        showSuccess(`Assigned ${permission} to ${role}`);
      }
      setRolePermissions((prev) => {
        const current = prev[role] ?? [];
        const updated = hasPerm ? current.filter((perm) => perm !== permission) : [...current, permission];
        return { ...prev, [role]: updated };
      });
    } catch (err) {
      showError('Failed to update permission');
    } finally {
      setMatrixLoading(false);
    }
  };

  const fetchAiSuggestion = async () => {
    setLoading(true);
    try {
      const res = await api.get('/ai/suggestions/access');
      setAiSuggestion(res.data.suggestion || 'No suggestion available');
    } catch (err) {
      setAiSuggestion('Failed to fetch AI suggestion');
    } finally {
      setLoading(false);
    }
  };

  return (
    <AdminLayout title="Access Management">
      <Box sx={{ mb: 2, display: 'flex', gap: 2 }}>
        <Button variant="contained" onClick={handleMatrixOpen} sx={{ bgcolor: '#1976d2', color: '#fff' }}>
          Edit Matrix (Roles × Permissions)
        </Button>
        <Button variant="outlined" onClick={fetchAiSuggestion}>
          AI Suggestion
        </Button>
      </Box>
      {aiSuggestion && (
        <Typography color="info.main" sx={{ mb: 2, p: 2, bgcolor: '#e3f2fd', borderRadius: 1 }}>
          {aiSuggestion}
        </Typography>
      )}
      {loading ? <CircularProgress /> : null}
      <Dialog 
        open={matrixOpen} 
        onClose={handleMatrixClose} 
        maxWidth="lg" 
        fullWidth
        disableRestoreFocus
      >
        <DialogTitle>Roles × Permissions Matrix</DialogTitle>
        <DialogContent>
          {matrixLoading && (
            <Box sx={{ display: 'flex', justifyContent: 'center', mb: 2 }}>
              <CircularProgress size={24} />
            </Box>
          )}
          <TableContainer component={Paper}>
            <Table size="small">
              <TableHead>
                <TableRow>
                  <TableCell>Role</TableCell>
                  {permissions.map((perm) => (
                    <TableCell key={perm.name} align="center">{perm.name}</TableCell>
                  ))}
                </TableRow>
              </TableHead>
              <TableBody>
                {roles.map((role) => (
                  <TableRow key={role.name}>
                    <TableCell>{role.name}</TableCell>
                    {permissions.map((perm) => (
                      <TableCell key={perm.name} align="center">
                        <Checkbox
                          checked={rolePermissions[role.name]?.includes(perm.name) || false}
                          onChange={() => handleTogglePermission(role.name, perm.name)}
                          color="primary"
                        />
                      </TableCell>
                    ))}
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        </DialogContent>
        <DialogActions>
          <Button onClick={handleMatrixClose}>Close</Button>
        </DialogActions>
      </Dialog>
    </AdminLayout>
  );
};

export default AccessManager;
