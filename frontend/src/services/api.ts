import axios from 'axios';
import type { 
  ApiResponse, 
  Technician, 
  Project, 
  Timesheet, 
  Expense, 
  TimesheetFormData, 
  ExpenseFormData,
  TimesheetValidationResult,
  TimesheetPermissions,
  TimesheetManagerResponse,
  DashboardStatistics,
  TopProject
} from '../types';

/**
 * API ROOT (sem /api no fim - será adicionado nas rotas)
 * - PROD:  https://api.vendaslive.com
 * - DEV (Docker): http://webserver   (via VITE_API_URL)
 * - DEV (fora de Docker): http://api.localhost
 */
export const API_URL =
  import.meta.env.VITE_API_URL || 'http://api.localhost';

// Axios instance - baseURL SEM /api (adicionado em cada rota)
const api = axios.create({
  baseURL: API_URL,
  headers: {
    'Content-Type': 'application/json',
    Accept: 'application/json',
  },
});

/**
 * Extract tenant slug from subdomain or localStorage
 * Order: subdomain > localStorage
 */
const getTenantSlug = (): string | null => {
  // Try subdomain first (e.g., "acme" from "acme.app.timeperk.com")
  const host = window.location.hostname;
  const parts = host.split('.');
  
  // If subdomain exists and it's not "app" or "www", use it as tenant
  if (parts.length > 2 && parts[0] !== 'app' && parts[0] !== 'www') {
    return parts[0];
  }
  
  // Fall back to localStorage (set during login)
  return localStorage.getItem('tenant_slug');
};

/**
 * Set tenant slug in localStorage (called after successful login/registration)
 */
export const setTenantSlug = (slug: string): void => {
  localStorage.setItem('tenant_slug', slug);
};

/**
 * Remove tenant slug from localStorage (called during logout)
 */
export const clearTenantSlug = (): void => {
  localStorage.removeItem('tenant_slug');
};

/**
 * Get headers for fetch requests (includes Authorization + X-Tenant)
 * Use this helper when using native fetch() instead of axios api instance
 */
export const getAuthHeaders = (): HeadersInit => {
  const headers: HeadersInit = {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  };

  const token = localStorage.getItem('auth_token');
  if (token) {
    headers['Authorization'] = `Bearer ${token}`;
  }

  const tenantSlug = getTenantSlug();
  if (tenantSlug) {
    headers['X-Tenant'] = tenantSlug;
  }

  return headers;
};

/**
 * Use native fetch with the same auth + tenant headers as axios instance
 */
export const fetchWithAuth = async (input: RequestInfo, init: RequestInit = {}) => {
  const headers = {
    ...(init.headers || {}),
    ...getAuthHeaders(),
  } as HeadersInit;

  const finalInit: RequestInit = {
    ...init,
    headers,
    // DO NOT use credentials: 'include' - auth via Bearer token in header
  };

  return fetch(input, finalInit);
};

// Add authentication + tenant interceptor
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  
  // Inject X-Tenant header for tenant-scoped requests
  const tenantSlug = getTenantSlug();
  if (tenantSlug) {
    config.headers['X-Tenant'] = tenantSlug;
    console.log('[API] X-Tenant header set to:', tenantSlug);
  } else {
    console.warn('[API] No tenant slug found! Checking localStorage:', localStorage.getItem('tenant_slug'));
  }
  
  return config;
});

export interface TimesheetMutationResponse {
  data: Timesheet;
  validation?: TimesheetValidationResult;
  message?: string;
  permissions?: TimesheetPermissions;
}

export interface TimesheetManagerViewParams {
  date_from?: string;
  date_to?: string;
  status?: string;
  technician_ids?: number[];
}

