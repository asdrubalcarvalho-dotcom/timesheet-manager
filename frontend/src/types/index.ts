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
  role: 'Technician' | 'Manager' | 'Admin' | 'Owner';
  roles?: string[];
  permissions?: string[];
  is_owner?: boolean;
  is_manager?: boolean;
  is_technician?: boolean;
  is_admin?: boolean;
  managed_projects?: number[];
  project_memberships?: Array<{
    project_id: number;
    project_role: 'member' | 'manager';
    expense_role: 'member' | 'manager';
    finance_role: 'none' | 'member' | 'manager';
  }>;
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
  user_id?: number;
  is_active?: boolean;
  worker_id?: string | null;
  worker_name?: string | null;
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
  manager_id?: number;
  user_project_role?: 'member' | 'manager' | 'none';
  user_expense_role?: 'member' | 'manager' | 'none';
  memberRecords?: ProjectMember[];
  member_records?: ProjectMember[]; // Laravel snake_case
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
  locations?: Location[];
}

export interface Location {
  id: number;
  name: string;
  country: string;
  city: string;
  address?: string;
  postal_code?: string;
  latitude?: number;
  longitude?: number;
  is_active: boolean;
  asset_id?: number | null;
  oem_id?: number | null;
  created_at: string;
  updated_at: string;
}

export interface ProjectMember {
  id: number;
  project_id: number;
  user_id: number;
  project_role: 'member' | 'manager' | 'none';
  expense_role: 'member' | 'manager' | 'none';
  finance_role: 'member' | 'manager' | 'none';
  created_at: string;
  updated_at: string;
  user?: {
    id: number;
    name: string;
    email: string;
  };
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
  status: 'submitted' | 'approved' | 'rejected' | 'closed' | 'draft';
  created_at: string;
  updated_at: string;
  technician?: Technician;
  project?: Project;
  task?: Task;
  location?: Location;
  ai_flagged?: boolean;
  ai_score?: number | null;
  ai_feedback?: string[] | null;
}

export type TimesheetOverlapRisk = 'ok' | 'warning' | 'block';

export interface TimesheetValidationSnapshot {
  timesheet_id?: number | null;
  technician_id: number;
  project_id: number;
  task_id: number;
  location_id: number;
  date: string;
  hours_worked: number;
  start_time?: string | null;
  end_time?: string | null;
  daily_total_hours: number;
  overlap_risk: TimesheetOverlapRisk;
  membership_ok: boolean;
  project_active: boolean;
  ai_flagged: boolean;
  ai_score?: number | null;
  ai_feedback?: string[] | null;
}

export interface TimesheetAIInsights {
  flagged: boolean;
  score?: number | null;
  feedback?: string[];
  source?: string;
}

export interface TimesheetValidationResult {
  status: 'ok' | 'warning' | 'block';
  warnings: string[];
  notes: Record<string, unknown>;
  snapshot: TimesheetValidationSnapshot;
  ai?: TimesheetAIInsights | null;
}

export interface TimesheetPermissions {
  can_view: boolean;
  can_edit: boolean;
  can_delete: boolean;
  can_approve: boolean;
  can_reject: boolean;
}

export interface Expense {
  id: number;
  technician_id: number;
  project_id: number;
  date: string;
  amount: number;
  description?: string;
  attachment_path?: string;
  status: 'draft' | 'submitted' | 'approved' | 'finance_review' | 'finance_approved' | 'paid' | 'rejected' | 'closed';
  expense_type: 'reimbursement' | 'mileage' | 'company_card';
  category?: 'fuel' | 'meals' | 'materials' | 'accommodation' | 'other';
  distance_km?: number;
  rate_per_km?: number;
  vehicle_type?: 'car' | 'motorcycle' | 'bicycle';
  rejection_reason?: string;
  payment_reference?: string;
  created_at: string;
  updated_at: string;
  technician?: Technician;
  project?: Project;
}

export interface TimesheetManagerRow {
  id: number;
  date: string;
  week?: number | null;
  start_time?: string | null;
  end_time?: string | null;
  hours_worked: number;
  total_hours?: number;
  status: Timesheet['status'];
  description?: string;
  project_name?: string;
  task_name?: string;
  technician?: Pick<Technician, 'id' | 'name' | 'email'> | null;
  project?: Pick<Project, 'id' | 'name'> | null;
  task?: Pick<Task, 'id' | 'name'> | null;
  location?: Pick<Location, 'id' | 'name' | 'city' | 'country'> | null;
  ai_flagged?: boolean;
  ai_score?: number | null;
  ai_feedback?: string[] | null;
  validation?: TimesheetValidationResult;
  technician_project_role?: 'member' | 'manager' | 'none' | null;
  technician_expense_role?: 'member' | 'manager' | 'none' | null;
  travels?: {
    count: number;
    duration_minutes: number;
    duration_formatted: string;
    segment_ids: number[];
  } | null;
  consistency_flags?: string[];
}

export interface TimesheetManagerSummary {
  total: number;
  flagged_count: number;
  over_cap_count: number;
  overlap_count: number;
  pending_count: number;
  average_ai_score: number | null;
}

export interface TimesheetManagerResponse {
  data: TimesheetManagerRow[];
  summary: TimesheetManagerSummary;
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

// Dashboard Statistics Types
export interface DashboardSummary {
  total_hours: number;
  total_expenses: number;
  pending_timesheets: number;
  pending_expenses: number;
  approved_timesheets: number;
  approved_expenses: number;
}

export interface ProjectStats {
  project_name: string;
  total_hours?: number;
  total_amount?: number;
}

export interface StatusStats {
  status: string;
  count: number;
  total_hours?: number;
  total_amount?: number;
}

export interface DailyTrend {
  date: string;
  hours?: number;
  amount?: number;
}

export interface DashboardStatistics {
  summary: DashboardSummary;
  hours_by_project: ProjectStats[];
  expenses_by_project: ProjectStats[];
  hours_by_status: StatusStats[];
  expenses_by_status: StatusStats[];
  daily_hours: DailyTrend[];
  daily_expenses: DailyTrend[];
}

export interface TopProject {
  project_name: string;
  value: number;
  metric: 'hours' | 'expenses';
}

// Travel Segments
export interface TravelSegment {
  id: number;
  technician_id: number;
  project_id: number;
  travel_date: string;
  start_at: string | null;
  end_at: string | null;
  duration_minutes: number | null;
  origin_country: string;
  origin_city?: string | null;
  origin_location_id?: number | null;
  destination_country: string;
  destination_city?: string | null;
  destination_location_id?: number | null;
  direction: 'departure' | 'arrival' | 'project_to_project' | 'internal' | 'other';
  classification_reason?: string | null;
  status: 'planned' | 'completed' | 'cancelled';
  linked_timesheet_entry_id?: number | null;
  created_by?: number;
  updated_by?: number;
  created_at?: string;
  updated_at?: string;
  technician?: {
    id: number;
    name: string;
    email: string;
  };
  project?: {
    id: number;
    name: string;
  };
  origin_location?: {
    id: number;
    name: string;
  };
  destination_location?: {
    id: number;
    name: string;
  };
}

export interface TravelSegmentFilters {
  technician_id?: number;
  project_id?: number;
  status?: string;
  start_date?: string;
  end_date?: string;
}

export interface TravelSuggestion {
  origin_country: string;
  origin_location_id?: number;
  destination_country?: string;
  destination_location_id?: number;
}
