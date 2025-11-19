import React, { useEffect, useRef, useState } from 'react';
import {
  Box,
  CircularProgress,
  FormControl,
  InputLabel,
  MenuItem,
  Select,
  Typography,
  Alert
} from '@mui/material';
import type { SelectChangeEvent } from '@mui/material';
import dayjs from 'dayjs';
import api from '../../services/api';
import { useTenantGuard } from '../../hooks/useTenantGuard';

type Project = {
  id: number;
  name: string;
  description?: string;
};

type PlanningTask = {
  id: number;
  name: string;
  start_date?: string | null;
  end_date?: string | null;
  progress?: number | null;
  dependencies?: string[] | string | null;
  estimated_hours?: number | null;
  is_active?: boolean;
};

type ApiResponse<T> = T[] | { data: T[] };

declare global {
  interface Window {
    Gantt?: any;
  }
}

const FRAPPE_STYLE_ID = 'frappe-gantt-style';
const FRAPPE_SCRIPT_ID = 'frappe-gantt-script';
let frappeLoader: Promise<void> | null = null;

const normalizeApiResponse = <T,>(payload: ApiResponse<T>): T[] => {
  if (Array.isArray(payload)) return payload;
  if (payload && Array.isArray(payload.data)) return payload.data;
  return [];
};

const ensureFrappeAssets = (): Promise<void> => {
  if (window.Gantt) {
    return Promise.resolve();
  }

  if (!frappeLoader) {
    frappeLoader = new Promise<void>((resolve, reject) => {
      if (!document.getElementById(FRAPPE_STYLE_ID)) {
        const link = document.createElement('link');
        link.id = FRAPPE_STYLE_ID;
        link.rel = 'stylesheet';
        link.href = 'https://unpkg.com/frappe-gantt@0.6.1/dist/frappe-gantt.css';
        document.head.appendChild(link);
      }

      if (document.getElementById(FRAPPE_SCRIPT_ID)) {
        document.getElementById(FRAPPE_SCRIPT_ID)!.addEventListener('load', () => resolve());
        return;
      }

      const script = document.createElement('script');
      script.id = FRAPPE_SCRIPT_ID;
      script.src = 'https://unpkg.com/frappe-gantt@0.6.1/dist/frappe-gantt.min.js';
      script.async = true;
      script.onload = () => resolve();
      script.onerror = () => reject(new Error('Failed to load Gantt library'));
      document.body.appendChild(script);
    });
  }

  return frappeLoader;
};