// Technicians API
export const techniciansApi = {
  getAll: (): Promise<ApiResponse<Technician[]>> =>
    api.get('/api/technicians').then(res => res.data),
  
  getById: (id: number): Promise<ApiResponse<Technician>> =>
    api.get(`/api/technicians/${id}`).then(res => res.data),
  
  create: (data: Partial<Technician>): Promise<ApiResponse<Technician>> =>
    api.post('/api/technicians', data).then(res => res.data),
  
  update: (id: number, data: Partial<Technician>): Promise<ApiResponse<Technician>> =>
    api.put(`/api/technicians/${id}`, data).then(res => res.data),
  
  delete: (id: number): Promise<ApiResponse<void>> =>
    api.delete(`/api/technicians/${id}`).then(res => res.data),
};

// Projects API
export const projectsApi = {
  getAll: (): Promise<Project[]> =>
    api.get('/api/projects').then(res => res.data),
  
  getForCurrentUser: (): Promise<Project[]> =>
    api.get('/api/user/projects').then(res => res.data),
  
  getById: (id: number): Promise<Project> =>
    api.get(`/api/projects/${id}`).then(res => res.data),
  
  create: (data: Partial<Project>): Promise<Project> =>
    api.post('/api/projects', data).then(res => res.data),
  
  update: (id: number, data: Partial<Project>): Promise<Project> =>
    api.put(`/api/projects/${id}`, data).then(res => res.data),
  
  delete: (id: number): Promise<void> =>
    api.delete(`/api/projects/${id}`).then(res => res.data),
};

// Timesheets API
export const timesheetsApi = {
  getAll: (params?: { 
    technician_id?: number; 
    project_id?: number; 
    date_from?: string; 
    date_to?: string; 
  }): Promise<Timesheet[]> =>
    api.get('/api/timesheets', { params }).then(res => res.data),
  
  getById: (id: number): Promise<TimesheetMutationResponse> =>
    api.get(`/api/timesheets/${id}`).then(res => res.data),
  
  create: (data: TimesheetFormData): Promise<TimesheetMutationResponse> =>
    api.post('/api/timesheets', data).then(res => res.data),
  
  update: (id: number, data: Partial<TimesheetFormData>): Promise<TimesheetMutationResponse> =>
    api.put(`/api/timesheets/${id}`, data).then(res => res.data),
  
  delete: (id: number): Promise<void> =>
    api.delete(`/api/timesheets/${id}`).then(res => res.data),

  getValidation: (id: number): Promise<TimesheetValidationResult> =>
    api.get(`/api/timesheets/${id}/validation`).then(res => res.data),

  getManagerView: (params?: TimesheetManagerViewParams): Promise<TimesheetManagerResponse> =>
    api.get('/api/timesheets/manager-view', { params }).then(res => res.data),
  
  getPendingCounts: (): Promise<{ timesheets: number; expenses: number; total: number }> =>
    api.get('/api/timesheets/pending-counts').then(res => res.data),
  
  submit: (id: number): Promise<Timesheet> =>
    api.patch(`/api/timesheets/${id}/submit`).then(res => res.data),
  
  approve: (id: number): Promise<Timesheet> =>
    api.put(`/api/timesheets/${id}/approve`).then(res => res.data),
  
  reject: (id: number, reason: string): Promise<Timesheet> =>
    api.put(`/api/timesheets/${id}/reject`, { reason }).then(res => res.data),
};

