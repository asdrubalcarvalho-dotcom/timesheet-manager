// TODO: ProjectMembersModal
/*
You are enhancing PlanningGanttUsers.tsx to DISPLAY project roles
in a READ-ONLY way.

IMPORTANT CONTEXT (do not violate):
- Roles already exist in the backend via project_members.
- PlanningGanttUsers already fetches projects WITH members.
- This task is ONLY about visual context.
- Do NOT add any CRUD, dialogs, buttons, or API calls.

GOAL:
Show each user's role per project in the Users Planning Gantt,
as a small read-only badge.

DOMAIN RULES:
- Roles are PER PROJECT, not global per user.
- Valid roles include: member, manager (others may exist but treat generically).
- Planning must NOT allow editing roles.

WHERE TO DISPLAY:
- Display the role badge on the PROJECT node
  under each User in the Gantt hierarchy.

EXAMPLE:
User
 └── Project Name   [Manager]
      └── Task

DATA SOURCE:
- Use the existing project.members / memberRecords data
  already returned by the backend.
- Match the role by:
  project.id + user.id.

UI REQUIREMENTS:
- Use a small Chip / badge style (MUI Chip or simple span).
- Neutral, non-intrusive styling.
- Example mapping:
  - manager → primary / blue
  - member → default / grey
- If role is missing, show nothing.

DO NOT:
- ❌ Change buildUsersPlanning logic
- ❌ Change gantt initialization
- ❌ Add new API calls
- ❌ Modify Admin behavior

IMPLEMENTATION HINT:
- When building the PROJECT node text,
  append the role label (e.g. "ERP System [Manager]"),
  OR use a template to render badge-like text.

FINAL CHECK:
Ask yourself:
"Did I only add READ-ONLY role visibility without changing behavior?"

If yes, the implementation is correct.
*/

/*

You are implementing CRUD functionality for managing Users assigned to Projects.

CONTEXT (do not violate):
- The system already has a stable Planning Gantt with 3 views:
  - Projects → Tasks
  - Locations → Projects → Tasks
  - Users → Projects → Tasks
- This task is ONLY about managing Users ↔ Projects assignments.
- Tasks are NOT assigned to users at this stage.
- This is MACRO planning, not execution or timesheets.

DOMAIN TRUTH:
- Users are assigned to Projects via `project_members`.
- This relationship already exists in the database.
- PlanningGanttUsers.tsx already VISUALIZES these assignments correctly.
- We now want to MANAGE (CRUD) them.

GOAL:
Allow adding and removing Users from Projects
WITHOUT touching:
- task logic
- gantt builders
- gantt initialization
- locations logic
- projects planning logic

SCOPE:
- Frontend + API integration only for Users × Projects.
- No changes to database schema unless explicitly requested.

UX REQUIREMENTS:
- CRUD must be accessible from the Users Planning view.
- Preferred UX:
  - Select a Project under a User (or via sidebar)
  - Click "Manage Project Members" (button or context action)
  - Open a modal dialog
- Modal contents:
  - Project name (read-only)
  - List of ALL users
  - Checkbox per user (checked = member of project)
  - Optional role display (if available)
  - Save / Cancel actions

BEHAVIOR RULES:
- Checking a user adds them to the project.
- Unchecking a user removes them from the project.
- Changes are applied only on Save.
- On success:
  - Close modal
  - Refresh project members data
  - Gantt Users view updates automatically

API ASSUMPTIONS (Laravel-style, adjust if needed):
- GET /api/projects/{id}/members
- POST /api/projects/{id}/members
  Body: { user_id }
- DELETE /api/projects/{id}/members/{userId}

IMPORTANT:
- Do NOT refetch tasks.
- Do NOT refetch gantt assets.
- Only refresh projects/members data.
- Keep all logic isolated in PlanningGanttUsers.tsx or a small helper component.

CODE QUALITY RULES:
- Keep the modal logic self-contained.
- Do NOT duplicate gantt logic.
- Do NOT introduce global state.
- Use clear naming (ProjectMembersModal, etc).

FINAL CHECK BEFORE FINISHING:
Ask yourself:
"Did I only manage Users ↔ Projects, without affecting planning logic?"

If yes, the implementation is correct.
*/

