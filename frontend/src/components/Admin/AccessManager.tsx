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
import { useFeatures } from '../../contexts/FeatureContext';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import { useTranslation } from 'react-i18next';

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
  const { t } = useTranslation();
  const { showSuccess, showError, showInfo } = useNotification();
  const { hasAI } = useFeatures();
  const { isReadOnly, ensureWritable } = useReadOnlyGuard('admin-access');
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
        api.get('/api/access/roles'),
        api.get('/api/access/permissions')
      ]);
      const rolesData: Role[] = Array.isArray(rolesRes.data) ? rolesRes.data : rolesRes.data?.data ?? [];
      const permissionsData: Permission[] = Array.isArray(permsRes.data) ? permsRes.data : permsRes.data?.data ?? [];

      setRoles(rolesData);
      setPermissions(permissionsData);
    } catch (err) {
      showError(t('admin.access.notifications.loadFailed'));
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
          const perms = await api.get(`/api/access/roles/${encodeURIComponent(role.name)}/permissions`);
          const permsArray: Permission[] = Array.isArray(perms.data) ? perms.data : perms.data?.data ?? [];
          return [role.name, permsArray.map((p) => p.name)] as const;
        })
      );
      setRolePermissions(Object.fromEntries(entries));
    } catch (err) {
      showError(t('admin.access.notifications.loadMatrixFailed'));
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
    if (!ensureWritable()) {
      return;
    }
    setMatrixLoading(true);
    try {
      const hasPerm = rolePermissions[role]?.includes(permission);
      if (hasPerm) {
        await api.post(`/api/access/roles/${role}/remove-permission`, { permission });
        showSuccess(t('admin.access.notifications.permissionRemoved', { permission, role }));
      } else {
        await api.post(`/api/access/roles/${role}/assign-permission`, { permission });
        showSuccess(t('admin.access.notifications.permissionAssigned', { permission, role }));
      }
      setRolePermissions((prev) => {
        const current = prev[role] ?? [];
        const updated = hasPerm ? current.filter((perm) => perm !== permission) : [...current, permission];
        return { ...prev, [role]: updated };
      });
    } catch (err) {
      showError(t('admin.access.notifications.updatePermissionFailed'));
    } finally {
      setMatrixLoading(false);
    }
  };

  const fetchAiSuggestion = async () => {
    // Feature gate: AI must be enabled
    if (!hasAI) {
      showInfo(t('admin.access.notifications.aiNotInPlan'));
      return;
    }
    
    setLoading(true);
    try {
      const res = await api.get('/api/ai/suggestions/access');
      setAiSuggestion(res.data.suggestion || t('admin.access.ai.noSuggestion'));
    } catch (err) {
      setAiSuggestion(t('admin.access.ai.fetchFailed'));
    } finally {
      setLoading(false);
    }
  };

  return (
    <AdminLayout title={t('admin.access.title')}>
      <Box sx={{ mb: 2, display: 'flex', gap: 2 }}>
        <Button variant="contained" onClick={handleMatrixOpen} sx={{ bgcolor: '#1976d2', color: '#fff' }}>
          {t('admin.access.actions.editMatrix')}
        </Button>
        {hasAI && (
          <Button variant="outlined" onClick={fetchAiSuggestion}>
            {t('admin.access.actions.aiSuggestion')}
          </Button>
        )}
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
        <DialogTitle>{t('admin.access.matrix.title')}</DialogTitle>
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
                  <TableCell>{t('admin.access.matrix.role')}</TableCell>
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
                          disabled={isReadOnly}
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
          <Button onClick={handleMatrixClose}>{t('common.close')}</Button>
        </DialogActions>
      </Dialog>
    </AdminLayout>
  );
};

export default AccessManager;
