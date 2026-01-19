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
  region?: string | null;
  week_start?: string | null;
}

export interface TenantContext {
  region: string | null;
  week_start?: string | null;
  state?: string | null;
  policy_key?: string | null;
  timezone: string;
  locale: string;
  date_format: string;
  currency: string;
  currency_symbol: string;
}

export type CaptchaChallenge = {
  provider: string;
  site_key: string;
};

export type LoginResult =
  | { ok: true }
  | { ok: false; error: string }
  | { ok: false; rateLimited: true; retryAfterSeconds?: number }
  | { ok: false; ssoOnlyRequired: true }
  | { ok: false; captchaRequired: true; captcha: CaptchaChallenge };

interface AuthContextType {
  user: User | null;
  tenant: Tenant | null;
  tenantContext: TenantContext | null;
  tenantSlug: string | null;
  login: (email: string, password: string, tenantSlug: string, captchaToken?: string | null) => Promise<LoginResult>;
  logout: () => void;
  refreshUser: () => Promise<boolean>;
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
  const [tenantContext, setTenantContext] = useState<TenantContext | null>(null);
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

  const refreshUser = useCallback(async (): Promise<boolean> => {
    try {
      const token = localStorage.getItem('auth_token');
      const storedTenant = localStorage.getItem('tenant_slug');

      if (!token || !storedTenant) {
        return false;
      }

      const response = await fetch(`${API_URL}/api/user`, {
        headers: {
          Authorization: `Bearer ${token}`,
          'X-Tenant': storedTenant,
        },
      });

      if (!response.ok) {
        return false;
      }

      const userData = await response.json();
      setUser(normalizeUser(userData));
      setTenant(userData?.tenant ?? null);
      setTenantContext(userData?.tenant_context ?? null);
      setTenantSlugState(storedTenant);
      return true;
    } catch (error) {
      console.error('Failed to refresh user:', error);
      return false;
    }
  }, [normalizeUser]);

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

          const tryFetchUser = async (): Promise<Response> =>
            fetch(`${API_URL}/api/user`, {
              headers: {
                Authorization: `Bearer ${token}`,
                'X-Tenant': storedTenant,
              },
            });

          let response: Response | null = null;
          let lastError: unknown = null;

          for (let attempt = 0; attempt < 2; attempt++) {
            try {
              response = await tryFetchUser();
              break;
            } catch (error) {
              lastError = error;
              // Navigation or transient network hiccups can cancel this request (nginx 499).
              // Don't clear auth storage on these; retry once.
              if (attempt === 0) {
                await new Promise((resolve) => setTimeout(resolve, 500));
                continue;
              }
            }
          }

          if (response?.ok) {
            const userData = await response.json();
            setUser(normalizeUser(userData));
            setTenant(userData?.tenant ?? null);
            setTenantContext(userData?.tenant_context ?? null);
          } else if (response) {
            // Only clear stored auth when we're confident the session is invalid.
            // 401/419: invalid/expired token
            // 404: tenant not found (common after docker-compose down -v resets DB while browser storage is stale)
            if (response.status === 401 || response.status === 419 || response.status === 404) {
              localStorage.removeItem('auth_token');
              localStorage.removeItem('tenant_slug');
            } else {
              console.warn('Auth check failed with non-terminal status:', response.status);
            }
          } else {
            console.warn('Auth check failed (no response):', lastError);
          }
        }
      } catch (error) {
        console.error('Auth check failed:', error);
        // Do not clear auth on unexpected exceptions; it can cause unnecessary logouts.
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
    tenantSlug: string,
    captchaToken?: string | null
  ): Promise<LoginResult> => {
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
          tenant_slug: tenantSlug,
          ...(captchaToken ? { captcha_token: captchaToken } : {}),
        })
      });

      if (!response.ok) {
        let data: any = null;
        try {
          data = await response.json();
        } catch {
          // ignore
        }

        // 429 must be treated as a terminal state in the UI (no retries, no CAPTCHA reload).
        if (response.status === 429) {
          const retryAfterHeader = response.headers.get('retry-after');
          const rateLimitResetHeader = response.headers.get('x-ratelimit-reset');

          let retryAfterSeconds: number | undefined;

          if (retryAfterHeader) {
            const parsed = Number(retryAfterHeader);
            if (!Number.isNaN(parsed) && parsed > 0) {
              retryAfterSeconds = Math.floor(parsed);
            }
          }

          // Some stacks return X-RateLimit-Reset as epoch seconds.
          if (retryAfterSeconds === undefined && rateLimitResetHeader) {
            const resetEpoch = Number(rateLimitResetHeader);
            if (!Number.isNaN(resetEpoch) && resetEpoch > 0) {
              const nowEpoch = Math.floor(Date.now() / 1000);
              const delta = resetEpoch - nowEpoch;
              if (delta > 0) {
                retryAfterSeconds = delta;
              }
            }
          }

          return { ok: false, rateLimited: true, retryAfterSeconds };
        }

        if (
          response.status === 403 &&
          typeof data?.message === 'string' &&
          data.message.toLowerCase().includes('requires single sign-on')
        ) {
          return { ok: false, ssoOnlyRequired: true };
        }

        if (response.status === 422 && data?.code === 'captcha_required' && data?.captcha) {
          return {
            ok: false,
            captchaRequired: true,
            captcha: {
              provider: String(data.captcha.provider || ''),
              site_key: String(data.captcha.site_key || ''),
            },
          };
        }

        const message =
          typeof data?.message === 'string' && data.message.trim()
            ? data.message
            : 'Invalid credentials or tenant not found';

        console.error('Login failed:', data);
        return { ok: false, error: message };
      }

      const data = await response.json();

      localStorage.setItem('auth_token', data.token);
      localStorage.setItem('tenant_slug', tenantSlug);

      setUser(normalizeUser(data.user));
      setTenantSlugState(tenantSlug);

      setTenant(data?.tenant ?? data?.user?.tenant ?? null);
      setTenantContext(data?.user?.tenant_context ?? null);

      return { ok: true };
    } catch (error) {
      console.error('Login network error:', error);
      return { ok: false, error: 'Network error. Please try again.' };
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
    setTenantContext(null);
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
        tenantContext,
        tenantSlug,
        login,
        logout,
        refreshUser,
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