/*
You are extending an existing React component: PlanningGanttUsers.tsx.

IMPORTANT:
- The Gantt logic is ALREADY CORRECT. Do NOT rewrite it.
- buildUsersPlanning() must NOT be modified.
- This task is ONLY about UI / UX parity with PlanningGantt.tsx (Projects view).

GOAL:
Make PlanningGanttUsers visually and behaviorally identical to PlanningGantt (Projects),
but adapted to USERS instead of PROJECTS.

WHAT TO REUSE FROM PlanningGantt.tsx:
- Top toolbar UI (period buttons: Day / Week / Month / Year)
- Columns toggle button
- Expand / Collapse button
- Sidebar toggle (show / hide)
- Grid show / hide logic
- Gantt scale switching logic

WHAT CHANGES FOR USERS VIEW:
- Sidebar shows "Select Users" instead of "Select Projects"
- Sidebar list is built from USERS derived from project_members
- Selected users filter which root nodes (users) are rendered
- If no user is selected → show ALL users

DO NOT:
- ❌ Change buildUsersPlanning
- ❌ Change data fetching logic
- ❌ Change project/task hierarchy
- ❌ Introduce task assignment logic
- ❌ Mix Projects and Users logic

STATE TO ADD (example):
- selectedUserIds: number[]
- sidebarOpen: boolean
- showGrid: boolean
- currentScale: 'day' | 'week' | 'month' | 'year'

FILTERING RULE:
- buildUsersPlanning receives ALL data
- UI filtering happens BEFORE passing data to gantt.parse
- Only filter by userId at root level

STRUCTURE:
- Sidebar (left):
  - Checkbox list of users
  - Toggle button (same as PlanningGantt)
- Main area:
  - Toolbar (same buttons as PlanningGantt)
  - Gantt container

FINAL CHECK:
- UI must look and behave the same as PlanningGantt (Projects)
- Only semantic difference is: USERS instead of PROJECTS
- No duplicated Gantt logic

If you follow these rules exactly, the implementation is correct.


*/

