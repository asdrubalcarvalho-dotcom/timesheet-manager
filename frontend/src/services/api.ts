import axios from 'axios';
import type { ApiResponse, Technician, Project, Timesheet, Expense, TimesheetFormData, ExpenseFormData } from '../types';

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
  
  getById: (id: number): Promise<Timesheet> =>
    api.get(`/timesheets/${id}`).then(res => res.data),
  
  create: (data: TimesheetFormData): Promise<Timesheet> =>
    api.post('/timesheets', data).then(res => res.data),
  
  update: (id: number, data: Partial<TimesheetFormData>): Promise<Timesheet> =>
    api.put(`/timesheets/${id}`, data).then(res => res.data),
  
  delete: (id: number): Promise<void> =>
    api.delete(`/timesheets/${id}`).then(res => res.data),
  
  submit: (id: number): Promise<Timesheet> =>
    api.patch(`/timesheets/${id}/submit`).then(res => res.data),
  
  approve: (id: number): Promise<Timesheet> =>
    api.patch(`/timesheets/${id}/approve`).then(res => res.data),
  
  reject: (id: number): Promise<Timesheet> =>
    api.patch(`/timesheets/${id}/reject`).then(res => res.data),
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

export default api;