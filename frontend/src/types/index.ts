// API Response Types
export interface ApiResponse<T = any> {
  data: T;
  message?: string;
  success: boolean;
}

export interface PaginatedResponse<T> {
  data: T[];
  total: number;
  per_page: number;
  current_page: number;
  last_page: number;
}

// User and Authentication
export interface User {
  id: number;
  name: string;
  email: string;
  role: 'Technician' | 'Manager';
  created_at: string;
  updated_at: string;
}

export interface LoginRequest {
  email: string;
  password: string;
}

export interface LoginResponse {
  token: string;
  user: User;
}

// Projects
export interface Project {
  id: number;
  name: string;
  description?: string;
  start_date: string;
  end_date?: string;
  created_at: string;
  updated_at: string;
}

// Technicians
export interface Technician {
  id: number;
  name: string;
  email: string;
  role: 'Technician' | 'Manager';
  hourly_rate?: number;
  created_at: string;
  updated_at: string;
}

export interface Project {
  id: number;
  name: string;
  description?: string;
  start_date: string;
  end_date?: string;
  status: 'active' | 'completed' | 'on_hold';
  created_at: string;
  updated_at: string;
}

export interface Task {
  id: number;
  name: string;
  description?: string;
  project_id: number;
  estimated_hours?: number;
  is_active: boolean;
  created_at: string;
  updated_at: string;
  project?: Project;
}

export interface Location {
  id: number;
  name: string;
  address?: string;
  latitude?: number;
  longitude?: number;
  description?: string;
  is_active: boolean;
  created_at: string;
  updated_at: string;
}

export interface Timesheet {
  id: number;
  technician_id: number;
  project_id: number;
  task_id?: number;
  location_id?: number;
  date: string;
  start_time?: string;
  end_time?: string;
  hours_worked: number;
  description?: string;
  status: 'draft' | 'submitted' | 'approved' | 'rejected';
  created_at: string;
  updated_at: string;
  technician?: Technician;
  project?: Project;
  task?: Task;
  location?: Location;
}

export interface Expense {
  id: number;
  technician_id: number;
  project_id: number;
  date: string;
  amount: number;
  description?: string;
  attachment_path?: string;
  status: 'draft' | 'submitted' | 'approved' | 'rejected';
  created_at: string;
  updated_at: string;
  technician?: Technician;
  project?: Project;
}

export interface ApiResponse<T> {
  data: T;
  message?: string;
  success: boolean;
}

export interface PaginatedResponse<T> {
  data: T[];
  current_page: number;
  last_page: number;
  per_page: number;
  total: number;
}

export interface TimesheetFormData {
  technician_id?: number; // Optional - determined by backend from authenticated user
  project_id: number;
  task_id?: number;
  location_id?: number;
  date: string;
  start_time?: string;
  end_time?: string;
  hours_worked: number;
  description?: string;
}

export interface ExpenseFormData {
  technician_id: number;
  project_id: number;
  date: string;
  amount: number;
  description?: string;
  attachment?: File;
}