/**
 * PlanningGanttUsers - Visualizes CURRENT allocations by Users
 * 
 * Hierarchy: User → Projects (where user is member) → Tasks (all project tasks)
 * 
 * Data sources:
 * - project_members (existing allocations)
 * - projects with their tasks
 * 
 * This is MACRO planning visualization, not task assignment or execution tracking.
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
import ProjectMembersDialog from './ProjectMembersDialog';
import { useAuth } from '../Auth/AuthContext';
import { formatTenantDate } from '../../utils/tenantFormatting';

interface User {
  id: number;
  name: string;
  email: string;
}

interface Project {
  id: number;
  name: string;
  description?: string;
  members?: User[];
}

interface Task {
  id: number;
  name: string;
  start_date?: string | null;
  end_date?: string | null;
  progress?: number | null;
  estimated_hours?: number | null;
  project_id: number;
}

interface DhtmlxTask {
  id: string | number;
  text: string;
  start_date: any;
  end_date: any;
  progress: number;
  type?: string;
  parent?: string | number;
  open?: boolean;
}

declare global {
  interface Window {
    gantt?: any;
  }
}

type ViewMode = 'Day' | 'Week' | 'Month' | 'Year';

const DEFAULT_VISIBLE_COLUMNS = {
  text: true,
  start_date: true,
  end_date: false,
  progress: true,
} as const;

type VisibleColumnsState = typeof DEFAULT_VISIBLE_COLUMNS;

type RoleBadge = {
  label: string;
  className: string;
};

type UserProjectRoles = {
  project_role?: string | null;
  expense_role?: string | null;
  finance_role?: string | null;
};

const escapeHtml = (value: string): string =>
  value
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#039;');

const parseUserProjectFromGanttId = (id: string | number): { userId: number; projectId: number } | null => {
  if (typeof id !== 'string') return null;
  // Only match PROJECT rows (not task rows like user_{u}_project_{p}_task_{t})
  const match = id.match(/^user_(\d+)_project_(\d+)$/);
  if (!match) return null;
  const userId = Number(match[1]);
  const projectId = Number(match[2]);
  if (!Number.isFinite(userId) || !Number.isFinite(projectId)) return null;
  return { userId, projectId };
};

const normalizeRoleLabel = (value: any): string => {
  if (!value) return 'None';
  const s = String(value).trim();
  if (!s) return 'None';
  return s.charAt(0).toUpperCase() + s.slice(1);
};

const buildColumns = (
  visibleColumns: VisibleColumnsState,
  resolveRoleBadge?: (task: any) => RoleBadge | null,
  formatGridDate?: (value: unknown) => string
) => {
  const cols: any[] = [];

  if (visibleColumns.text) {
    cols.push({
      name: 'text',
      label: 'Users',
      width: 260,
      tree: true,
      template: (task: any) => {
        const baseText = escapeHtml(String(task?.text ?? ''));
        const badge = resolveRoleBadge?.(task);
        if (!badge) return baseText;

        const badgeHtml = `<span class="tp-role-badge ${escapeHtml(badge.className)}">${escapeHtml(badge.label)}</span>`;
        return `${baseText} ${badgeHtml}`;
      },
    });
  }


  if (visibleColumns.start_date) {
    cols.push({
      name: 'start_date',
      label: 'Start',
      width: 100,
      align: 'center',
      template: (task: any) => {
        if (!formatGridDate) return String(task?.start_date ?? '');
        return formatGridDate(task?.start_date);
      },
    });
  }

  if (visibleColumns.end_date) {
    cols.push({
      name: 'end_date',
      label: 'End',
      width: 100,
      align: 'center',
      template: (task: any) => {
        if (!formatGridDate) return String(task?.end_date ?? '');
        return formatGridDate(task?.end_date);
      },
    });
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

    .tp-role-badge {
      display: inline-block;
      margin-left: 8px;
      padding: 1px 8px;
      border-radius: 999px;
      font-size: 11px;
      line-height: 18px;
      vertical-align: middle;
      white-space: nowrap;
      border: 1px solid transparent;
    }
    .tp-role-primary {
      background: #e3f2fd;
      border-color: #90caf9;
      color: #1565c0;
    }
    .tp-role-default {
      background: #f5f5f5;
      border-color: #e0e0e0;
      color: #616161;
    }

  `;
  document.head.appendChild(style);
};

const DHTMLX_STYLE_ID = 'dhtmlx-gantt-style-users';
const DHTMLX_SCRIPT_ID = 'dhtmlx-gantt-script-users';
let dhtmlxLoader: Promise<void> | null = null;

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

const getProjectMembers = (project: any): User[] => {
  const pickArray = (v: any): any[] => {
    if (Array.isArray(v)) return v;
    if (v?.data && Array.isArray(v.data)) return v.data;
    return [];
  };

  // Common relation keys
  const candidates = [
    ...pickArray(project?.members),
    ...pickArray(project?.users),
    ...pickArray(project?.project_members),
    ...pickArray(project?.projectMembers),
    ...pickArray(project?.memberships),
  ];

  const extracted: User[] = [];

  candidates.forEach((item: any) => {
    // Sometimes it's already a user object
    if (item && typeof item.id === 'number' && (item.name || item.email)) {
      extracted.push({ id: item.id, name: item.name ?? `User ${item.id}`, email: item.email ?? '' });
      return;
    }

    // Sometimes it's a membership object containing user/technician
    const nested = item?.user || item?.member || item?.technician || item?.worker;
    if (nested && typeof nested.id === 'number') {
      extracted.push({
        id: nested.id,
        name: nested.name ?? `User ${nested.id}`,
        email: nested.email ?? '',
      });
    }
  });

  // De-duplicate by id
  const map = new Map<number, User>();
  extracted.forEach((u) => map.set(u.id, u));
  return Array.from(map.values());
};

/**
 * Build Gantt data structure:
 * User (root)
 *   └── Project (where user is member)
 *         └── Task (all tasks of that project)
 */
