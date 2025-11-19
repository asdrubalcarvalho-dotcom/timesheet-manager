import api from './api';
import type { Location } from '../types';

export const locationService = {
  // Get all locations
  getLocations: async (): Promise<Location[]> => {
    const response = await api.get('/api/locations');
    return response.data.data;
  },

  // Get active locations only
  getActiveLocations: async (): Promise<Location[]> => {
    const response = await api.get('/api/locations/active');
    return response.data.data;
  },

  // Get single location
  getLocation: async (id: number): Promise<Location> => {
    const response = await api.get(`/locations/${id}`);
    return response.data.data;
  },

  // Create location
  createLocation: async (locationData: Partial<Location>): Promise<Location> => {
    const response = await api.post('/api/locations', locationData);
    return response.data.data;
  },

  // Update location
  updateLocation: async (id: number, locationData: Partial<Location>): Promise<Location> => {
    const response = await api.put(`/locations/${id}`, locationData);
    return response.data.data;
  },

  // Delete location
  deleteLocation: async (id: number): Promise<void> => {
    await api.delete(`/locations/${id}`);
  }
};