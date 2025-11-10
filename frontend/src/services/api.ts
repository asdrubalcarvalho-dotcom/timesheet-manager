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

const API_BASE_URL = 'http://localhost:8080/api';

const api = axios.create({
  baseURL: API_BASE_URL,
  headers: {
    'Content-Type': 'application/json',
    'Accept': 'application/json',
  },
});

// Add authentication interceptor
api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
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
    api.get('/technicians').then(res => res.data),
  
  getById: (id: number): Promise<ApiResponse<Technician>> =>
    api.get(`/technicians/${id}`).then(res => res.data),
  
  create: (data: Partial<Technician>): Promise<ApiResponse<Technician>> =>
    api.post('/technicians', data).then(res => res.data),
  
  update: (id: number, data: Partial<Technician>): Promise<ApiResponse<Technician>> =>
    api.put(`/technicians/${id}`, data).then(res => res.data),
  
  delete: (id: number): Promise<ApiResponse<void>> =>
    api.delete(`/technicians/${id}`).then(res => res.data),
};

// Projects API
export const projectsApi = {
  getAll: (): Promise<Project[]> =>
    api.get('/projects').then(res => res.data),
  
  getForCurrentUser: (): Promise<Project[]> =>
    api.get('/user/projects').then(res => res.data),
  
  getById: (id: number): Promise<Project> =>
    api.get(`/projects/${id}`).then(res => res.data),
  
  create: (data: Partial<Project>): Promise<Project> =>
    api.post('/projects', data).then(res => res.data),
  
  update: (id: number, data: Partial<Project>): Promise<Project> =>
    api.put(`/projects/${id}`, data).then(res => res.data),
  
  delete: (id: number): Promise<void> =>
    api.delete(`/projects/${id}`).then(res => res.data),
};

// Timesheets API
export const timesheetsApi = {
  getAll: (params?: { 
    technician_id?: number; 
    project_id?: number; 
    date_from?: string; 
    date_to?: string; 
  }): Promise<Timesheet[]> =>
    api.get('/timesheets', { params }).then(res => res.data),
  
  getById: (id: number): Promise<TimesheetMutationResponse> =>
    api.get(`/timesheets/${id}`).then(res => res.data),
  
  create: (data: TimesheetFormData): Promise<TimesheetMutationResponse> =>
    api.post('/timesheets', data).then(res => res.data),
  
  update: (id: number, data: Partial<TimesheetFormData>): Promise<TimesheetMutationResponse> =>
    api.put(`/timesheets/${id}`, data).then(res => res.data),
  
  delete: (id: number): Promise<void> =>
    api.delete(`/timesheets/${id}`).then(res => res.data),

  getValidation: (id: number): Promise<TimesheetValidationResult> =>
    api.get(`/timesheets/${id}/validation`).then(res => res.data),

  getManagerView: (params?: TimesheetManagerViewParams): Promise<TimesheetManagerResponse> =>
    api.get('/timesheets/manager-view', { params }).then(res => res.data),
  
  getPendingCounts: (): Promise<{ timesheets: number; expenses: number; total: number }> =>
    api.get('/timesheets/pending-counts').then(res => res.data),
  
  submit: (id: number): Promise<Timesheet> =>
    api.patch(`/timesheets/${id}/submit`).then(res => res.data),
  
  approve: (id: number): Promise<Timesheet> =>
    api.put(`/timesheets/${id}/approve`).then(res => res.data),
  
  reject: (id: number, reason: string): Promise<Timesheet> =>
    api.put(`/timesheets/${id}/reject`, { reason }).then(res => res.data),
};

// Expenses API
export const expensesApi = {
  getAll: (params?: { 
    technician_id?: number; 
    project_id?: number; 
    date_from?: string; 
    date_to?: string; 
  }): Promise<ApiResponse<Expense[]>> =>
    api.get('/expenses', { params }).then(res => res.data),
  
  getById: (id: number): Promise<ApiResponse<Expense>> =>
    api.get(`/expenses/${id}`).then(res => res.data),
  
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
    return api.post('/expenses', formData, {
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
    return api.put(`/expenses/${id}`, formData, {
      headers: { 'Content-Type': 'multipart/form-data' }
    }).then(res => res.data);
  },
  
  delete: (id: number): Promise<ApiResponse<void>> =>
    api.delete(`/expenses/${id}`).then(res => res.data),
  
  submit: (id: number): Promise<ApiResponse<Expense>> =>
    api.patch(`/expenses/${id}/submit`).then(res => res.data),
  
  approve: (id: number): Promise<ApiResponse<Expense>> =>
    api.patch(`/expenses/${id}/approve`).then(res => res.data),
  
  reject: (id: number): Promise<ApiResponse<Expense>> =>
    api.patch(`/expenses/${id}/reject`).then(res => res.data),
};

// Tasks API
export const tasksApi = {
  getAll: (): Promise<any[]> =>
    api.get('/tasks').then(res => res.data),
  
  getById: (id: number): Promise<any> =>
    api.get(`/tasks/${id}`).then(res => res.data),
  
  getByProject: (projectId: number): Promise<any[]> =>
    api.get(`/projects/${projectId}/tasks`).then(res => res.data),
};

// Locations API
export const locationsApi = {
  getAll: (): Promise<any[]> =>
    api.get('/locations').then(res => res.data),
  
  getActive: (): Promise<any[]> =>
    api.get('/locations/active').then(res => res.data),
  
  getById: (id: number): Promise<any> =>
    api.get(`/locations/${id}`).then(res => res.data),
};

// Dashboard API
export const dashboardApi = {
  getStatistics: (params?: {
    date_from?: string;
    date_to?: string;
  }): Promise<DashboardStatistics> =>
    api.get('/dashboard/statistics', { params }).then(res => res.data),
  
  getTopProjects: (params?: {
    limit?: number;
    metric?: 'hours' | 'expenses';
    date_from?: string;
    date_to?: string;
  }): Promise<TopProject[]> =>
    api.get('/dashboard/top-projects', { params }).then(res => res.data),
};

export default api;
