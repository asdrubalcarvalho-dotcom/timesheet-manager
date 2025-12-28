
// NOTE: Tasks CRUD is ADMIN-ONLY and must NOT affect Planning Gantt logic.
/*
Implement a MINIMAL CRUD for Project Tasks inside the Admin "Edit Project" page,
using a dedicated "Tasks" tab.

CONTEXT (do not violate):
- Planning Gantt is READ-ONLY and must NOT be modified.
- Tasks already exist and are rendered in Planning.
- This feature is ADMIN-only governance.

GOAL:
Allow admins to manage tasks (create, edit, delete) for a project
from a "Tasks" tab in the Edit Project page.

WHERE:
- Admin → Projects → Edit → Tab "Tasks"

---

## BACKEND (Laravel)

Reuse existing endpoints if present. If missing, add minimal ones:

- GET    /api/projects/{project}/tasks   → list tasks for project
- POST   /api/projects/{project}/tasks   → create task for project
- PUT    /api/tasks/{task}               → update task
- DELETE /api/tasks/{task}               → delete task

Rules:
- Tenant-scoped
- Validate project ownership
- Do NOT add new tables

Task fields (minimal):
- name (required)
- project_id (implicit on create)
- start_date (required date)
- end_date (optional, >= start_date)
- estimated_hours (optional number)
- progress (optional int 0..100, default 0)

---

## FRONTEND (React / MUI)

In the Edit Project page:
1) Add a new tab "Tasks".
2) On tab open:
   - Fetch tasks for the project.
3) Render a table:
   Name | Start | End | Progress | Actions(Edit/Delete)
4) Add "Add Task" button.
5) Use a modal dialog for Create/Edit:
   - Inputs: name, start_date, end_date, estimated_hours, progress
   - Client-side validation:
     - end_date >= start_date
     - progress between 0 and 100
6) On Save:
   - Call API
   - Show success toast
   - Refresh tasks list
7) On Delete:
   - Confirm
   - Call API
   - Refresh tasks list

---

## DO NOT:
- ❌ Modify PlanningGantt.tsx or PlanningGanttUsers.tsx
- ❌ Add drag & drop or Gantt editing
- ❌ Add task dependencies
- ❌ Add assignment task → user logic
- ❌ Mix this with project members logic

---

## FINAL CHECK
- Admin can create/edit/delete tasks for a project.
- Planning shows updated tasks after refresh.
- No regressions in Planning or Users views.
*/
import React, { useEffect, useMemo, useRef, useState } from 'react';
import {
  Box,
  CircularProgress,
  Typography,
  Alert,
  Checkbox,
  Button,
  ButtonGroup,
  Menu,
  MenuItem,
  IconButton,
  Tooltip,
  useMediaQuery,
} from '@mui/material';
import {
  ViewColumn as ViewColumnIcon,
  FitScreen as FitScreenIcon,
  Today as TodayIcon,
  ZoomOutMap as ZoomOutMapIcon,
  ChevronLeft as ChevronLeftIcon,
  ChevronRight as ChevronRightIcon,
  GridOn as GridOnIcon,
} from '@mui/icons-material';
import dayjs from 'dayjs';
import api from '../../services/api';
import { useTenantGuard } from '../../hooks/useTenantGuard';
import TaskLocationsDialog from '../Common/TaskLocationsDialog';
import { useAuth } from '../Auth/AuthContext';

interface Project {
  id: number;
  name: string;
  description?: string;
}

interface PlanningTask {
  id: number;
  name: string;
  start_date?: string | null;
  end_date?: string | null;
  progress?: number | null;
  dependencies?: string[] | string | null;
  estimated_hours?: number | null;
  is_active?: boolean;
  project_id?: number;
  project_name?: string;
  location_name?: string;
  locations?: Array<{ id: number; name: string }>;
  assigned_user_name?: string;
}

interface DhtmlxTask {
  id: string | number;
  text: string;
  start_date: any;
  end_date: any;
  progress: number;
  project_id?: number;
  project_name?: string;
  parent?: string | number;
  type?: string;
  open?: boolean;
  source_task_id?: number;
  source_task_name?: string;
}

type ViewMode = 'Day' | 'Week' | 'Month' | 'Year';
type ApiResponse<T> = T[] | { data: T[] };

declare global {
  interface Window {
    gantt?: any;
  }
}

const DHTMLX_STYLE_ID = 'dhtmlx-gantt-style';
const DHTMLX_SCRIPT_ID = 'dhtmlx-gantt-script';
let dhtmlxLoader: Promise<void> | null = null;


const DEFAULT_VISIBLE_COLUMNS = {
  text: true,
  project_name: false,
  start_date: true,
  end_date: false,
  progress: true,
} as const;
type VisibleColumnsState = typeof DEFAULT_VISIBLE_COLUMNS;

const projectColor = (id: number): string => {
  const hue = (id * 37) % 360;
  return `hsl(${hue}, 65%, 55%)`;
};

const normalizeApiResponse = <T,>(payload: ApiResponse<T>): T[] => {
  if (Array.isArray(payload)) {
    return payload as T[];
  }

  if (
    payload &&
    typeof payload === 'object' &&
    Array.isArray((payload as { data?: unknown }).data)
  ) {
    return (payload as { data: T[] }).data;
  }

  return [];
};

