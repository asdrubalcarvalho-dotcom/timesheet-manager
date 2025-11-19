import api from './api';
import type { TravelSegment, TravelSegmentFilters, TravelSuggestion } from '../types';

export type { TravelSegment, TravelSegmentFilters, TravelSuggestion };

export const travelsApi = {
  getAll: (filters?: TravelSegmentFilters) => {
    const params = new URLSearchParams();
    if (filters?.technician_id) params.append('technician_id', filters.technician_id.toString());
    if (filters?.project_id) params.append('project_id', filters.project_id.toString());
    if (filters?.status) params.append('status', filters.status);
    if (filters?.start_date) params.append('start_date', filters.start_date);
    if (filters?.end_date) params.append('end_date', filters.end_date);
    
    return api.get(`/api/travels?${params.toString()}`).then(res => res.data);
  },

  getById: (id: number) =>
    api.get(`/api/travels/${id}`).then(res => res.data),

  create: (data: Partial<TravelSegment>) =>
    api.post('/api/travels', data).then(res => res.data),

  update: (id: number, data: Partial<TravelSegment>) =>
    api.put(`/api/travels/${id}`, data).then(res => res.data),

  delete: (id: number) =>
    api.delete(`/api/travels/${id}`).then(res => res.data),

  getSuggestions: (technicianId: number, projectId: number) =>
    api.get(`/api/travels/suggestions?technician_id=${technicianId}&project_id=${projectId}`)
      .then(res => res.data.suggestion as TravelSuggestion),

  getTravelsByDate: (params: { technician_id?: number; month?: string; start_date?: string; end_date?: string; project_id?: number }) => {
    const queryParams = new URLSearchParams();
    if (params.technician_id) queryParams.append('technician_id', params.technician_id.toString());
    if (params.month) queryParams.append('month', params.month);
    if (params.start_date) queryParams.append('start_date', params.start_date);
    if (params.end_date) queryParams.append('end_date', params.end_date);
    if (params.project_id) queryParams.append('project_id', params.project_id.toString());
    
    return api.get(`/api/travels/by-date?${queryParams.toString()}`).then(res => res.data);
  },
};
