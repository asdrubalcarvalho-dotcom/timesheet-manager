import React, {
  createContext,
  useContext,
  useState,
  useEffect,
  useCallback
} from 'react';
import { API_URL } from '../../services/api';
import type { ReactNode } from 'react';

/* ===============================================================
   TYPES
================================================================ */

interface User {
  id: number;
  name: string;
  email: string;
  role: 'Technician' | 'Manager' | 'Admin' | 'Owner';
  roles: string[];
  permissions: string[];
  is_owner: boolean;
  is_manager: boolean;
  is_technician: boolean;
  is_admin: boolean;
  managed_projects: number[];
  tenant_id?: string;
  tenant?: Tenant;
  project_memberships?: Array<{
    project_id: number;
    project_role: 'member' | 'manager';
    expense_role: 'member' | 'manager';
    finance_role: 'none' | 'member' | 'manager';
  }>;
}

interface Tenant {
  id: string;
  slug: string;
  name: string;
  status: string;
  plan?: string;
  ai_enabled?: boolean;
}

interface AuthContextType {
  user: User | null;
  tenant: Tenant | null;
  tenantSlug: string | null;
  login: (email: string, password: string, tenantSlug: string) => Promise<boolean>;
  logout: () => void;
  loading: boolean;
  isOwner: () => boolean;
  isManager: () => boolean;
  isTechnician: () => boolean;
  isAdmin: () => boolean;
  canValidateTimesheets: () => boolean;
  canManageProjects: () => boolean;
  hasPermission: (permission: string) => boolean;
}

/* ===============================================================
   CONTEXT
================================================================ */

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (!context) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

/* ===============================================================
   AUTH PROVIDER
================================================================ */

interface AuthProviderProps {
  children: ReactNode;
}

export const AuthProvider: React.FC<AuthProviderProps> = ({ children }) => {
  /* ---------------------------------------------------------------
     STATE
  --------------------------------------------------------------- */
  const [user, setUser] = useState<User | null>(null);
  const [tenant, setTenant] = useState<Tenant | null>(null);
  const [tenantSlug, setTenantSlugState] = useState<string | null>(null);
  const [loading, setLoading] = useState(true);



  /* ---------------------------------------------------------------
     NORMALIZE USER
  --------------------------------------------------------------- */
  const normalizeUser = useCallback((userData: any): User => {
    const roles = Array.isArray(userData?.roles) ? userData.roles : [];
    const permissions = Array.isArray(userData?.permissions)
      ? userData.permissions
      : [];
    const managedProjects = Array.isArray(userData?.managed_projects)
      ? userData.managed_projects
      : [];
    const memberships = Array.isArray(userData?.project_memberships)
      ? userData.project_memberships
      : [];

    const primaryRole = (userData?.role || 'Technician') as User['role'];

    return {
      id: Number(userData?.id),
      name: userData?.name ?? '',
      email: userData?.email ?? '',
      role: primaryRole,
      roles,
      permissions,
      is_owner: roles.includes('Owner') || primaryRole === 'Owner',
      is_manager: roles.includes('Manager') || primaryRole === 'Manager',
      is_technician: roles.includes('Technician') || primaryRole === 'Technician',
      is_admin:
        roles.includes('Admin') ||
        primaryRole === 'Admin' ||
        primaryRole === 'Owner',
      managed_projects: managedProjects
        .map((id: any) => Number(id))
        .filter((id: number) => !isNaN(id)),
      project_memberships: memberships,
      tenant_id: userData?.tenant_id
    };
  }, []);

  /* ---------------------------------------------------------------
     CHECK SESSION ON LOAD
  --------------------------------------------------------------- */
  useEffect(() => {
    const checkAuth = async () => {
      try {
        const token = localStorage.getItem('auth_token');
        const storedTenant = localStorage.getItem('tenant_slug');

        if (token && storedTenant) {
          setTenantSlugState(storedTenant);

          const response = await fetch(`${API_URL}/api/user`, {
            headers: {
              Authorization: `Bearer ${token}`,
              'X-Tenant': storedTenant,
            },
          });

          if (response.ok) {
            const userData = await response.json();
            setUser(normalizeUser(userData));
          } else {
            localStorage.removeItem('auth_token');
            localStorage.removeItem('tenant_slug');
          }
        }
      } catch (error) {
        console.error('Auth check failed:', error);
        localStorage.removeItem('auth_token');
        localStorage.removeItem('tenant_slug');
      } finally {
        setLoading(false);
      }
    };

    checkAuth();
  }, [normalizeUser, API_URL]);

  /* ---------------------------------------------------------------
     LOGIN
  --------------------------------------------------------------- */
  const login = async (
    email: string,
    password: string,
    tenantSlug: string
  ): Promise<boolean> => {
    try {
      const response = await fetch(`${API_URL}/api/login`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Accept: 'application/json',
          'X-Tenant': tenantSlug
        },
        body: JSON.stringify({
          email,
          password,
          tenant_slug: tenantSlug
        })
      });

      if (!response.ok) {
        const error = await response.json();
        console.error('Login failed:', error);
        return false;
      }

      const data = await response.json();

      localStorage.setItem('auth_token', data.token);
      localStorage.setItem('tenant_slug', tenantSlug);

      setUser(normalizeUser(data.user));
      setTenantSlugState(tenantSlug);

      if (data.tenant) {
        setTenant(data.tenant);
      }

      return true;
    } catch (error) {
      console.error('Login network error:', error);
      return false;
    }
  };

  /* ---------------------------------------------------------------
     LOGOUT
  --------------------------------------------------------------- */
  const logout = () => {
    localStorage.removeItem('auth_token');
    localStorage.removeItem('tenant_slug');
    setUser(null);
    setTenant(null);
    setTenantSlugState(null);
  };

  /* ---------------------------------------------------------------
     ROLE HELPERS
  --------------------------------------------------------------- */
  const isOwner = () => user?.is_owner ?? false;
  const isManager = () => user?.is_manager ?? false;
  const isTechnician = () => user?.is_technician ?? false;
  const isAdmin = () => user?.is_admin ?? false;

  const canValidateTimesheets = () =>
    user?.permissions?.includes('approve-timesheets') ?? false;

  const canManageProjects = () =>
    user?.permissions?.includes('manage-projects') ?? false;

  const hasPermission = (perm: string) =>
    user?.permissions?.includes(perm) ?? false;

  /* ---------------------------------------------------------------
     PROVIDER
  --------------------------------------------------------------- */
  return (
    <AuthContext.Provider
      value={{
        user,
        tenant,
        tenantSlug,
        login,
        logout,
        loading,
        isOwner,
        isManager,
        isTechnician,
        isAdmin,
        canValidateTimesheets,
        canManageProjects,
        hasPermission
      }}
    >
      {children}
    </AuthContext.Provider>
  );
};