const ensureDhtmlxGanttAssets = (): Promise<void> => {
  if (window.gantt) return Promise.resolve();

  if (!dhtmlxLoader) {
    dhtmlxLoader = new Promise<void>((resolve, reject) => {
      if (!document.getElementById(DHTMLX_STYLE_ID)) {
        const link = document.createElement('link');
        link.id = DHTMLX_STYLE_ID;
        link.rel = 'stylesheet';
        link.href = 'https://cdn.dhtmlx.com/gantt/edge/dhtmlxgantt.css';
        document.head.appendChild(link);
      }

      if (document.getElementById(DHTMLX_SCRIPT_ID)) {
        if (window.gantt) {
          resolve();
        } else {
          document.getElementById(DHTMLX_SCRIPT_ID)!.addEventListener('load', () => resolve());
        }
        return;
      }

      const script = document.createElement('script');
      script.id = DHTMLX_SCRIPT_ID;
      script.src = 'https://cdn.dhtmlx.com/gantt/edge/dhtmlxgantt.js';
      script.async = false;
      script.onload = () => {
        if (window.gantt) {
          resolve();
        } else {
          reject(new Error('DHTMLX Gantt loaded but window.gantt is undefined'));
        }
      };
      script.onerror = () => reject(new Error('Failed to load DHTMLX Gantt'));
      document.head.appendChild(script);
    });
  }

  return dhtmlxLoader;
};

const buildColumns = (
  visibleColumns: VisibleColumnsState,
  planningView: 'projects' | 'locations' = 'projects',
  resolveProjectName?: (projectId: number) => string
) => {
  const cols: any[] = [];

  if (visibleColumns.text) {
    cols.push({
      name: 'text',
      label: planningView === 'locations' ? 'Location' : 'Task',
      width: 220,
      tree: true,
    });
  }

  // Project column always available for context
  if (visibleColumns.project_name) {
    cols.push({
      name: 'project_name',
      label: 'Project',
      width: 160,
      align: 'left',
      template: (task: any) => {
        const directId = typeof task?.project_id === 'number' ? task.project_id : undefined;

        // In Locations view, many rows don't carry project_name.
        // Infer from project_id or from the synthetic row id: "...-project-<id>...".
        let inferredId: number | undefined = directId;
        if (!inferredId && task?.id != null) {
          const idStr = String(task.id);
          const match = idStr.match(/-project-(\d+)/);
          if (match?.[1]) {
            const parsed = Number(match[1]);
            if (Number.isFinite(parsed)) inferredId = parsed;
          }
        }

        if (inferredId != null) {
          const resolved = resolveProjectName ? resolveProjectName(inferredId) : '';
          return resolved || task?.project_name || `Project ${inferredId}`;
        }

        return task?.project_name || '';
      },
    });
  }

  if (visibleColumns.start_date) {
    cols.push({ name: 'start_date', label: 'Start', width: 100, align: 'center' });
  }

  if (visibleColumns.end_date) {
    cols.push({ name: 'end_date', label: 'End', width: 100, align: 'center' });
  }

  if (visibleColumns.progress) {
    cols.push({
      name: 'progress',
      label: 'Progress',
      width: 80,
      align: 'center',
      template: (task: any) => {
        if (task.type === 'project') return '';
        return `${Math.round((task.progress || 0) * 100)}%`;
      },
    });
  }

  return cols;
};

const injectCustomStyles = () => {
  const styleId = 'dhtmlx-gantt-custom-styles';
  if (document.getElementById(styleId)) return;

  const style = document.createElement('style');
  style.id = styleId;
  style.innerHTML = `
    .gantt_task_line {
      border-radius: 6px;
      box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }
    .gantt_task_content {
      border-radius: 6px;
      font-size: 13px;
      padding: 4px 8px;
    }
    .gantt_grid {
      background: #fafafa;
    }
    .gantt_grid_head_cell {
      background: #f5f5f5;
      border-color: #e0e0e0;
      font-weight: 600;
      color: #424242;
    }
    .gantt_cell,
    .gantt_row,
    .gantt_row.odd {
      border-color: #e8e8e8;
    }
    .gantt_row.odd {
      background: #fafafa;
    }
    .gantt-project-group {
      background: linear-gradient(to right, #e3f2fd, #bbdefb) !important;
      border: 1px solid #90caf9 !important;
      font-weight: 600;
      color: #1565c0;
    }
    .gantt_row[data-type="project"] {
      background: #e3f2fd;
      font-weight: 600;
    }
    .task-selected .gantt_task_content {
      box-shadow: 0 0 0 3px #1976d2 inset, 0 2px 8px rgba(25, 118, 210, 0.3);
    }
    .gantt_layout_cell::-webkit-scrollbar {
      width: 10px;
      height: 10px;
    }
    .gantt_layout_cell::-webkit-scrollbar-track {
      background: #f1f1f1;
    }
    .gantt_layout_cell::-webkit-scrollbar-thumb {
      background: #c1c1c1;
      border-radius: 5px;
    }
    .gantt_layout_cell::-webkit-scrollbar-thumb:hover {
      background: #a1a1a1;
    }
  `;
  document.head.appendChild(style);
};

interface PlanningGanttProps {
  initialView?: 'projects' | 'locations';
}

