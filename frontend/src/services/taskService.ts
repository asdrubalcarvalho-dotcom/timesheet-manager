import api from './api';
import type { Task } from '../types';

export const taskService = {
  // Get all tasks
  getTasks: async (projectId?: number): Promise<Task[]> => {
    const url = projectId ? `/tasks?project_id=${projectId}` : '/tasks';
    const response = await api.get(url);
    return response.data.data;
  },

  // Get tasks for a specific project
  getTasksByProject: async (projectId: number): Promise<Task[]> => {
    const response = await api.get(`/projects/${projectId}/tasks`);
    return response.data.data;
  },

  // Get single task
  getTask: async (id: number): Promise<Task> => {
    const response = await api.get(`/tasks/${id}`);
    return response.data.data;
  },

  // Create task
  createTask: async (taskData: Partial<Task>): Promise<Task> => {
    const response = await api.post('/api/tasks', taskData);
    return response.data.data;
  },

  // Update task
  updateTask: async (id: number, taskData: Partial<Task>): Promise<Task> => {
    const response = await api.put(`/tasks/${id}`, taskData);
    return response.data.data;
  },

  // Delete task
  deleteTask: async (id: number): Promise<void> => {
    await api.delete(`/tasks/${id}`);
  }
};