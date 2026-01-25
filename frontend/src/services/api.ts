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
import { notifyGlobal } from './globalNotifications';

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
  const reservedSubdomains = new Set(['app', 'www', 'api', 'management']);
  
  // If subdomain exists and it's not "app" or "www", use it as tenant
  if (parts.length > 2 && !reservedSubdomains.has(parts[0])) {
    return parts[0];
  }

  // Local dev convenience: treat "<tenant>.localhost" as a tenant slug.
  // Example: http://upg.localhost:8082 should send X-Tenant: upg
  if (parts.length === 2 && parts[1] === 'localhost' && !reservedSubdomains.has(parts[0])) {
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

// In-memory dedupe for upgrade-required toasts to avoid spamming on auto-load pages
const UPGRADE_TOAST_DEDUPE_WINDOW_MS = 60_000;
const upgradeToastLastShownAt = new Map<string, number>();

const shouldShowUpgradeToast = (key: string): boolean => {
  const now = Date.now();
  const lastShownAt = upgradeToastLastShownAt.get(key);

  if (lastShownAt && now - lastShownAt < UPGRADE_TOAST_DEDUPE_WINDOW_MS) {
    return false;
  }

  upgradeToastLastShownAt.set(key, now);
  return true;
};

const READ_ONLY_STORAGE_KEY = 'tenant_read_only_mode';

const setReadOnlyModeFlag = (enabled: boolean): void => {
  try {
    if (enabled) {
      localStorage.setItem(READ_ONLY_STORAGE_KEY, '1');
    } else {
      localStorage.removeItem(READ_ONLY_STORAGE_KEY);
    }
    window.dispatchEvent(new Event('tenant-read-only-mode-changed'));
  } catch {
    // ignore storage/event errors
  }
};

// Global 403 handling (UX-only): show backend message, then re-throw.
api.interceptors.response.use(
  (response) => response,
  (error) => {
    const status = error?.response?.status;
    if (status === 403) {
      const data = error?.response?.data;
      const upgradeRequired = data?.upgrade_required === true;

      if (upgradeRequired) {
        const reason = typeof data?.reason === 'string' ? data.reason : undefined;

        if (reason === 'subscription_expired') {
          setReadOnlyModeFlag(true);

          const dedupeKey = 'subscription_expired';
          if (shouldShowUpgradeToast(dedupeKey)) {
            notifyGlobal('Your subscription has expired. You are in read-only mode.', 'warning');
          }

          return Promise.reject(error);
        }

        const moduleValue = typeof data?.module === 'string' ? data.module.trim() : '';
        const planValue = typeof data?.plan === 'string' ? data.plan.trim() : '';
        const msg = data?.message ?? data?.error;

        const moduleLabel = moduleValue || 'this feature';
        const dedupeKey = planValue ? `${moduleLabel}:${planValue}` : moduleLabel;

        if (shouldShowUpgradeToast(dedupeKey)) {
          const finalMessage =
            typeof msg === 'string' && msg.trim()
              ? `Feature locked: ${moduleLabel}. ${msg.trim()}`
              : `Feature locked: ${moduleLabel}. Upgrade required.`;

          notifyGlobal(finalMessage, 'warning');
        }

        return Promise.reject(error);
      }

      const msg = data?.message ?? data?.error;

      if (typeof msg === 'string' && msg.trim()) {
        notifyGlobal(msg, 'warning');
      }
    }

    return Promise.reject(error);
  }
);

export interface TimesheetMutationResponse {
  data: Timesheet;
  validation?: TimesheetValidationResult;
  message?: string;
  warning?: any;
  permissions?: TimesheetPermissions;
}

export interface TimesheetManagerViewParams {
  date_from?: string;
  date_to?: string;
  status?: string;
  technician_ids?: number[];
}

export interface TimesheetWeekSummary {
  regular_hours: number;
  overtime_hours: number;
  overtime_rate: number;
  overtime_hours_2_0: number;
  workweek_start: string | null;
  policy_key?: 'US-CA' | 'US-NY' | 'US-FLSA' | 'NON-US' | string;
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
  
  getForCurrentUser: (params?: { technician_id?: number; user_id?: number }): Promise<Project[]> =>
    api.get('/api/user/projects', { params }).then(res => res.data),
  
  getForCurrentUserExpenses: (params?: { technician_id?: number; user_id?: number }): Promise<Project[]> =>
    api.get('/api/user/expense-projects', { params }).then(res => res.data),
  
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

  getSummary: (params: {
    date: string;
    project_id?: number;
    technician_id?: number;
  }): Promise<TimesheetWeekSummary> =>
    api.get('/api/timesheets/summary', { params }).then(res => res.data),
  
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
  legal_accepted: boolean;
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

export interface TaskLocationSuggestionWeights {
  same_project: number;
  cross_project: number;
  assignment_fallback: number;
}

export interface TaskLocationSuggestionLocationMeta {
  city?: string;
  country?: string;
  timezone?: string;
  full_address?: string;
  meta?: Record<string, unknown> | null;
  is_active?: boolean;
}

export interface TaskLocationSuggestion {
  location_id: number;
  name: string;
  confidence: number;
  sources?: string[];
  location?: TaskLocationSuggestionLocationMeta;
}

export interface TaskLocationSuggestionResponse {
  success: boolean;
  weights?: TaskLocationSuggestionWeights;
  data: {
    task_id: number;
    project_id: number | null;
    project_name?: string | null;
    suggestions: TaskLocationSuggestion[];
  };
}

export const tenantApi = {
  /**
   * Register a new tenant (company)
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

  /**
   * Update AI toggle for current tenant (tenant-scoped route)
   */
  updateAiToggle: (aiEnabled: boolean): Promise<any> =>
    api.put('/api/billing/ai-toggle', {
      ai_enabled: aiEnabled,
    }).then(res => res.data),

  /**
   * Update compliance settings for current tenant (tenant-scoped route).
   */
  updateComplianceSettings: (payload: { state: string | null }): Promise<any> =>
    api.put('/api/billing/compliance-settings', payload).then(res => res.data),
};

// Task-Location Management API
export const taskLocationsApi = {
  /**
   * Get locations for a specific task
   */
  get: (taskId: number) =>
    api.get(`/api/tasks/${taskId}/locations`),
  
  /**
   * Sync locations for a task (replaces all existing)
   */
  sync: (taskId: number, locationIds: number[]) =>
    api.post(`/api/tasks/${taskId}/locations`, { location_ids: locationIds }),
  
  /**
   * Remove a specific location from a task
   */
  detach: (taskId: number, locationId: number) =>
    api.delete(`/api/tasks/${taskId}/locations/${locationId}`)
};

export const aiSuggestionsApi = {
  suggestTaskLocations: (taskId: number, limit = 5): Promise<TaskLocationSuggestionResponse> =>
    api.post('/api/ai/suggest-task-locations', { task_id: taskId, limit }).then(res => res.data),
};

export default api;