const PlanningGantt: React.FC<PlanningGanttProps> = ({ initialView = 'projects' }) => {
  useTenantGuard();
  const { hasPermission } = useAuth();
  const canCreatePlanning = hasPermission('create-planning');

  const [projects, setProjects] = useState<Project[]>([]);
  const [selectedProjectIds, setSelectedProjectIds] = useState<number[]>([]);
  const [selectedLocationNames, setSelectedLocationNames] = useState<string[]>([]);
  const [loadingProjects, setLoadingProjects] = useState(false);

  const [tasks, setTasks] = useState<DhtmlxTask[]>([]);
  const [rawTasks, setRawTasks] = useState<PlanningTask[]>([]);
  const [loadingTasks, setLoadingTasks] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const [viewMode, setViewMode] = useState<ViewMode>('Month');
  const [ganttInitialized, setGanttInitialized] = useState(false);
  const [dhtmlxLoaded, setDhtmlxLoaded] = useState(false);

const [visibleColumns, setVisibleColumns] = useState<VisibleColumnsState>(DEFAULT_VISIBLE_COLUMNS);

 
  const [columnMenuAnchor, setColumnMenuAnchor] = useState<null | HTMLElement>(null);

  const [sidebarWidth, setSidebarWidth] = useState(300);
  const [isResizing, setIsResizing] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const isSmallScreen = useMediaQuery('(max-width:1200px)');
  const [gridVisible, setGridVisible] = useState(true);
  const [planningView] = useState<'projects' | 'locations'>(initialView); // Fixed view based on prop
  const [allExpanded, setAllExpanded] = useState(true);

  const [reloadLocationsNonce, setReloadLocationsNonce] = useState(0);
  const [selectedTaskForLocations, setSelectedTaskForLocations] = useState<{ id: number; name: string } | null>(null);
  const [taskLocationsDialogOpen, setTaskLocationsDialogOpen] = useState(false);

  const closeTaskLocationsDialog = () => {
    setTaskLocationsDialogOpen(false);
  };

  const handleManageLocations = () => {
    if (!selectedTaskForLocations) return;
    setTaskLocationsDialogOpen(true);
  };

  const ganttContainerRef = useRef<HTMLDivElement>(null);
  const sidebarRef = useRef<HTMLDivElement>(null);

  const resolveProjectName = useMemo(() => {
    const map = new Map<number, string>();
    projects.forEach((p) => map.set(p.id, p.name));
    return (projectId: number) => map.get(projectId) || '';
  }, [projects]);

  // Ensures projects load only once on mount — prevents unnecessary rerenders
  useEffect(() => {
    if (isSmallScreen) {
      setSidebarOpen(false);
    } else {
      setSidebarOpen(true);
    }
  }, [isSmallScreen]);

  useEffect(() => {
    if (planningView !== 'locations') {
      setSelectedTaskForLocations(null);
    }
  }, [planningView]);

  useEffect(() => {
    ensureDhtmlxGanttAssets()
      .then(() => {
        console.log('[PlanningGantt] DHTMLX assets loaded successfully');
        setDhtmlxLoaded(true);
      })
      .catch((err) => {
        console.error('Failed to load DHTMLX Gantt:', err);
        setError('Failed to load Gantt library. Please refresh the page.');
      });
  }, []);

  useEffect(() => {
    const loadProjects = async () => {
      setLoadingProjects(true);
      try {
        const response = await api.get<ApiResponse<Project>>('/api/projects');
        const list = normalizeApiResponse<Project>(response.data);
        setProjects(list);

        if (planningView === 'projects' && list.length > 0 && selectedProjectIds.length === 0) {
          setSelectedProjectIds([list[0].id]);
        }
      } catch (err) {
        console.error('Failed to load projects:', err);
        setError('Unable to load projects. Please try again.');
      } finally {
        setLoadingProjects(false);
      }
    };

    loadProjects();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []);

  const availableLocations = useMemo<string[]>(() => {
    if (planningView !== 'locations') return [];
    const set = new Set<string>();
    rawTasks.forEach((t) => {
      const fromArray = (t.locations || [])
        .map((l) => (l?.name ? String(l.name).trim() : ''))
        .filter((n) => !!n);
      if (fromArray.length > 0) {
        fromArray.forEach((n) => set.add(n));
        return;
      }
      const legacy = t.location_name?.trim();
      if (legacy) set.add(legacy);
    });
    return Array.from(set).sort((a, b) => a.localeCompare(b));
  }, [planningView, rawTasks]);

  useEffect(() => {
    if (planningView !== 'locations') return;
    if (selectedLocationNames.length > 0) return;
    if (availableLocations.length === 0) return;

    // Default to the first location selected (mirrors Projects behavior)
    setSelectedLocationNames([availableLocations[0]]);
  }, [planningView, availableLocations, selectedLocationNames.length]);

  const toggleLocation = (locationName: string) => {
    setSelectedLocationNames((prev) =>
      prev.includes(locationName)
        ? prev.filter((n) => n !== locationName)
        : [...prev, locationName]
    );
  };

  useEffect(() => {
    if (planningView !== 'projects') return;

    if (selectedProjectIds.length === 0) {
      setRawTasks([]);
      setTasks([]);
      return;
    }

    const fetchTasksForProjects = async () => {
      setLoadingTasks(true);
      setError(null);
      try {
        const response = await api.get<ApiResponse<PlanningTask>>('/api/tasks');
        const allTasks = normalizeApiResponse<PlanningTask>(response.data);

        const deriveLocationName = (task: PlanningTask): string | undefined => {
          if (task.location_name && task.location_name.trim()) return task.location_name.trim();
          const first = task.locations?.find((l) => l?.name && l.name.trim());
          return first?.name?.trim();
        };

        const filteredTasks = allTasks.filter((task) => selectedProjectIds.includes(task.project_id || 0));

        const enrichedTasks = filteredTasks.map((task) => {
          const project = projects.find((p) => p.id === task.project_id);
          return {
            ...task,
            project_name: project?.name || `Project ${task.project_id}`,
            location_name: deriveLocationName(task),
          };
        });

        setRawTasks(enrichedTasks);
      } catch (err) {
        console.error('Failed to load tasks:', err);
        setError('Failed to load tasks for selected projects.');
        setRawTasks([]);
        setTasks([]);
      } finally {
        setLoadingTasks(false);
      }
    };

    fetchTasksForProjects();
  }, [planningView, selectedProjectIds, projects]);

  useEffect(() => {
    if (planningView !== 'locations') return;

    const fetchTasksForLocations = async () => {
      setLoadingTasks(true);
      setError(null);
      try {
        const response = await api.get<ApiResponse<PlanningTask>>('/api/tasks');
        const allTasks = normalizeApiResponse<PlanningTask>(response.data);

        const deriveLocationName = (task: PlanningTask): string | undefined => {
          if (task.location_name && task.location_name.trim()) return task.location_name.trim();
          const first = task.locations?.find((l) => l?.name && l.name.trim());
          return first?.name?.trim();
        };

        const hasAnyLocation = (task: PlanningTask): boolean => {
          const names = (task.locations || [])
            .map((l) => (l?.name ? String(l.name).trim() : ''))
            .filter((n) => !!n);
          if (names.length > 0) return true;
          const legacy = deriveLocationName(task);
          return !!legacy && !!legacy.trim();
        };

        // Locations view must be independent from selectedProjectIds.
        // Keep the full locations[] list to support multi-location rendering.
        const enriched = allTasks
          .map((task) => {
            const project = projects.find((p) => p.id === task.project_id);
            return {
              ...task,
              project_name: project?.name || `Project ${task.project_id}`,
              // keep a legacy location_name for compatibility/tooling
              location_name: deriveLocationName(task),
            };
          })
          .filter((t) => hasAnyLocation(t));

        setRawTasks(enriched);
      } catch (err) {
        console.error('Failed to load tasks:', err);
        setError('Failed to load tasks for locations.');
        setRawTasks([]);
        setTasks([]);
      } finally {
        setLoadingTasks(false);
      }
    };

    fetchTasksForLocations();
  }, [planningView, projects, reloadLocationsNonce]);



  // Rebuild DHTMLX tasks when view/data change (single source of truth)
  useEffect(() => {
    if (!rawTasks.length) {
      setTasks([]);
      return;
    }

    const rebuilt = buildDhtmlxTasks(rawTasks, projects);
    setTasks(rebuilt);
  }, [planningView, rawTasks, projects, selectedLocationNames]);

  // Initialize Gantt once when assets + container are ready
  useEffect(() => {
    if (!dhtmlxLoaded || !window.gantt || !ganttContainerRef.current || ganttInitialized) {
      console.log('[PlanningGantt] Init check:', {
        dhtmlxLoaded,
        hasGantt: !!window.gantt,
        hasContainer: !!ganttContainerRef.current,
        ganttInitialized,
      });
      return;
    }

    const container = ganttContainerRef.current;
    const gantt = window.gantt;

    let initialized = false;
    const resizeObserver = new ResizeObserver((entries) => {
      for (const entry of entries) {
        const { width, height } = entry.contentRect;

        // First-time init when container has real size
        if (!initialized && width > 0 && height > 0) {
          initialized = true;
          console.log('[PlanningGantt] Container ready, initializing DHTMLX Gantt...', { width, height });

          // Set grid width based on gridVisible
          gantt.config.grid_width = gridVisible ? 300 : 0;
          gantt.config.autofit = true;
          gantt.config.min_column_width = 60;
          gantt.config.date_format = '%Y-%m-%d %H:%i';
          gantt.config.readonly = true;
          gantt.config.auto_scheduling = false;
          gantt.config.show_progress = true;
          gantt.config.row_height = 34;

          gantt.config.columns = buildColumns(DEFAULT_VISIBLE_COLUMNS, planningView, resolveProjectName);

          // Task classes and colors
          let selectedTaskId: string | number | null = null;

          gantt.templates.task_class = (_start: Date, _end: Date, task: any) => {
            const classes: string[] = [];
            if (task.type === 'project') {
              classes.push('gantt-project-group');
            }
            if (task.project_id) {
              classes.push(`gantt-task-project-${task.project_id}`);
            }
            if (selectedTaskId && task.id === selectedTaskId) {
              classes.push('task-selected');
            }
            return classes.join(' ');
          };

          gantt.attachEvent('onTaskClick', (id: string | number) => {
            selectedTaskId = id;
            if (planningView === 'locations') {
              try {
                const clicked = gantt.getTask(id);
                const sourceId = Number(clicked?.source_task_id);
                const sourceName = (clicked?.source_task_name || clicked?.text || '').toString().trim();
                if (Number.isFinite(sourceId) && sourceName) {
                  setSelectedTaskForLocations({ id: sourceId, name: sourceName });
                } else {
                  setSelectedTaskForLocations(null);
                }
              } catch {
                setSelectedTaskForLocations(null);
              }
            } else {
              setSelectedTaskForLocations(null);
            }
            gantt.refreshTask(id);
            return true;
          });

          gantt.attachEvent('onTaskLoading', (task: any) => {
            if (task.type !== 'project' && task.project_id) {
              const baseColor = projectColor(task.project_id);
              task.color = baseColor;
              task.progressColor = baseColor;
            }
            return true;
          });

          gantt.templates.tooltip_text = (start: Date, end: Date, task: any) => {
            if (task.type === 'project') {
              return `<b>${task.text}</b><br/><i>Project Group</i>`;
            }
            return `
              <b>${task.text}</b><br/>
              <b>Project:</b> ${task.project_name || 'N/A'}<br/>
              <b>Start:</b> ${gantt.templates.tooltip_date_format(start)}<br/>
              <b>End:</b> ${gantt.templates.tooltip_date_format(end)}<br/>
              <b>Progress:</b> ${Math.round((task.progress || 0) * 100)}%
            `;
          };

          injectCustomStyles();

          gantt.init(container);
          applyScaleInternal(gantt, viewMode);

          setGanttInitialized(true);
          console.log('[PlanningGantt] ✅ DHTMLX Gantt initialized successfully!');

          // Ensure initial sizing is correct
          try {
            gantt.render();
            gantt.setSizes();
          } catch (e) {
            // no-op
          }
        } else if (initialized) {
          // Keep gantt responsive after init (sidebar toggle, view switch, zoom, etc.)
          try {
            gantt.setSizes();
          } catch (e) {
            // no-op
          }
        }
      }
    });

    resizeObserver.observe(container);

    return () => {
      resizeObserver.disconnect();
      if (window.gantt) {
        console.log('[PlanningGantt] Cleaning up DHTMLX Gantt');
        window.gantt.clearAll();
      }
    };
  }, [dhtmlxLoaded, ganttInitialized, viewMode, gridVisible, planningView]);

  // Update columns and layout when visibility/grid/view changes
  useEffect(() => {
    if (!ganttInitialized || !window.gantt) return;
    const gantt = window.gantt;
    
    // Ensure grid width matches gridVisible state
    gantt.config.grid_width = gridVisible ? 300 : 0;
    gantt.config.columns = buildColumns(visibleColumns, planningView, resolveProjectName);
    gantt.render();
    
    // Fix column squeezing after view switches
    try {
      gantt.setSizes();
    } catch (e) {
      // no-op
    }
  }, [visibleColumns, gridVisible, ganttInitialized, resolveProjectName, planningView]);

  // Parse tasks into Gantt when tasks array changes
  useEffect(() => {
    if (!ganttInitialized || !window.gantt || loadingTasks) return;

    const gantt = window.gantt;
    gantt.clearAll();

    if (tasks.length === 0) {
      console.log('[PlanningGantt] No tasks to render');
      gantt.render();
      return;
    }

    console.log('[PlanningGantt] Parsing', tasks.length, 'tasks into DHTMLX');
    gantt.parse({ data: tasks });
    gantt.render();
  }, [tasks, ganttInitialized, loadingTasks]);


  // Update view mode / scales
  useEffect(() => {
    if (!ganttInitialized || !window.gantt) return;
    applyScaleInternal(window.gantt, viewMode);
    window.gantt.render();
  }, [viewMode, ganttInitialized]);

  const applyScaleInternal = (gantt: any, mode: ViewMode) => {
    switch (mode) {
      case 'Day':
        gantt.config.scales = [
          { unit: 'day', step: 1, format: '%D %d %M' },
        ];
        break;
      case 'Week':
        gantt.config.scales = [
          { unit: 'week', step: 1, format: 'Week #%W' },
          { unit: 'day', step: 1, format: '%d %M' },
        ];
        break;
      case 'Month':
        gantt.config.scales = [
          { unit: 'month', step: 1, format: '%F %Y' },
          { unit: 'week', step: 1, format: 'Week #%W' },
        ];
        break;
      case 'Year':
        gantt.config.scales = [
          { unit: 'year', step: 1, format: '%Y' },
          { unit: 'month', step: 1, format: '%M' },
        ];
        break;
      default:
        break;
    }
  };


  /** Formats a date string safely for DHTMLX */
  const safeFormat = (input: string | null | undefined): string => {
    if (!input || typeof input !== "string") {
      return dayjs().format("YYYY-MM-DD HH:mm");
    }
    const d = dayjs(input);
    return d.isValid() ? d.format("YYYY-MM-DD HH:mm") : dayjs().format("YYYY-MM-DD HH:mm");
  };

  const buildByProject = (apiTasks: PlanningTask[], allProjects: Project[]): DhtmlxTask[] => {
    const tasksByProject = apiTasks.reduce((acc, task) => {
      const projectId = task.project_id || 0;
      if (!acc[projectId]) acc[projectId] = [];
      acc[projectId].push(task);
      return acc;
    }, {} as Record<number, PlanningTask[]>);

    const dhtmlxTasks: DhtmlxTask[] = [];

    Object.entries(tasksByProject).forEach(([projectIdStr, projectTasks]) => {
      const projectId = parseInt(projectIdStr, 10);
      const project = allProjects.find((p) => p.id === projectId);
      const projectName = project?.name || `Project ${projectId}`;

      const projectStart = projectTasks.reduce<dayjs.Dayjs | null>((earliest, task) => {
        const taskStart = dayjs(task.start_date);
        return !earliest || (taskStart.isValid() && taskStart.isBefore(earliest)) ? taskStart : earliest;
      }, null);

      const projectEnd = projectTasks.reduce<dayjs.Dayjs | null>((latest, task) => {
        const taskEnd = dayjs(task.end_date);
        return !latest || (taskEnd.isValid() && taskEnd.isAfter(latest)) ? taskEnd : latest;
      }, null);

      const projectGroupId = `project-${projectId}`;
      dhtmlxTasks.push({
        id: projectGroupId,
        text: projectName,
        start_date: safeFormat(projectStart?.toString() ?? null),
        end_date: safeFormat(projectEnd?.toString() ?? null),
        progress: 0,
        type: 'project',
        open: true,
        project_id: projectId,
        project_name: projectName,
      });

      projectTasks.forEach((task) => {
        dhtmlxTasks.push({
          id: `${projectId}-${task.id}`,
          text: task.name,
          start_date: safeFormat(task.start_date),
          end_date: safeFormat(task.end_date ? task.end_date : dayjs(task.start_date).add(task.estimated_hours ?? 8, "hour").format("YYYY-MM-DD HH:mm")),
          progress: (task.progress ?? 0) / 100,
          project_id: projectId,
          project_name: projectName,
          parent: projectGroupId,
        });
      });
    });

    return dhtmlxTasks;
  };

  const buildByLocation = (
    apiTasks: PlanningTask[],
    allProjects: Project[],
    selectedNames: string[]
  ): DhtmlxTask[] => {
    const dhtmlxTasks: DhtmlxTask[] = [];

    const getLocationNamesForTask = (task: PlanningTask): string[] => {
      const fromArray = (task.locations || [])
        .map((l) => (l?.name ? String(l.name).trim() : ''))
        .filter((n) => !!n);

      if (fromArray.length > 0) {
        return Array.from(new Set(fromArray));
      }

      const legacy = task.location_name && task.location_name.trim() ? task.location_name.trim() : '';
      if (legacy) return [legacy];

      return ['Unknown Location'];
    };

    // Group tasks by ALL locations (multi-location supported)
    const tasksByLocation: Record<string, PlanningTask[]> = {};
    apiTasks.forEach((task) => {
      const names = getLocationNamesForTask(task);
      names.forEach((locationName) => {
        if (selectedNames.length > 0 && !selectedNames.includes(locationName)) return;
        if (!tasksByLocation[locationName]) {
          tasksByLocation[locationName] = [];
        }
        tasksByLocation[locationName].push(task);
      });
    });

    Object.entries(tasksByLocation).forEach(([locationName, locationTasks]) => {
      const locationId = `location-${locationName}`;

      const locationStart = locationTasks.reduce<dayjs.Dayjs | null>((earliest, t) => {
        const d = dayjs(t.start_date);
        return !earliest || (d.isValid() && d.isBefore(earliest)) ? d : earliest;
      }, null);

      const locationEnd = locationTasks.reduce<dayjs.Dayjs | null>((latest, t) => {
        const d = dayjs(t.end_date);
        return !latest || (d.isValid() && d.isAfter(latest)) ? d : latest;
      }, null);

      dhtmlxTasks.push({
        id: locationId,
        text: locationName,
        start_date: safeFormat(locationStart?.toString() ?? null),
        end_date: safeFormat(locationEnd?.toString() ?? null),
        progress: 0,
        type: 'project',
        open: true,
      });

      const tasksByProject: Record<number, PlanningTask[]> = {};
      locationTasks.forEach((task) => {
        const pid = task.project_id || 0;
        if (!tasksByProject[pid]) tasksByProject[pid] = [];
        tasksByProject[pid].push(task);
      });

      Object.entries(tasksByProject).forEach(([projectIdStr, projectTasks]) => {
        const projectId = Number(projectIdStr);
        const project = allProjects.find((p) => p.id === projectId);
        const projectName = project?.name || `Project ${projectId}`;
        const projectGroupId = `${locationId}-project-${projectId}`;

        const projectStart = projectTasks.reduce<dayjs.Dayjs | null>((earliest, t) => {
          const d = dayjs(t.start_date);
          return !earliest || (d.isValid() && d.isBefore(earliest)) ? d : earliest;
        }, null);

        const projectEnd = projectTasks.reduce<dayjs.Dayjs | null>((latest, t) => {
          const d = dayjs(t.end_date);
          return !latest || (d.isValid() && d.isAfter(latest)) ? d : latest;
        }, null);

        dhtmlxTasks.push({
          id: projectGroupId,
          text: projectName,
          start_date: safeFormat(projectStart?.toString() ?? null),
          end_date: safeFormat(projectEnd?.toString() ?? null),
          progress: 0,
          type: 'project',
          open: true,
          parent: locationId,
        });

        projectTasks.forEach((task) => {
          dhtmlxTasks.push({
            id: `${projectGroupId}-task-${task.id}`,
            text: task.name,
            start_date: safeFormat(task.start_date),
            end_date: safeFormat(
              task.end_date
                ? task.end_date
                : dayjs(task.start_date)
                    .add(task.estimated_hours ?? 8, 'hour')
                    .format('YYYY-MM-DD HH:mm')
            ),
            progress: (task.progress ?? 0) / 100,
            type: 'task',
            project_id: projectId,
            project_name: projectName,
            source_task_id: task.id,
            source_task_name: task.name,
            parent: projectGroupId,
          });
        });
      });
    });

    return dhtmlxTasks;
  };



  const buildDhtmlxTasks = (apiTasks: PlanningTask[], allProjects: Project[]): DhtmlxTask[] => {
    switch (planningView) {
      case 'projects':
        return buildByProject(apiTasks, allProjects);
      case 'locations':
        return buildByLocation(apiTasks, allProjects, selectedLocationNames);
      default:
        return buildByProject(apiTasks, allProjects);
    }
  };

  const toggleProject = (projectId: number) => {
    setSelectedProjectIds((prev) =>
      prev.includes(projectId) ? prev.filter((id) => id !== projectId) : [...prev, projectId]
    );
  };

  const handleViewModeChange = (mode: ViewMode) => {
    setViewMode(mode);
  };

  const handleColumnMenuOpen = (event: React.MouseEvent<HTMLElement>) => {
    setColumnMenuAnchor(event.currentTarget);
  };

  const handleColumnMenuClose = () => {
    setColumnMenuAnchor(null);
  };

  const toggleColumn = (column: keyof VisibleColumnsState) => {
    if (column === 'text') return; // Task always visible
    setVisibleColumns((prev) => ({ ...prev, [column]: !prev[column] }));
  };

  const toggleSidebarOpen = () => {
    setSidebarOpen((prev) => !prev);
  };

  const startResize = (e: React.MouseEvent) => {
    e.preventDefault();
    if (isSmallScreen) return; // no resize in overlay mode
    setIsResizing(true);
  };

  useEffect(() => {
    if (!isResizing) return;

    const handleMouseMove = (e: MouseEvent) => {
      const left = sidebarRef.current?.getBoundingClientRect().left || 0;
      const newWidth = e.clientX - left;
      if (newWidth >= 200 && newWidth <= 500) {
        setSidebarWidth(newWidth);
      }
    };

    const handleMouseUp = () => {
      setIsResizing(false);
    };

    document.addEventListener('mousemove', handleMouseMove);
    document.addEventListener('mouseup', handleMouseUp);

    return () => {
      document.removeEventListener('mousemove', handleMouseMove);
      document.removeEventListener('mouseup', handleMouseUp);
    };
  }, [isResizing, isSmallScreen]);

  const handleFitTasks = () => {
    if (!window.gantt || !ganttInitialized) return;
    const gantt = window.gantt;

    // getTaskByTime devolve OBJETOS de tarefa, não IDs
    const allTasks: any[] =
      gantt.getTaskByTime(new Date(0), new Date(8640000000000000)) || [];

    if (!allTasks.length) return;

    let min: Date | null = null;
    let max: Date | null = null;

    allTasks.forEach((task) => {
      const start: Date | undefined =
        task.start_date instanceof Date ? task.start_date : undefined;
      const end: Date | undefined =
        task.end_date instanceof Date ? task.end_date : undefined;
      if (!start || !end) return;

      if (!min || start < min) min = start;
      if (!max || end > max) max = end;
    });

    // Se por algum motivo não há datas válidas, não fazemos nada
    if (!min || !max) return;

    gantt.config.start_date = gantt.date.add(min, -1, 'day');
    gantt.config.end_date = gantt.date.add(max, 1, 'day');

    gantt.render();
    gantt.showDate(min);
  };
  
  const handleShowToday = () => {
    if (!window.gantt || !ganttInitialized) return;
    window.gantt.showDate(new Date());
  };

  const handleResetZoom = () => {
    setViewMode('Month');
  };

  const handleToggleExpandCollapse = () => {
    setAllExpanded((prev) => {
      const next = !prev;
      if (window.gantt && ganttInitialized) {
        try {
          window.gantt.eachTask((t: any) => {
            t.$open = next;
          });
          window.gantt.render();
        } catch (e) {
          // no-op
        }
      }
      return next;
    });
  };

  return (
    <Box sx={{ display: 'flex', gap: 0, height: 'calc(100vh - 100px)', p: 2, position: 'relative' }}>
      {/* Left Panel: Selector (Projects / Locations) */}
      <Box
        ref={sidebarRef}
        sx={{
          ...(isSmallScreen
            ? {
                position: 'absolute',
                left: 0,
                top: 0,
                bottom: 0,
                width: sidebarWidth,
                zIndex: 1200,
                transform: sidebarOpen ? 'translateX(0%)' : 'translateX(-100%)',
                transition: 'transform 0.3s ease-out',
                willChange: 'transform',
              }
            : {
                width: sidebarOpen ? sidebarWidth : 0,
                flexShrink: 0,
                transition: 'width 0.3s ease',
              }),
          bgcolor: 'background.paper',
          border: sidebarOpen ? '1px solid #e0e0e0' : 'none',
          borderRadius: 1,
          overflow: 'hidden',
          position: isSmallScreen ? 'absolute' : 'relative',
          display: 'flex',
          flexDirection: 'column',
        }}
      >
        <Box sx={{ overflowY: 'auto', flex: 1, p: 2 }}>
          {planningView === 'projects' && (
            <>
              <Typography variant="subtitle2" sx={{ mb: 1 }}>
                Select Projects
              </Typography>

              {loadingProjects && (
                <Box sx={{ display: 'flex', justifyContent: 'center', p: 2 }}>
                  <CircularProgress size={24} />
                </Box>
              )}

              {!loadingProjects && (
                <Box component="ul" sx={{ listStyle: 'none', p: 0, m: 0 }}>
                  {projects.map((project) => (
                    <Box
                      component="li"
                      key={project.id}
                      sx={{
                        display: 'flex',
                        alignItems: 'center',
                        py: 1,
                        borderBottom: '1px solid #f0f0f0',
                      }}
                    >
                      <Checkbox
                        checked={selectedProjectIds.includes(project.id)}
                        onChange={() => toggleProject(project.id)}
                        size="small"
                      />
                      <Box sx={{ ml: 1, flex: 1 }}>
                        <Typography variant="body2" fontWeight={500}>
                          {project.name}
                        </Typography>
                        {project.description && (
                          <Typography variant="caption" color="text.secondary">
                            {project.description}
                          </Typography>
                        )}
                      </Box>
                      <Box
                        sx={{
                          width: 12,
                          height: 12,
                          borderRadius: '50%',
                          bgcolor: projectColor(project.id),
                        }}
                      />
                    </Box>
                  ))}
                </Box>
              )}
            </>
          )}

          {planningView === 'locations' && (
            <>
              <Typography variant="subtitle2" sx={{ mb: 1 }}>
                Select Locations
              </Typography>

              {loadingTasks && (
                <Box sx={{ display: 'flex', justifyContent: 'center', p: 2 }}>
                  <CircularProgress size={24} />
                </Box>
              )}

              {!loadingTasks && availableLocations.length === 0 && (
                <Typography variant="body2" color="text.secondary">
                  No locations found.
                </Typography>
              )}

              {!loadingTasks && availableLocations.length > 0 && (
                <Box component="ul" sx={{ listStyle: 'none', p: 0, m: 0 }}>
                  {availableLocations.map((locationName) => (
                    <Box
                      component="li"
                      key={locationName}
                      sx={{
                        display: 'flex',
                        alignItems: 'center',
                        py: 1,
                        borderBottom: '1px solid #f0f0f0',
                      }}
                    >
                      <Checkbox
                        checked={selectedLocationNames.includes(locationName)}
                        onChange={() => toggleLocation(locationName)}
                        size="small"
                      />
                      <Box sx={{ ml: 1, flex: 1 }}>
                        <Typography variant="body2" fontWeight={500}>
                          {locationName}
                        </Typography>
                      </Box>
                    </Box>
                  ))}
                </Box>
              )}
            </>
          )}
        </Box>

        {/* Resizer (desktop only) */}
        {!isSmallScreen && (
          <Box
            onMouseDown={startResize}
            sx={{
              position: 'absolute',
              right: 0,
              top: 0,
              bottom: 0,
              width: 4,
              cursor: 'col-resize',
              bgcolor: isResizing ? '#1976d2' : 'transparent',
              '&:hover': {
                bgcolor: '#90caf9',
              },
              transition: 'background-color 0.2s',
            }}
          />
        )}
      </Box>

      {/* Sidebar toggle button */}
      <Tooltip title={sidebarOpen ? 'Close panel' : 'Open panel'}>
        <IconButton
          onClick={toggleSidebarOpen}
          size="small"
          sx={{
            position: 'absolute',
            left: isSmallScreen ? 8 : sidebarOpen ? sidebarWidth : 0,
            top: '50%',
            transform: 'translateY(-50%)',
            zIndex: isSmallScreen ? 1300 : 1100,
            bgcolor: 'primary.main',
            color: 'white',
            width: 28,
            height: 28,
            '&:hover': { bgcolor: 'primary.dark' },
            boxShadow: 2,
            transition: 'left 0.3s ease',
          }}
        >
          {sidebarOpen ? <ChevronLeftIcon fontSize="small" /> : <ChevronRightIcon fontSize="small" />}
        </IconButton>
      </Tooltip>

      {/* Right Panel: Gantt Chart */}
      <Box
        sx={{
          flex: 1,
          display: 'flex',
          flexDirection: 'column',
          gap: 2,
          ml: !isSmallScreen && sidebarOpen ? 2 : 0,
        }}
      >
        {error && (
          <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError(null)}>
            {error}
          </Alert>
        )}

        {/* Controls Row */}
        <Box sx={{ display: 'flex', gap: 2, alignItems: 'center', flexWrap: 'wrap' }}>
          <Tooltip title={gridVisible ? "Hide grid" : "Show grid"}>
            <IconButton
              size="small"
              onClick={() => {
                setGridVisible((v) => {
                  const next = !v;
                  if (window.gantt) {
                    window.gantt.config.grid_width = next ? 300 : 0;
                    window.gantt.render();
                    try {
                      window.gantt.setSizes();
                    } catch (e) {
                      // no-op
                    }
                  }
                  return next;
                });
              }}
              color="primary"
            >
              <GridOnIcon />
            </IconButton>
          </Tooltip>
          {/* Column Manager */}
          <Tooltip title="Show/Hide Columns">
            <Button
              variant="outlined"
              size="small"
              startIcon={<ViewColumnIcon />}
              onClick={handleColumnMenuOpen}
            >
              Columns
            </Button>
          </Tooltip>

          {planningView === 'locations' && canCreatePlanning && (
            <Button
              variant="contained"
              color="primary"
              size="small"
              onClick={handleManageLocations}
              disabled={!selectedTaskForLocations}
            >
              Manage Task Locations
            </Button>
          )}

          <Menu
            anchorEl={columnMenuAnchor}
            open={Boolean(columnMenuAnchor)}
            onClose={handleColumnMenuClose}
          >
            <MenuItem onClick={() => toggleColumn('text')} disabled>
              <Checkbox checked={visibleColumns.text} disabled size="small" />
              {planningView === 'locations' ? 'Location' : 'Task'} (always visible)
            </MenuItem>
            <MenuItem onClick={() => toggleColumn('project_name')}>
              <Checkbox checked={visibleColumns.project_name} size="small" />
              Project
            </MenuItem>
            <MenuItem onClick={() => toggleColumn('start_date')}>
              <Checkbox checked={visibleColumns.start_date} size="small" />
              Start
            </MenuItem>
            <MenuItem onClick={() => toggleColumn('end_date')}>
              <Checkbox checked={visibleColumns.end_date} size="small" />
              End
            </MenuItem>
            <MenuItem onClick={() => toggleColumn('progress')}>
              <Checkbox checked={visibleColumns.progress} size="small" />
              Progress
            </MenuItem>
          </Menu>

          {/* Zoom Controls */}
          <ButtonGroup variant="outlined" size="small">
            {(['Day', 'Week', 'Month', 'Year'] as ViewMode[]).map((mode) => (
              <Button
                key={mode}
                onClick={() => handleViewModeChange(mode)}
                variant={viewMode === mode ? 'contained' : 'outlined'}
              >
                {mode}
              </Button>
            ))}
          </ButtonGroup>

          {/* Expand / Collapse */}
          <Button variant="outlined" size="small" onClick={handleToggleExpandCollapse}>
            {allExpanded ? 'Collapse' : 'Expand'}
          </Button>

          {/* Timeline Utility Buttons */}
          <Tooltip title="Fit all tasks in view">
            <IconButton size="small" onClick={handleFitTasks} color="primary">
              <FitScreenIcon />
            </IconButton>
          </Tooltip>

          <Tooltip title="Go to today">
            <IconButton size="small" onClick={handleShowToday} color="primary">
              <TodayIcon />
            </IconButton>
          </Tooltip>

          <Tooltip title="Reset zoom to Month view">
            <IconButton size="small" onClick={handleResetZoom} color="primary">
              <ZoomOutMapIcon />
            </IconButton>
          </Tooltip>
        </Box>

        {/* Loading Indicator */}
        {loadingTasks && (
          <Box sx={{ display: 'flex', justifyContent: 'center', p: 4 }}>
            <CircularProgress />
          </Box>
        )}

        {/* DHTMLX Gantt Container */}
        <Box
          sx={{
            flex: 1,
            overflow: 'hidden',
            borderRadius: 2,
            bgcolor: '#fff',
            boxShadow: 1,
            border: '1px solid #e0e0e0',
          }}
        >
          <div
            ref={ganttContainerRef}
            id="gantt_here"
            style={{
              width: '100%',
              height: '100%',
            }}
          />
        </Box>

        <TaskLocationsDialog
          open={taskLocationsDialogOpen}
          task={selectedTaskForLocations}
          onClose={closeTaskLocationsDialog}
          onSaved={async () => {
            setReloadLocationsNonce((n) => n + 1);
          }}
        />
      </Box>
    </Box>
  );
};

export default PlanningGantt;
