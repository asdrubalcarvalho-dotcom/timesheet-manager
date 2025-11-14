import api from './api';

export interface TravelSegment {
  id: number;
  technician_id: number;
  project_id: number;
  travel_date?: string;
  start_at?: string | null;
  end_at?: string | null;
  duration_minutes?: number | null;
  origin_country: string;
  origin_location_id?: number | null;
  destination_country: string;
  destination_location_id?: number | null;
  direction: 'departure' | 'arrival' | 'project_to_project' | 'internal' | 'other';
  classification_reason?: string;
  status: 'planned' | 'completed' | 'cancelled';
  linked_timesheet_entry_id?: number;
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

export const travelsApi = {
  getAll: (filters?: TravelSegmentFilters) => {
    const params = new URLSearchParams();
    if (filters?.technician_id) params.append('technician_id', filters.technician_id.toString());
    if (filters?.project_id) params.append('project_id', filters.project_id.toString());
    if (filters?.status) params.append('status', filters.status);
    if (filters?.start_date) params.append('start_date', filters.start_date);
    if (filters?.end_date) params.append('end_date', filters.end_date);
    
    return api.get(`/travels?${params.toString()}`).then(res => res.data);
  },

  getById: (id: number) =>
    api.get(`/travels/${id}`).then(res => res.data),

  create: (data: Partial<TravelSegment>) =>
    api.post('/travels', data).then(res => res.data),

  update: (id: number, data: Partial<TravelSegment>) =>
    api.put(`/travels/${id}`, data).then(res => res.data),

  delete: (id: number) =>
    api.delete(`/travels/${id}`).then(res => res.data),

  getSuggestions: (technicianId: number, projectId: number) =>
    api.get(`/travels/suggestions?technician_id=${technicianId}&project_id=${projectId}`)
      .then(res => res.data.suggestion as TravelSuggestion),

  getTravelsByDate: (params: { technician_id?: number; month?: string; start_date?: string; end_date?: string; project_id?: number }) => {
    const queryParams = new URLSearchParams();
    if (params.technician_id) queryParams.append('technician_id', params.technician_id.toString());
    if (params.month) queryParams.append('month', params.month);
    if (params.start_date) queryParams.append('start_date', params.start_date);
    if (params.end_date) queryParams.append('end_date', params.end_date);
    if (params.project_id) queryParams.append('project_id', params.project_id.toString());
    
    return api.get(`/travels/by-date?${queryParams.toString()}`).then(res => res.data);
  },
};