const PlanningGantt: React.FC = () => {
  useTenantGuard(); // Ensure tenant_slug exists
  const [projects, setProjects] = useState<Project[]>([]);
  const [selectedProject, setSelectedProject] = useState<number | ''>('');
  const [loadingProjects, setLoadingProjects] = useState<boolean>(true);
  const [loadingTasks, setLoadingTasks] = useState<boolean>(false);
  const [error, setError] = useState<string | null>(null);
  const containerRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    const loadProjects = async () => {
      try {
        const response = await api.get<ApiResponse<Project>>('/api/planning/projects');
        const list = normalizeApiResponse(response.data);
        setProjects(list);
        if (list.length > 0) {
          setSelectedProject(list[0].id);
        }
      } catch (err) {
        console.error(err);
        setError('Unable to load projects. Please try again.');
      } finally {
        setLoadingProjects(false);
      }
    };

    loadProjects();
  }, []);

  useEffect(() => {
    const fetchTasks = async () => {
      if (!selectedProject) {
        return;
      }

      setLoadingTasks(true);
      setError(null);
      try {
        const response = await api.get<ApiResponse<PlanningTask>>('/api/planning/tasks', {
          params: { project_id: selectedProject },
        });
        const tasks = normalizeApiResponse(response.data);
        await renderGantt(tasks);
      } catch (err) {
        console.error(err);
        setError('Failed to load tasks for the selected project.');
        clearGantt();
      } finally {
        setLoadingTasks(false);
      }
    };

    fetchTasks();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, [selectedProject]);

  const clearGantt = () => {
    if (containerRef.current) {
      containerRef.current.innerHTML = '';
    }
  };

  const buildTaskList = (tasks: PlanningTask[]) => {
    if (!tasks.length) {
      return [];
    }

    return tasks.map((task) => {
      const start = task.start_date || dayjs().format('YYYY-MM-DD');
      const fallbackEnd = task.end_date
        ? task.end_date
        : dayjs(start).add(task.estimated_hours ?? 8, 'hour').format('YYYY-MM-DD');

      const dependencyArray = Array.isArray(task.dependencies)
        ? task.dependencies
        : (task.dependencies ?? '')
            .toString()
            .split(',')
            .map((dep) => dep.trim())
            .filter(Boolean);

      const fallbackId =
        task.id?.toString() ?? `task-${Math.random().toString(36).slice(2, 9)}`;

      return {
        id: fallbackId,
        name: task.name,
        start,
        end: fallbackEnd,
        progress: Math.min(Math.max(task.progress ?? 0, 0), 100),
        dependencies: dependencyArray.join(','),
        custom_class: task.is_active ? 'gantt-task-active' : 'gantt-task-inactive',
      };
    });
  };

  const renderGantt = async (tasks: PlanningTask[]) => {
    await ensureFrappeAssets();

    if (!containerRef.current) {
      return;
    }

    const normalizedTasks = buildTaskList(tasks);

    containerRef.current.innerHTML = '';

    if (!normalizedTasks.length) {
      containerRef.current.innerHTML = '<p style="padding:16px;">No tasks available for this project.</p>';
      return;
    }

    const target = document.createElement('div');
    containerRef.current.appendChild(target);

    new window.Gantt(target, normalizedTasks, {
      view_mode: 'Week',
      custom_popup_html: (task: any) => `
        <div class="gantt-popup">
          <h5>${task.name}</h5>
          <p><strong>Start:</strong> ${task._start.format('YYYY-MM-DD')}</p>
          <p><strong>End:</strong> ${task._end.format('YYYY-MM-DD')}</p>
          <p><strong>Progress:</strong> ${task.progress}%</p>
        </div>
      `,
    });
  };

  const handleProjectChange = (event: SelectChangeEvent<string | number>) => {
    const value = event.target.value;
    setSelectedProject(typeof value === 'string' ? Number(value) : value);
  };

  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', gap: 3 }}>
      <Typography variant="h4" fontWeight={600}>
        Project Planning Gantt
      </Typography>

      {error && <Alert severity="error">{error}</Alert>}

      <FormControl sx={{ maxWidth: 360 }}>
        <InputLabel id="project-select-label">Project</InputLabel>
        <Select
          labelId="project-select-label"
          label="Project"
          value={selectedProject === '' ? '' : selectedProject}
          onChange={handleProjectChange}
          disabled={loadingProjects}
        >
          {projects.map((project) => (
            <MenuItem key={project.id} value={project.id}>
              {project.name}
            </MenuItem>
          ))}
        </Select>
      </FormControl>

      <Box
        sx={{
          position: 'relative',
          minHeight: 320,
          borderRadius: 2,
          bgcolor: '#fff',
          boxShadow: 1,
          p: 2,
          overflow: 'auto',
          '& .gantt .bar': {
            stroke: 'none',
          },
          '& .gantt-task-inactive .bar': {
            fill: '#e2e8f0',
          },
          '& .gantt-task-active .bar': {
            fill: '#43a047',
          },
          '& .gantt-popup': {
            padding: '8px 12px',
            fontSize: '0.85rem',
          },
          '& .gantt .grid-header': {
            fill: '#f8fafc',
          }
        }}
      >
        {(loadingProjects || loadingTasks) && (
          <Box
            sx={{
              position: 'absolute',
              inset: 0,
              display: 'flex',
              alignItems: 'center',
              justifyContent: 'center',
              bgcolor: 'rgba(255,255,255,0.7)',
              zIndex: 1,
            }}
          >
            <CircularProgress />
          </Box>
        )}
        <div ref={containerRef} />
      </Box>
    </Box>
  );
};

export default PlanningGantt;