// Expenses API
export const expensesApi = {
  getAll: (params?: { 
    technician_id?: number; 
    project_id?: number; 
    date_from?: string; 
    date_to?: string; 
  }): Promise<ApiResponse<Expense[]>> =>
    api.get('/api/expenses', { params }).then(res => res.data),
  
  getById: (id: number): Promise<ApiResponse<Expense>> =>
    api.get(`/api/expenses/${id}`).then(res => res.data),
  
  create: (data: ExpenseFormData): Promise<ApiResponse<Expense>> => {
    const formData = new FormData();
    Object.entries(data).forEach(([key, value]) => {
      if (value !== undefined) {
        if (key === 'attachment' && value instanceof File) {
          formData.append(key, value);
        } else {
          formData.append(key, String(value));
        }
      }
    });
    return api.post('/api/expenses', formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    }).then(res => res.data);
  },
  
  update: (id: number, data: Partial<ExpenseFormData>): Promise<ApiResponse<Expense>> => {
    const formData = new FormData();
    Object.entries(data).forEach(([key, value]) => {
      if (value !== undefined) {
        if (key === 'attachment' && value instanceof File) {
          formData.append(key, value);
        } else {
          formData.append(key, String(value));
        }
      }
    });
    return api.put(`/api/expenses/${id}`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    }).then(res => res.data);
  },
  
  delete: (id: number): Promise<ApiResponse<void>> =>
    api.delete(`/api/expenses/${id}`).then(res => res.data),
  
  submit: (id: number): Promise<ApiResponse<Expense>> =>
    api.patch(`/api/expenses/${id}/submit`).then(res => res.data),
  
  approve: (id: number): Promise<ApiResponse<Expense>> =>
    api.patch(`/api/expenses/${id}/approve`).then(res => res.data),
  
  reject: (id: number): Promise<ApiResponse<Expense>> =>
    api.patch(`/api/expenses/${id}/reject`).then(res => res.data),
};

// Tasks API
export const tasksApi = {
  getAll: (): Promise<any[]> =>
    api.get('/api/tasks').then(res => res.data),
  
  getById: (id: number): Promise<any> =>
    api.get(`/api/tasks/${id}`).then(res => res.data),
  
  getByProject: (projectId: number): Promise<any[]> =>
    api.get(`/api/projects/${projectId}/tasks`).then(res => res.data),
};

// Locations API
export const locationsApi = {
  getAll: (): Promise<any[]> =>
    api.get('/api/locations').then(res => res.data),
  
  getActive: (): Promise<any[]> =>
    api.get('/api/locations/active').then(res => res.data),
  
  getById: (id: number): Promise<any> =>
    api.get(`/api/locations/${id}`).then(res => res.data),
};

// Dashboard API
export const dashboardApi = {
  getStatistics: (params?: {
    date_from?: string;
    date_to?: string;
  }): Promise<DashboardStatistics> =>
    api.get('/api/dashboard/statistics', { params }).then(res => res.data),
  
  getTopProjects: (params?: {
    limit?: number;
    metric?: 'hours' | 'expenses';
    date_from?: string;
    date_to?: string;
  }): Promise<TopProject[]> =>
    api.get('/api/dashboard/top-projects', { params }).then(res => res.data),
};

// Tenant API (Central - no tenant context required)
export interface TenantRegistrationData {
  company_name: string;
  slug: string;
  admin_name: string;
  admin_email: string;
  admin_password: string;
  admin_password_confirmation: string;
  industry?: string;
  country?: string;
  timezone?: string;
  plan?: 'trial' | 'standard' | 'premium' | 'enterprise';
}

export interface TenantRegistrationResponse {
  status: string;
  message: string;
  tenant: string; // tenant slug
  database: string; // database name
  tenant_info: {
    id: string;
    slug: string;
    name: string;
    domain: string;
    status: string;
    trial_ends_at: string;
  };
  admin: {
    email: string;
    token: string;
  };
  next_steps: {
    login_url: string;
    api_header: string;
  };
}

export const tenantApi = {
  /**
   * Register a new tenant (company)
   * This endpoint does NOT require X-Tenant header
   */
  register: (data: TenantRegistrationData): Promise<TenantRegistrationResponse> =>
    api.post('/api/tenants/request-signup', data).then(res => res.data),
  
  /**
   * List all tenants (Admin only)
   */
  list: (): Promise<any[]> =>
    api.get('/api/tenants').then(res => res.data.tenants),
  
  /**
   * Get tenant details by slug
   */
  get: (slug: string): Promise<any> =>
    api.get(`/api/tenants/${slug}`).then(res => res.data.tenant),
};

export default api;