const buildUsersPlanning = (
  projects: Project[],
  tasks: Task[]
): DhtmlxTask[] => {
  const ganttData: DhtmlxTask[] = [];

  // Create a map of tasks by project_id for quick lookup
  const tasksByProject = tasks.reduce((acc, task) => {
    if (!acc[task.project_id]) {
      acc[task.project_id] = [];
    }
    acc[task.project_id].push(task);
    return acc;
  }, {} as Record<number, Task[]>);

  // Build map of users to their projects
  const userProjectsMap = new Map<number, Project[]>();
  const userMap = new Map<number, User>();

  projects.forEach((project) => {
    const members = getProjectMembers(project);
    members.forEach((user) => {
      userMap.set(user.id, user);
      if (!userProjectsMap.has(user.id)) {
        userProjectsMap.set(user.id, []);
      }
      userProjectsMap.get(user.id)!.push(project);
    });
  });

  // Build Gantt structure
  userProjectsMap.forEach((userProjects, userId) => {
    const user = userMap.get(userId);
    if (!user) return;

    // Calculate user date range from all their project tasks
    let userMinDate: Date | null = null;
    let userMaxDate: Date | null = null;

    userProjects.forEach(project => {
      const projectTasks = tasksByProject[project.id] || [];
      projectTasks.forEach(task => {
        if (task.start_date) {
          const startDate = new Date(task.start_date);
          if (!userMinDate || startDate < userMinDate) {
            userMinDate = startDate;
          }
        }
        if (task.end_date) {
          const endDate = new Date(task.end_date);
          if (!userMaxDate || endDate > userMaxDate) {
            userMaxDate = endDate;
          }
        }
      });
    });

    // Default dates if no tasks have dates
    const defaultStart = dayjs().toDate();
    const defaultEnd = dayjs().add(30, 'days').toDate();

    // Add User node
    const userId_str = `user_${userId}`;
    ganttData.push({
      id: userId_str,
      text: user.name,
      start_date: dayjs(userMinDate || defaultStart).format('YYYY-MM-DD'),
      end_date: dayjs(userMaxDate || defaultEnd).format('YYYY-MM-DD'),
      progress: 0,
      type: 'project',
      open: true,
    });

    // Add Projects under this User
    userProjects.forEach(project => {
      const projectTasks = tasksByProject[project.id] || [];
      
      // Calculate project date range from its tasks
      let projectMinDate: Date | null = null;
      let projectMaxDate: Date | null = null;

      projectTasks.forEach(task => {
        if (task.start_date) {
          const startDate = new Date(task.start_date);
          if (!projectMinDate || startDate < projectMinDate) {
            projectMinDate = startDate;
          }
        }
        if (task.end_date) {
          const endDate = new Date(task.end_date);
          if (!projectMaxDate || endDate > projectMaxDate) {
            projectMaxDate = endDate;
          }
        }
      });

      const projectId_str = `user_${userId}_project_${project.id}`;
      
      ganttData.push({
        id: projectId_str,
        text: project.name,
        start_date: dayjs(projectMinDate || defaultStart).format('YYYY-MM-DD'),
        end_date: dayjs(projectMaxDate || defaultEnd).format('YYYY-MM-DD'),
        progress: 0,
        type: 'project',
        parent: userId_str,
        open: true,
      });

      // Add Tasks under this Project
      projectTasks.forEach(task => {
        const taskStartDate = task.start_date 
          ? new Date(task.start_date) 
          : defaultStart;
        const taskEndDate = task.end_date 
          ? new Date(task.end_date) 
          : dayjs(taskStartDate).add(7, 'days').toDate();

        ganttData.push({
          id: `user_${userId}_project_${project.id}_task_${task.id}`,
          text: task.name,
          start_date: dayjs(taskStartDate).format('YYYY-MM-DD'),
          end_date: dayjs(taskEndDate).format('YYYY-MM-DD'),
          progress: (task.progress || 0) / 100,
          parent: projectId_str,
        });
      });
    });
  });

  return ganttData;
};

