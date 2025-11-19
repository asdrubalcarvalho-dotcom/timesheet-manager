import api from './api';

export interface Project {
  id: number;
  name: string;
  description?: string;
  start_date: string;
  end_date?: string;
  status: string;
}

export const projectService = {
  // Get all projects
  getProjects: async (): Promise<Project[]> => {
    const response = await api.get('/api/projects');
    return response.data.data || response.data;
  },

  // Get single project
  getProject: async (id: number): Promise<Project> => {
    const response = await api.get(`/projects/${id}`);
    return response.data.data || response.data;
  },

  // Create project
  createProject: async (projectData: Partial<Project>): Promise<Project> => {
    const response = await api.post('/api/projects', projectData);
    return response.data.data || response.data;
  },

  // Update project
  updateProject: async (id: number, projectData: Partial<Project>): Promise<Project> => {
    const response = await api.put(`/projects/${id}`, projectData);
    return response.data.data || response.data;
  },

  // Delete project
  deleteProject: async (id: number): Promise<void> => {
    await api.delete(`/projects/${id}`);
  }
};