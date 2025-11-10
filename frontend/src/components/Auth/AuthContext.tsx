import React, { createContext, useContext, useState, useEffect, useCallback } from 'react';
import type { ReactNode } from 'react';

interface User {
  id: number;
  name: string;
  email: string;
  role: 'Technician' | 'Manager' | 'Admin';
  roles: string[];
  permissions: string[];
  is_manager: boolean;
  is_technician: boolean;
  is_admin: boolean;
  managed_projects: number[];
  project_memberships?: Array<{
    project_id: number;
    project_role: 'member' | 'manager';
    expense_role: 'member' | 'manager';
    finance_role: 'none' | 'member' | 'manager';
  }>;
}

interface AuthContextType {
  user: User | null;
  login: (email: string, password: string) => Promise<boolean>;
  logout: () => void;
  loading: boolean;
  // Utility functions for role checking
  isManager: () => boolean;
  isTechnician: () => boolean;
  isAdmin: () => boolean;
  canValidateTimesheets: () => boolean;
  canManageProjects: () => boolean;
  hasPermission: (permission: string) => boolean;
}

const AuthContext = createContext<AuthContextType | undefined>(undefined);

export const useAuth = () => {
  const context = useContext(AuthContext);
  if (context === undefined) {
    throw new Error('useAuth must be used within an AuthProvider');
  }
  return context;
};

interface AuthProviderProps {
  children: ReactNode;
}

export const AuthProvider: React.FC<AuthProviderProps> = ({ children }) => {
  const [user, setUser] = useState<User | null>(null);
  const [loading, setLoading] = useState(true);

  const normalizeUser = useCallback((userData: any): User => {
    const roles: string[] = Array.isArray(userData?.roles) ? userData.roles : [];
    const permissions: string[] = Array.isArray(userData?.permissions) ? userData.permissions : [];
    const managedProjectsRaw = Array.isArray(userData?.managed_projects) ? userData.managed_projects : [];
    const projectMemberships = Array.isArray(userData?.project_memberships) ? userData.project_memberships : [];

    return {
      id: Number(userData?.id),
      name: userData?.name ?? '',
      email: userData?.email ?? '',
      role: (userData?.role ?? 'Technician') as User['role'],
      roles,
      permissions,
      is_manager: Boolean(userData?.is_manager ?? roles.includes('Manager')),
      is_technician: Boolean(userData?.is_technician ?? roles.includes('Technician')),
      is_admin: Boolean(userData?.is_admin ?? roles.includes('Admin')),
      managed_projects: managedProjectsRaw.map((projectId: any) => Number(projectId)).filter((id: number) => !Number.isNaN(id)),
      project_memberships: projectMemberships,
    };
  }, []);

  // Check for existing session on mount
  useEffect(() => {
    const checkAuth = async () => {
      try {
        const token = localStorage.getItem('auth_token');
        if (token) {
          const response = await fetch('http://localhost:8080/api/user', {
            headers: {
              'Authorization': `Bearer ${token}`,
              'Accept': 'application/json'
            }
          });
          
          if (response.ok) {
            const userData = await response.json();
            setUser(normalizeUser(userData));
          } else {
            localStorage.removeItem('auth_token');
          }
        }
      } catch (error) {
        console.error('Auth check failed:', error);
        localStorage.removeItem('auth_token');
      } finally {
        setLoading(false);
      }
    };

    checkAuth();
  }, [normalizeUser]);

  const login = async (email: string, password: string): Promise<boolean> => {
    try {
      console.log('Attempting login with:', { email, password: '***' });
      
      const response = await fetch('http://localhost:8080/api/login', {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({ email, password })
      });

      console.log('Login response status:', response.status);

      if (response.ok) {
        const data = await response.json();
        console.log('Login success:', data);
        localStorage.setItem('auth_token', data.token);
        setUser(normalizeUser(data.user));
        return true;
      } else {
        const errorData = await response.json();
        console.error('Login failed with status', response.status, ':', errorData);
        return false;
      }
    } catch (error) {
      console.error('Login network error:', error);
      return false;
    }
  };

  const logout = () => {
    localStorage.removeItem('auth_token');
    setUser(null);
  };

  // Role checking utility functions
  const isManager = (): boolean => user?.is_manager || user?.roles?.includes('Manager') || false;
  const isTechnician = (): boolean => user?.is_technician || user?.roles?.includes('Technician') || false;
  const isAdmin = (): boolean => user?.is_admin || user?.roles?.includes('Admin') || false;
  
  const canValidateTimesheets = (): boolean => {
    return user?.permissions?.includes('approve-timesheets') || false;
  };
  
  const canManageProjects = (): boolean => {
    return user?.permissions?.includes('manage-projects') || false;
  };
  
  const hasPermission = (permission: string): boolean => {
    return user?.permissions?.includes(permission) || false;
  };

  return (
    <AuthContext.Provider value={{ 
      user, 
      login, 
      logout, 
      loading,
      isManager,
      isTechnician,
      isAdmin,
      canValidateTimesheets,
      canManageProjects,
      hasPermission
    }}>
      {children}
    </AuthContext.Provider>
  );
};