const PlanningGanttUsers: React.FC = () => {
  useTenantGuard();
  const { hasPermission, tenantContext } = useAuth();
  const canCreatePlanning = hasPermission('create-planning');

  const formatGridDate = useMemo(() => {
    const toYmd = (value: unknown): string | null => {
      if (value instanceof Date) {
        return dayjs(value).isValid() ? dayjs(value).format('YYYY-MM-DD') : null;
      }
      if (typeof value === 'string') {
        const s = value.trim();
        if (/^\d{4}-\d{2}-\d{2}/.test(s)) return s.slice(0, 10);
      }
      return null;
    };

    return (value: unknown): string => {
      const ymd = toYmd(value);
      return ymd ? formatTenantDate(ymd, tenantContext) : '';
    };
  }, [tenantContext]);

  const ganttContainerRef = useRef<HTMLDivElement>(null);
  const sidebarRef = useRef<HTMLDivElement>(null);

  const [ganttReady, setGanttReady] = useState(false);
  const [ganttInitialized, setGanttInitialized] = useState(false);

  const [viewMode, setViewMode] = useState<ViewMode>('Month');
  const [visibleColumns, setVisibleColumns] = useState<VisibleColumnsState>(DEFAULT_VISIBLE_COLUMNS);
  const [columnMenuAnchor, setColumnMenuAnchor] = useState<null | HTMLElement>(null);

  const [sidebarWidth, setSidebarWidth] = useState(300);
  const [isResizing, setIsResizing] = useState(false);
  const [sidebarOpen, setSidebarOpen] = useState(true);
  const isSmallScreen = useMediaQuery('(max-width:1200px)');
  const [gridVisible, setGridVisible] = useState(true);

  const [selectedUserIds, setSelectedUserIds] = useState<number[]>([]);
  const [allExpanded, setAllExpanded] = useState(true);
  const selectionInitializedRef = useRef(false);

  const [loadingProjects, setLoadingProjects] = useState(true);
  const [loadingTasks, setLoadingTasks] = useState(true);
  const [error, setError] = useState<string | null>(null);

  const [projects, setProjects] = useState<Project[]>([]);
  const [tasks, setTasks] = useState<Task[]>([]);

  const [selectedProjectIdForMembers, setSelectedProjectIdForMembers] = useState<number | null>(null);
  const [openMembersDialog, setOpenMembersDialog] = useState(false);

  // Read-only role mapping: userId+projectId -> {project_role, expense_role, finance_role}
  const rolesByUserProject = useMemo(() => {
    const map = new Map<string, UserProjectRoles>();

    const ensureKey = (userId: any, projectId: any) => {
      const uid = Number(userId);
      const pid = Number(projectId);
      if (!Number.isFinite(uid) || !Number.isFinite(pid)) return;

      const key = `${uid}:${pid}`;
      if (!map.has(key)) {
        map.set(key, {});
      }
    };

    const mergeRoles = (userId: any, projectId: any, roles: Partial<UserProjectRoles>) => {
      const uid = Number(userId);
      const pid = Number(projectId);
      if (!Number.isFinite(uid) || !Number.isFinite(pid)) return;

      const key = `${uid}:${pid}`;
      const existing = map.get(key) ?? {};
      map.set(key, {
        ...existing,
        ...roles,
      });
    };

    (projects as any[]).forEach((project) => {
      const projectId = project?.id;

      // Ensure the map has an entry for each member even if roles aren't present in payload
      getProjectMembers(project).forEach((u) => ensureKey(u.id, projectId));

      // memberRecords-style data
      const memberRecords = Array.isArray(project?.memberRecords)
        ? project.memberRecords
        : Array.isArray(project?.member_records)
          ? project.member_records
          : null;
      if (memberRecords) {
        memberRecords.forEach((m: any) => {
          mergeRoles(m?.user_id ?? m?.user?.id, projectId, {
            project_role: m?.project_role ?? m?.timesheet_role ?? m?.role ?? null,
            expense_role: m?.expense_role ?? null,
            finance_role: m?.finance_role ?? null,
          });
        });
      }

      // members-style data (users with pivot)
      const members = Array.isArray(project?.members)
        ? project.members
        : Array.isArray(project?.users)
          ? project.users
          : null;
      if (members) {
        members.forEach((u: any) => {
          mergeRoles(u?.id ?? u?.user_id, projectId, {
            project_role: u?.project_role ?? u?.timesheet_role ?? u?.pivot?.project_role ?? null,
            expense_role: u?.expense_role ?? u?.pivot?.expense_role ?? null,
            finance_role: u?.finance_role ?? u?.pivot?.finance_role ?? null,
          });
        });
      }

      // membership objects with nested user
      const memberships = Array.isArray(project?.project_members)
        ? project.project_members
        : Array.isArray(project?.memberships)
          ? project.memberships
          : null;
      if (memberships) {
        memberships.forEach((m: any) => {
          const nested = m?.user || m?.member || m?.technician || m?.worker;
          mergeRoles(m?.user_id ?? nested?.id, projectId, {
            project_role: m?.project_role ?? m?.timesheet_role ?? m?.pivot?.project_role ?? null,
            expense_role: m?.expense_role ?? m?.pivot?.expense_role ?? null,
            finance_role: m?.finance_role ?? m?.pivot?.finance_role ?? null,
          });
        });
      }
    });

    return map;
  }, [projects]);

  const resolveRoleBadge = (task: any): RoleBadge | null => {
    const parsed = parseUserProjectFromGanttId(task?.id);
    if (!parsed) return null;

    const roles = rolesByUserProject.get(`${parsed.userId}:${parsed.projectId}`);
    const role = String(roles?.project_role ?? '').toLowerCase();
    const label = normalizeRoleLabel(roles?.project_role);

    return {
      label,
      className: role === 'manager' ? 'tp-role-primary' : 'tp-role-default',
    };
  };

  // Ensure sidebar defaults match PlanningGantt behavior
  useEffect(() => {
    if (isSmallScreen) {
      setSidebarOpen(false);
    } else {
      setSidebarOpen(true);
    }
  }, [isSmallScreen]);

  const users = useMemo<User[]>(() => {
    const map = new Map<number, User>();
    projects.forEach((project) => {
      getProjectMembers(project).forEach((u) => {
        map.set(u.id, u);
      });
    });
    return Array.from(map.values()).sort((a, b) => a.name.localeCompare(b.name));
  }, [projects]);

  // UX parity: default to first user selected (like Projects view)
  useEffect(() => {
    if (selectionInitializedRef.current) return;
    if (users.length === 0) return;

    setSelectedUserIds([users[0].id]);
    selectionInitializedRef.current = true;
  }, [users]);

  const toggleUser = (userId: number) => {
    setSelectedUserIds((prev) =>
      prev.includes(userId) ? prev.filter((id) => id !== userId) : [...prev, userId]
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
    if (column === 'text') return;
    setVisibleColumns((prev) => ({ ...prev, [column]: !prev[column] }));
  };

  const toggleSidebarOpen = () => {
    setSidebarOpen((prev) => !prev);
  };

  const startResize = (e: React.MouseEvent) => {
    e.preventDefault();
    if (isSmallScreen) return;
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
  }, [isResizing]);

  // Load DHTMLX Gantt
  useEffect(() => {
    ensureDhtmlxGanttAssets()
      .then(() => setGanttReady(true))
      .catch((err) => {
        console.error('Failed to load DHTMLX Gantt:', err);
        setError('Failed to load Gantt chart library');
      });
  }, []);

  // Fetch Projects with Members
  useEffect(() => {
    const fetchProjects = async () => {
      try {
        setLoadingProjects(true);
        const response = await api.get('/api/projects', {
          params: { include_members: true }
        });
        const projectsData = Array.isArray(response.data)
          ? response.data
          : response.data?.data || [];
        setProjects(projectsData);
      } catch (err: any) {
        console.error('Failed to fetch projects:', err);
        setError(err.response?.data?.message || 'Failed to load projects');
      } finally {
        setLoadingProjects(false);
      }
    };

    fetchProjects();
  }, []);

  const selectedProjectForMembers = useMemo(() => {
    if (!selectedProjectIdForMembers) return null;
    const project = projects.find((p) => p.id === selectedProjectIdForMembers);
    if (!project) return null;
    return { id: project.id, name: project.name };
  }, [projects, selectedProjectIdForMembers]);

  const selectedProjectMemberIds = useMemo(() => {
    if (!selectedProjectForMembers) return [];
    const project = projects.find((p) => p.id === selectedProjectForMembers.id);
    if (!project) return [];
    return getProjectMembers(project).map((u) => u.id);
  }, [projects, selectedProjectForMembers]);

  const refreshProjectsWithMembers = async () => {
    const response = await api.get('/api/projects', {
      params: { include_members: true },
    });
    const projectsData = Array.isArray(response.data) ? response.data : response.data?.data || [];
    setProjects(projectsData);
  };

  // Fetch Tasks
  useEffect(() => {
    const fetchTasks = async () => {
      try {
        setLoadingTasks(true);
        const response = await api.get('/api/tasks');
        const tasksData = Array.isArray(response.data) 
          ? response.data 
          : response.data?.data || [];
        setTasks(tasksData);
      } catch (err: any) {
        console.error('Failed to fetch tasks:', err);
        setError(err.response?.data?.message || 'Failed to load tasks');
      } finally {
        setLoadingTasks(false);
      }
    };

    fetchTasks();
  }, []);

  const applyScaleInternal = (gantt: any, mode: ViewMode) => {
    switch (mode) {
      case 'Day':
        gantt.config.scales = [{ unit: 'day', step: 1, format: '%D %d %M' }];
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

  const handleFitTasks = () => {
    if (!window.gantt || !ganttInitialized) return;
    const gantt = window.gantt;

    const allTasks: any[] = gantt.getTaskByTime(new Date(0), new Date(8640000000000000)) || [];
    if (!allTasks.length) return;

    let min: Date | null = null;
    let max: Date | null = null;

    allTasks.forEach((task) => {
      const start: Date | undefined = task.start_date instanceof Date ? task.start_date : undefined;
      const end: Date | undefined = task.end_date instanceof Date ? task.end_date : undefined;
      if (!start || !end) return;
      if (!min || start < min) min = start;
      if (!max || end > max) max = end;
    });

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

  // Initialize Gantt once when assets + container are ready
  useEffect(() => {
    if (!ganttReady || !window.gantt || !ganttContainerRef.current || ganttInitialized) return;

    const container = ganttContainerRef.current;
    const gantt = window.gantt;

    let initialized = false;
    const resizeObserver = new ResizeObserver((entries) => {
      for (const entry of entries) {
        const { width, height } = entry.contentRect;

        if (!initialized && width > 0 && height > 0) {
          initialized = true;

          gantt.config.grid_width = gridVisible ? 300 : 0;
          gantt.config.autofit = true;
          gantt.config.min_column_width = 60;
          gantt.config.date_format = '%Y-%m-%d';
          gantt.config.readonly = true;
          gantt.config.auto_scheduling = false;
          gantt.config.show_progress = true;
          gantt.config.row_height = 34;
          gantt.config.columns = buildColumns(DEFAULT_VISIBLE_COLUMNS, resolveRoleBadge, formatGridDate);

          gantt.templates.tooltip_date_format = (date: Date) => {
            const ymd = dayjs(date).isValid() ? dayjs(date).format('YYYY-MM-DD') : '';
            return ymd ? formatTenantDate(ymd, tenantContext) : '';
          };

          let selectedTaskId: string | number | null = null;
          gantt.templates.task_class = (_start: Date, _end: Date, task: any) => {
            const classes: string[] = [];
            if (task.type === 'project') {
              classes.push('gantt-project-group');
            }
            if (selectedTaskId && task.id === selectedTaskId) {
              classes.push('task-selected');
            }
            return classes.join(' ');
          };

          gantt.attachEvent('onTaskClick', (id: string | number) => {
            selectedTaskId = id;
            gantt.refreshTask(id);

            // Keep track of the last clicked PROJECT row so the toolbar "Edit users" button works.
            const parsed = parseUserProjectFromGanttId(id);
            if (parsed) {
              setSelectedProjectIdForMembers(parsed.projectId);
            }
            return true;
          });

          gantt.templates.tooltip_text = (start: Date, end: Date, task: any) => {
            if (task.type === 'project') {
              return `<b>${task.text}</b><br/><i>Group</i>`;
            }
            return `
              <b>${task.text}</b><br/>
              <b>Start:</b> ${gantt.templates.tooltip_date_format(start)}<br/>
              <b>End:</b> ${gantt.templates.tooltip_date_format(end)}<br/>
              <b>Progress:</b> ${Math.round((task.progress || 0) * 100)}%
            `;
          };

          injectCustomStyles();
          gantt.init(container);
          applyScaleInternal(gantt, viewMode);

          setGanttInitialized(true);

          try {
            gantt.render();
            gantt.setSizes();
          } catch (e) {
            // no-op
          }
        } else if (initialized) {
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
        try {
          window.gantt.clearAll();
        } catch (e) {
          // no-op
        }
      }
    };
  }, [ganttReady, ganttInitialized, gridVisible, viewMode, formatGridDate, tenantContext]);

  // Update columns/grid when toggled
  useEffect(() => {
    if (!ganttInitialized || !window.gantt) return;
    const gantt = window.gantt;
    gantt.config.grid_width = gridVisible ? 300 : 0;
    gantt.config.columns = buildColumns(visibleColumns, resolveRoleBadge, formatGridDate);
    gantt.render();
    try {
      gantt.setSizes();
    } catch (e) {
      // no-op
    }
  }, [visibleColumns, gridVisible, ganttInitialized, rolesByUserProject, formatGridDate]);

  // Update view mode / scales
  useEffect(() => {
    if (!ganttInitialized || !window.gantt) return;
    applyScaleInternal(window.gantt, viewMode);
    window.gantt.render();
  }, [viewMode, ganttInitialized]);

  // Parse tasks into Gantt (with UI filtering) when data changes
  useEffect(() => {
    if (!ganttInitialized || !window.gantt) return;
    if (loadingProjects || loadingTasks) return;

    const gantt = window.gantt;
    try {
      gantt.clearAll();
    } catch (e) {
      // no-op
    }

    const allData = buildUsersPlanning(projects, tasks);
    let filteredData = allData;

    if (selectedUserIds.length > 0) {
      const allowedRoots = new Set<string>(selectedUserIds.map((id) => `user_${id}`));
      const byId = new Map<string | number, DhtmlxTask>();
      allData.forEach((t) => byId.set(t.id, t));

      const getRootId = (task: DhtmlxTask): string | number => {
        let current: DhtmlxTask | undefined = task;
        const visited = new Set<string | number>();
        while (current?.parent && byId.has(current.parent) && !visited.has(current.parent)) {
          visited.add(current.parent);
          current = byId.get(current.parent);
        }
        return current?.id ?? task.id;
      };

      filteredData = allData.filter((t) => {
        const rootId = getRootId(t);
        return typeof rootId === 'string' && allowedRoots.has(rootId);
      });
    }

    if (filteredData.length === 0) {
      gantt.render();
      return;
    }

    gantt.parse({ data: filteredData });
    gantt.render();
  }, [projects, tasks, loadingProjects, loadingTasks, selectedUserIds, ganttInitialized]);

  const loading = loadingProjects || loadingTasks;

  return (
    <Box sx={{ display: 'flex', gap: 0, height: 'calc(100vh - 100px)', p: 2, position: 'relative' }}>
      {/* Left Panel: User Selection */}
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
          <Typography variant="subtitle2" sx={{ mb: 1 }}>
            Select Users
          </Typography>

          {(loadingProjects || loadingTasks) && (
            <Box sx={{ display: 'flex', justifyContent: 'center', p: 2 }}>
              <CircularProgress size={24} />
            </Box>
          )}

          {!loading && (
            <Box component="ul" sx={{ listStyle: 'none', p: 0, m: 0 }}>
              {users.map((user) => (
                <Box
                  component="li"
                  key={user.id}
                  sx={{
                    display: 'flex',
                    alignItems: 'center',
                    py: 1,
                    borderBottom: '1px solid #f0f0f0',
                  }}
                >
                  <Checkbox
                    checked={selectedUserIds.includes(user.id)}
                    onChange={() => toggleUser(user.id)}
                    size="small"
                  />
                  <Box sx={{ ml: 1, flex: 1 }}>
                    <Typography variant="body2" fontWeight={500}>
                      {user.name}
                    </Typography>
                    {user.email && (
                      <Typography variant="caption" color="text.secondary">
                        {user.email}
                      </Typography>
                    )}
                  </Box>
                </Box>
              ))}
            </Box>
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
          <Tooltip title={gridVisible ? 'Hide grid' : 'Show grid'}>
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

          {canCreatePlanning && (
            <Button
              variant="contained"
              size="small"
              disabled={!selectedProjectForMembers}
              onClick={() => setOpenMembersDialog(true)}
            >
              Manage Project Members
            </Button>
          )}

          <Menu anchorEl={columnMenuAnchor} open={Boolean(columnMenuAnchor)} onClose={handleColumnMenuClose}>
            <MenuItem onClick={() => toggleColumn('text')} disabled>
              <Checkbox checked={visibleColumns.text} disabled size="small" />
              Users (always visible)
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
        {loading && (
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
            id="gantt_users_here"
            style={{
              width: '100%',
              height: '100%',
            }}
          />
        </Box>

      </Box>

      <ProjectMembersDialog
        open={openMembersDialog}
        project={selectedProjectForMembers}
        initialMemberIds={selectedProjectMemberIds}
        onClose={() => setOpenMembersDialog(false)}
        onSaved={async () => {
          try {
            await refreshProjectsWithMembers();
          } catch (e) {
            // Keep UX minimal; errors are already surfaced via existing error banner on fetch.
          }
        }}
      />
    </Box>
  );
};

export default PlanningGanttUsers;
