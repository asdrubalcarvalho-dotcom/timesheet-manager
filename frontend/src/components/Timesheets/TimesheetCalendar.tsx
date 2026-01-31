import React, { useState, useEffect, useMemo, useCallback, useRef } from 'react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import { timesheetsApi, projectsApi, tasksApi, locationsApi, techniciansApi } from '../../services/api';
import { travelsApi } from '../../services/travels';
import type { Project, Timesheet, Task, Location, Technician, ProjectMember } from '../../types';
import type { TravelSegment } from '../../services/travels';
import { useAuth } from '../Auth/AuthContext';
import { useNotification } from '../../contexts/NotificationContext';
import { useNavigate } from 'react-router-dom';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import { useFeatures } from '../../contexts/FeatureContext';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import {
  formatTenantDate,
  formatTenantDateTime,
  formatTenantNumber,
  formatTenantTime,
  getTenantDatePickerFormat,
  getTenantHourCycle,
  getTenantTimeFormat,
  getTenantUiLocale,
} from '../../utils/tenantFormatting';
import { getPolicyAlertModel } from '../../utils/policyAlert';
import { computeCaDailyOt2Candidates } from '../../utils/computeCaDailyOt2Candidates';
import { getVisibleTimesheets } from '../../utils/getVisibleTimesheets';
import { applyTimesheetUiFilters } from './timesheetUiFilters';
import { weekStartToFirstDay } from '../../utils/weekStartToFirstDay';
import { useRightPanel } from '../RightPanel/useRightPanel';
import { useRegisterRightPanelTab } from '../RightPanel/useRegisterRightPanelTab';
import { RightPanelTrigger } from '../RightPanel/RightPanelTrigger';
import { useRightPanelTabToggle } from '../RightPanel/useRightPanelTabToggle';
import { useTimesheetAlertsSummary } from './useTimesheetAlertsSummary';
import { useTranslation } from 'react-i18next';

import {
  Box,
  Typography,
  Button,
  Alert,
  AlertTitle,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  TextField,
  MenuItem,
  Card,
  CardContent,
  Chip,
  IconButton,
  Grid,
  Paper,
  Fade,
  Collapse,
  Badge,
  Tooltip,
  useTheme,
  useMediaQuery,
  Tabs,
  Tab,
  Avatar,
  ToggleButton,
  ToggleButtonGroup
} from '@mui/material';
import {
  Schedule as DurationIcon,
  AccessTime as TimeIcon,
  Work as ProjectIcon,
  Assignment as TaskIcon,
  LocationOn as LocationIcon,
  Close as CloseIcon,
  Save as SaveIcon,
  Edit as EditIcon,
  Add as AddIcon
} from '@mui/icons-material';
import SmartToyIcon from '@mui/icons-material/SmartToy';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { TimePicker } from '@mui/x-date-pickers/TimePicker';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs';
import dayjs, { Dayjs } from 'dayjs';
import localizedFormat from 'dayjs/plugin/localizedFormat';
import customParseFormat from 'dayjs/plugin/customParseFormat';
import type { DateClickArg } from '@fullcalendar/interaction';
import type {
  DateSelectArg,
  DayCellMountArg,
  DatesSetArg,
  EventClickArg,
  EventInput,
  EventMountArg,
} from '@fullcalendar/core';

import { useTimesheetAISuggestion } from '../../hooks/useTimesheetAISuggestion';
import AISuggestionCard from '../AI/AISuggestionCard';


// Configure dayjs plugins
dayjs.extend(localizedFormat);
dayjs.extend(customParseFormat);

// Custom date formatter to ensure English format
const formatDate = (date: Dayjs | null): string => {
  if (!date) return '';
  return date.format('YYYY-MM-DD');
};

const timeToString = (time: Dayjs | null): string => {
  if (!time) return '';
  return time.format('HH:mm');
};

// Convert decimal hours to HH:MM format
const decimalToHHMM = (decimal: number | string): string => {
  const hours = parseFloat(decimal.toString());
  const wholeHours = Math.floor(hours);
  const minutes = Math.round((hours - wholeHours) * 60);
  return `${wholeHours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}`;
};

const parseTimesheetDateTime = (dateOnly: string, timeValue?: string | null): Dayjs | null => {
  if (!timeValue) {
    return null;
  }

  const trimmedValue = String(timeValue).trim();
  if (!trimmedValue) {
    return null;
  }

  // Check if it contains date info (DATETIME format like "2025-11-07 09:00:00")
  const hasDateInfo = /\d{4}-\d{2}-\d{2}/.test(trimmedValue) || trimmedValue.includes('T');
  
  // Extract just the time part if it's a DATETIME
  let timePartOnly = trimmedValue;
  if (hasDateInfo && trimmedValue.includes(' ')) {
    timePartOnly = trimmedValue.split(' ')[1]; // Get part after space
  } else if (hasDateInfo && trimmedValue.includes('T')) {
    timePartOnly = trimmedValue.split('T')[1].split('.')[0]; // ISO format
  }

  // Extract hours, minutes, seconds from time part
  const match = timePartOnly.match(/(\d{1,2}):(\d{2})(?::(\d{2}))?/);
  if (!match) {
    return null;
  }

  const hours = parseInt(match[1], 10);
  const minutes = parseInt(match[2], 10);
  const seconds = match[3] ? parseInt(match[3], 10) : 0;
  if (Number.isNaN(hours) || Number.isNaN(minutes) || Number.isNaN(seconds)) {
    return null;
  }

  // Always combine with the provided dateOnly (ignore date in timeValue)
  const normalizedTime = `${hours.toString().padStart(2, '0')}:${minutes.toString().padStart(2, '0')}:${seconds
    .toString()
    .padStart(2, '0')}`;
  const combined = dayjs(`${dateOnly} ${normalizedTime}`, ['YYYY-MM-DD HH:mm:ss', 'YYYY-MM-DD HH:mm']);

  return combined.isValid() ? combined : null;
};

const STATUS_STYLE_MAP: Record<string, { background: string; dot: string }> = {
  submitted: { background: '#ffffff', dot: '#2196f3' },  // Blue like 'planned'
  approved: { background: '#ffffff', dot: '#4caf50' },   // Green like 'completed'
  rejected: { background: '#ffffff', dot: '#f44336' },   // Red
  closed: { background: '#ffffff', dot: '#757575' },     // Gray like 'cancelled'
  default: { background: '#ffffff', dot: '#9e9e9e' },    // Light gray (fallback for unknown status)
};

const DAILY_HOUR_CAP = 12;





const TimesheetCalendar: React.FC = () => {
  const { t } = useTranslation();
  const { user, tenant, isManager, isAdmin, loading: authLoading, tenantContext } = useAuth();
  const navigate = useNavigate();
  const { hasTravels } = useFeatures();
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('sm'));

  const datePickerFormat = useMemo(() => getTenantDatePickerFormat(tenantContext), [tenantContext]);
  const timePickerAmpm = useMemo(() => getTenantHourCycle(tenantContext) === 12, [tenantContext]);
  const timePickerFormat = useMemo(() => getTenantTimeFormat(tenantContext), [tenantContext]);

  const policyAlert = useMemo(() => getPolicyAlertModel(tenantContext), [tenantContext]);

  const sourceWeekStart = tenantContext?.week_start ?? tenant?.week_start;
  const weekFirstDay = useMemo(() => weekStartToFirstDay(sourceWeekStart), [sourceWeekStart]);

  useEffect(() => {
    console.debug('[TimesheetCalendar] week start debug', {
      region: tenantContext?.region,
      tenantWeekStart: tenant?.week_start,
      ctxWeekStart: tenantContext?.week_start,
      weekFirstDay,
    });
  }, [weekFirstDay, tenant?.week_start, tenantContext?.week_start, tenantContext?.region]);
  
  // Calendar ref to access API methods
  const calendarRef = useRef<FullCalendar>(null);
  
  // State variables
  const [timesheets, setTimesheets] = useState<Timesheet[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [technicians, setTechnicians] = useState<Technician[]>([]);
  const [travelsByDate, setTravelsByDate] = useState<Record<string, TravelSegment[]>>({});
  const [travelDetailsOpen, setTravelDetailsOpen] = useState(false);
  const [selectedTravelDate, setSelectedTravelDate] = useState<string>('');
  const [selectedTravels, setSelectedTravels] = useState<TravelSegment[]>([]);
  const [projectRoleMap, setProjectRoleMap] = useState<Record<number, { projectRole?: 'member' | 'manager' | 'none'; expenseRole?: 'member' | 'manager' | 'none'; }>>({});
  const [loading, setLoading] = useState(false);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [selectedEntry, setSelectedEntry] = useState<Timesheet | null>(null);
  const [currentCalendarViewType, setCurrentCalendarViewType] = useState<string>('dayGridMonth');
  const [currentWeekStartDate, setCurrentWeekStartDate] = useState<string | null>(null);

  const isTenantDrivenFirstDayView =
    currentCalendarViewType === 'timeGridWeek' ||
    currentCalendarViewType === 'listWeek' ||
    currentCalendarViewType === 'dayGridMonth' ||
    currentCalendarViewType === 'listMonth';

  useEffect(() => {
    const api = calendarRef.current?.getApi();
    if (!api) return;

    // FullCalendar sometimes doesn't reliably re-apply `views.*.firstDay` after auth/tenant arrives.
    // We explicitly set the global option only while in tenant-driven views.
    // For other views, reset to Sunday (keeps current behavior with locale="en").
    api.setOption('firstDay', isTenantDrivenFirstDayView ? weekFirstDay : 0);
  }, [isTenantDrivenFirstDayView, weekFirstDay]);

  // Week summary (read-only UI)
  const [weekSummary, setWeekSummary] = useState<{
    regular_hours: number;
    overtime_hours: number;
    overtime_rate: number;
    overtime_hours_2_0: number;
    workweek_start: string | null;
    policy_key?: 'US-CA' | 'US-NY' | 'US-FLSA' | 'NON-US' | string;
  } | null>(null);
  const [weekSummaryStatus, setWeekSummaryStatus] = useState<'idle' | 'loading' | 'loaded' | 'error'>('idle');
  const lastSummaryDateRef = useRef<string | null>(null);

  // Insights drawer (UI-only)
  const [insightsTab, setInsightsTab] = useState<'alerts' | 'weekly'>('alerts');
  const { isOpen: isRightPanelOpen, activeTabId: rightPanelActiveTabId } = useRightPanel();

  const handleSwitchToWeekView = useCallback(() => {
    const api = calendarRef.current?.getApi();
    if (!api) return;
    api.setOption('firstDay', weekFirstDay);
    api.changeView('timeGridWeek');
  }, [weekFirstDay]);
  
  // Confirmation dialog state
  type ConfirmDialogState = {
    open: boolean;
    title: string;
    message: string;
    recordDetails: Record<string, unknown>;
    action: () => void | Promise<void>;
  };

  const [confirmDialog, setConfirmDialog] = useState<ConfirmDialogState>({
    open: false,
    title: '',
    message: '',
    recordDetails: {},
    action: () => {},
  });
  
  // Global notification hook
  const { showSuccess, showError, showWarning } = useNotification();

  const { isReadOnly: isReadOnlyMode, warn: showReadOnlyWarning } = useReadOnlyGuard('timesheets');
  
  // Form state
  const [selectedDate, setSelectedDate] = useState<Dayjs | null>(null);
  const [selectedTechnicianId, setSelectedTechnicianId] = useState<number | ''>('');
  const [projectId, setProjectId] = useState<number | ''>('');
    // Auto-select single project or clear if none
    useEffect(() => {
      if (projects.length === 1) {
        setProjectId(projects[0].id);
      } else if (projects.length === 0) {
        setProjectId(0);
      }
    }, [projects]);
  const [taskId, setTaskId] = useState<number>(0);
  const [locationId, setLocationId] = useState<string>('');
  const [hoursWorked, setHoursWorked] = useState<number>(0);
  const [description, setDescription] = useState('');
  const [startTimeObj, setStartTimeObj] = useState<Dayjs | null>(dayjs().hour(9).minute(0).second(0));
  const [endTimeObj, setEndTimeObj] = useState<Dayjs | null>(dayjs().hour(10).minute(0).second(0));
  // Default to 'all' to show all timesheets (not just 'mine')
  const [timesheetScope, setTimesheetScope] = useState<'mine' | 'others' | 'all'>('all');
  const [validationFilter, setValidationFilter] = useState<'all' | 'ai_flagged' | 'overcap'>('all');
  const userIsManager = isManager();
  const userIsAdmin = isAdmin();

  const roleIsAssigned = (role?: 'member' | 'manager' | 'none') => role && role !== 'none';

  const formatRoleLabel = (role?: 'member' | 'manager' | 'none') => {
    if (!role || role === 'none') return 'Nenhum';
    return role.charAt(0).toUpperCase() + role.slice(1);
  };

  // AI Suggestion state - with localStorage persistence
  const [showAISuggestions, setShowAISuggestions] = useState<boolean>(() => {
    const saved = localStorage.getItem('timesheet_ai_suggestions_enabled');
    return saved !== null ? saved === 'true' : true; // Default: enabled
  });
  const [aiSuggestionExpanded, setAiSuggestionExpanded] = useState<boolean>(false);

  // AI Suggestion Hook
  const aiSuggestion = useTimesheetAISuggestion();

  // Calculate available technicians based on user role and visibility rules
  const availableTechnicians = useMemo(() => {
    if (!user) return [];

    // Get current user's technician record
    const currentUserTechnician = technicians.find(t => t.email === user.email);

    // If project is selected, filter by project membership
    if (Number(projectId) > 0) {
      const selectedProject = projects.find(p => p.id === Number(projectId));
      
      // Try both camelCase and snake_case (Laravel returns snake_case by default)
      const memberRecords: ProjectMember[] = selectedProject?.memberRecords || selectedProject?.member_records || [];
      
      // Get project members who have timesheet permissions (role != 'none')
      const projectMembersWithTimesheetAccess = memberRecords.filter((member) =>
        member.project_role && member.project_role !== 'none'
      );

      // Map to technician user IDs
      const allowedTechnicianUserIds = new Set(
        projectMembersWithTimesheetAccess.map((member) => member.user_id)
      );

      // Filter technicians by project membership
      const projectTechnicians = technicians.filter(t => 
        t.user_id && allowedTechnicianUserIds.has(t.user_id)
      );

      // Check current user's role in this project
      const currentUserProjectMember = projectMembersWithTimesheetAccess.find(
        (member) => member.user_id === user.id
      );

      // For Admins: show all technicians (bypass project membership check)
      if (userIsAdmin) {
        return technicians;
      }

      // For Project Managers: show only MEMBERS (not other managers) + themselves
      if (currentUserProjectMember?.project_role === 'manager') {
        // Get member user IDs (excluding managers)
        const memberUserIds = new Set(
          projectMembersWithTimesheetAccess
            .filter((member) => member.project_role === 'member')
            .map((member) => member.user_id)
        );

        // Filter technicians: only members + current user (manager can see their own)
        return projectTechnicians.filter(t => 
          t.user_id && (memberUserIds.has(t.user_id) || t.user_id === user.id)
        );
      }

      // For regular members: only show themselves
      // (User always has access since project appears in their dropdown)
      return currentUserTechnician ? [currentUserTechnician] : [];
    }

    // No project selected - original logic
    // For regular members: only show themselves
    if (!userIsManager && !userIsAdmin) {
      return currentUserTechnician ? [currentUserTechnician] : [];
    }

    // For Managers/Admins in "all" scope: show all visible technicians
    if (timesheetScope === 'all') {
      return technicians;
    }

    // For Managers/Admins in "others" scope: show all EXCEPT themselves
    if (timesheetScope === 'others') {
      return technicians.filter(t => t.email !== user.email);
    }

    // For Managers/Admins in "mine" scope: only show themselves
    return currentUserTechnician ? [currentUserTechnician] : [];
  }, [technicians, user, userIsManager, userIsAdmin, timesheetScope, projectId, projects]);

  const policyVisibleTimesheets = useMemo(() => {
    return getVisibleTimesheets(timesheets);
  }, [timesheets]);

  const validationSummary = useMemo(() => {
    const aiFlaggedIds = new Set<number>();
    const overCapIds = new Set<number>();
    const totals = new Map<string, { hours: number; ids: number[] }>();

    policyVisibleTimesheets.forEach((ts) => {
      if (ts.ai_flagged) {
        aiFlaggedIds.add(ts.id);
      }

      if (ts.technician_id && ts.date) {
        const key = `${ts.technician_id}-${dayjs(ts.date).format('YYYY-MM-DD')}`;
        const entry = totals.get(key) ?? { hours: 0, ids: [] };
        const decimal =
          typeof ts.hours_worked === 'string'
            ? parseFloat(ts.hours_worked)
            : ts.hours_worked;
        entry.hours += Number.isFinite(decimal) ? decimal : 0;
        entry.ids.push(ts.id);
        totals.set(key, entry);
      }
    });

    totals.forEach(({ hours, ids }) => {
      if (hours > DAILY_HOUR_CAP) {
        ids.forEach((id) => overCapIds.add(id));
      }
    });

    return {
      aiFlagged: aiFlaggedIds.size,
      aiFlaggedIds,
      overCap: overCapIds.size,
      overCapIds,
    };
  }, [policyVisibleTimesheets]);

  const toggleValidationFilter = (target: 'ai_flagged' | 'overcap') => {
    setValidationFilter((prev) => (prev === target ? 'all' : target));
  };


  type ArrayResponse<T> = { data: T[] };
  const isArrayResponse = <T,>(value: unknown): value is ArrayResponse<T> => {
    return Boolean(value) && typeof value === 'object' && Array.isArray((value as { data?: unknown }).data);
  };

  const loadTimesheets = useCallback(async () => {
    try {
      setLoading(true);
      const response: unknown = await timesheetsApi.getAll();
      console.log('Loaded timesheets:', response);

      let timesheetsData: Timesheet[] = [];
      if (Array.isArray(response)) {
        timesheetsData = response as Timesheet[];
      } else if (isArrayResponse<Timesheet>(response)) {
        timesheetsData = response.data;
      }

      setTimesheets(timesheetsData);
    } catch (error: unknown) {
      console.error('Error loading timesheets:', error);
      showError('Failed to load timesheets');
    } finally {
      setLoading(false);
    }
  }, [showError]);

  const loadTasks = useCallback(async () => {
    try {
      const data: unknown = await tasksApi.getAll();
      console.log('Loaded tasks:', data);
      let tasksArray: Task[] = [];
      if (Array.isArray(data)) {
        tasksArray = data as Task[];
      } else if (isArrayResponse<Task>(data)) {
        tasksArray = data.data;
      }
      setTasks(tasksArray);
    } catch (error: unknown) {
      console.error('Error loading tasks:', error);
      showError('Failed to load tasks');
    }
  }, [showError]);

  const loadLocations = useCallback(async () => {
    try {
      const data: unknown = await locationsApi.getAll();
      console.log('Loaded locations:', data);
      let locationsArray: Location[] = [];
      if (Array.isArray(data)) {
        locationsArray = data as Location[];
      } else if (isArrayResponse<Location>(data)) {
        locationsArray = data.data;
      }
      setLocations(locationsArray);
    } catch (error: unknown) {
      console.error('Error loading locations:', error);
      showError('Failed to load tasks');
    }
  }, [showError]);

  const loadTechnicians = useCallback(async () => {
    try {
      const response: unknown = await techniciansApi.getAll();
      console.log('Loaded technicians RAW response:', response);

      let techniciansData: Technician[] = [];
      if (Array.isArray(response)) {
        techniciansData = response as Technician[];
      } else if (isArrayResponse<Technician>(response)) {
        techniciansData = response.data;
      } else if (response && typeof response === 'object' && 'data' in response) {
        const nested = (response as { data?: unknown }).data;
        if (isArrayResponse<Technician>(nested)) {
          techniciansData = nested.data;
        } else if (Array.isArray(nested)) {
          techniciansData = nested as Technician[];
        }
      }

      console.log('Processed technicians:', techniciansData);
      setTechnicians(techniciansData);
    } catch (error: unknown) {
      console.error('Error loading technicians:', error);
      showError('Failed to load workers');
    }
  }, [showError]);

  const loadTravels = useCallback(
    async (month?: string, technicianId?: number) => {
      // Load travels for calendar month view integration
      if (!hasTravels) {
        setTravelsByDate({});
        return;
      }

      try {
        const params: Record<string, string | number> = {};

        params.month = month ?? dayjs().format('YYYY-MM');

        // Use provided technician filter when explicitly supplied
        if (technicianId) {
          params.technician_id = technicianId;
        }

        console.log('🛫 [TRAVELS] Loading with params:', params);
        const response = await travelsApi.getTravelsByDate(params);

        console.log('🛫 [TRAVELS] API Response:', response);

        if (response && response.travels_by_date) {
          const travelCount = Object.keys(response.travels_by_date).length;
          const totalSegments = Object.values(response.travels_by_date).flat().length;
          console.log(
            `🛫 [TRAVELS] Loaded ${totalSegments} segments across ${travelCount} dates:`,
            response.travels_by_date
          );
          setTravelsByDate(response.travels_by_date);
        } else {
          console.warn('🛫 [TRAVELS] No travels_by_date in response:', response);
          setTravelsByDate({});
        }
      } catch (error: unknown) {
        console.error('🛫 [TRAVELS] Error loading travels:', error);
        setTravelsByDate({});
        // Fail silently - travels are supplementary info to timesheets
      }
    },
    [hasTravels]
  );

  const loadProjects = useCallback(
    async (technicianId?: number | '') => {
      try {
        const technicianFilter = typeof technicianId === 'number' ? technicianId : undefined;
        const userProjectsResponse = await projectsApi
          .getForCurrentUser(technicianFilter ? { technician_id: technicianFilter } : undefined)
          .catch(() => ([] as Project[]));

        console.log('Loaded user projects:', userProjectsResponse);

        const userProjectsArray = Array.isArray(userProjectsResponse)
          ? userProjectsResponse
          : (userProjectsResponse as { data?: Project[] }).data || [];

        setProjects(userProjectsArray);

        const roleMap = userProjectsArray.reduce<
          Record<
            number,
            {
              projectRole?: 'member' | 'manager' | 'none';
              expenseRole?: 'member' | 'manager' | 'none';
            }
          >
        >((acc, project) => {
          acc[project.id] = {
            projectRole: project.user_project_role,
            expenseRole: project.user_expense_role,
          };
          return acc;
        }, {});
        setProjectRoleMap(roleMap);
      } catch (error: unknown) {
        console.error('Error loading projects:', error);
        showError('Failed to load projects');
      }
    },
    [showError]
  );

  // Load initial data once authentication state is resolved
  useEffect(() => {
    if (authLoading) return;
    if (!user) return;

    loadTasks();
    loadLocations();
    loadTechnicians();
    loadTravels(); // Load travel indicators for calendar
  }, [authLoading, user, loadTasks, loadLocations, loadTechnicians, loadTravels]);

  // Refetch projects and timesheets when technician selection changes (or on initial load)
  useEffect(() => {
    if (authLoading || !user) return;
    loadTimesheets();
  }, [authLoading, user, loadTimesheets]);

  useEffect(() => {
    if (authLoading || !user) return;
    loadProjects(selectedTechnicianId);
  }, [authLoading, user, selectedTechnicianId, loadProjects]);

  // Don't force scope - respect the default 'all' value from useState
  // Users can manually change scope using toggle buttons

  // Auto-select technician when creating new entry
  useEffect(() => {
    if (dialogOpen && !selectedEntry && availableTechnicians.length > 0 && selectedTechnicianId === '') {
      // Find the current user's technician record first (preferred)
      const currentUserTechnician = availableTechnicians.find(t => t.email === user?.email);
      
      if (currentUserTechnician) {
        // Auto-select the current user's technician if found
        console.log('Auto-selecting current user technician:', currentUserTechnician);
        setSelectedTechnicianId(currentUserTechnician.id);
      } else {
        // Fallback: Auto-select the first available technician (for Admins/Managers creating for others)
        console.log('Auto-selecting first available technician:', availableTechnicians[0]);
        setSelectedTechnicianId(availableTechnicians[0].id);
      }
    }
  }, [dialogOpen, selectedEntry, availableTechnicians, selectedTechnicianId, user]);

  // Reset location when task changes (only if current location is not in task's locations)
  useEffect(() => {
    if (taskId && taskId !== 0 && locationId) {
      const selectedTask = tasks.find(t => t.id === taskId);
      if (selectedTask?.locations && selectedTask.locations.length > 0) {
        const taskLocationIds = selectedTask.locations.map(loc => loc.id);
        const currentLocationId = typeof locationId === 'string' ? parseInt(locationId) : locationId;
        if (!taskLocationIds.includes(currentLocationId)) {
          setLocationId(''); // Clear location if not in task's locations
        }
      }
    }
  }, [taskId, locationId, tasks]);

  const handleDialogClose = useCallback(async () => {
    setDialogOpen(false);
    await loadTimesheets();
  }, [loadTimesheets]);

  const loadWeekSummary = useCallback(async (date: string) => {
    if (!date) return;
    if (authLoading) return;
    if (!user) return;

    // Dedupe repeated calls for the same week start while navigating
    // NOTE: On errors, we still dedupe to avoid spamming; user can change view/week to retry.
    if (lastSummaryDateRef.current === date) {
      return;
    }

    lastSummaryDateRef.current = date;
    setWeekSummaryStatus('loading');

    try {
      const data = await timesheetsApi.getSummary({ date });
      setWeekSummary(data);
      setWeekSummaryStatus('loaded');
    } catch (error) {
      console.warn('[TimesheetCalendar] Week summary unavailable:', error);
      setWeekSummary(null);
      setWeekSummaryStatus('error');
    }
  }, [authLoading, user]);

  // Handle view change - reload data when switching to Week view
  const handleViewChange = useCallback(
    (info: DatesSetArg) => {
      console.log('View changed to:', info.view.type, 'Date range:', info.startStr, 'to', info.endStr);

      // Track active view type to allow view-specific header formatting
      setCurrentCalendarViewType(info.view.type);

      if (info.view.type === 'timeGridWeek') {
        setCurrentWeekStartDate(info.startStr);
        void loadWeekSummary(info.startStr);
      }

      // Keep travel indicators in sync with the current visible range (month key)
      if (hasTravels) {
        const monthKey = dayjs(info.start).format('YYYY-MM');
        const technicianFilter = typeof selectedTechnicianId === 'number' ? selectedTechnicianId : undefined;
        void loadTravels(monthKey, technicianFilter);
      }
    },
    [hasTravels, loadWeekSummary, loadTravels, selectedTechnicianId]
  );

  const calculateHours = (startTime: Dayjs | null, endTime: Dayjs | null): number => {
    if (!startTime || !endTime) {
      console.log('Missing time parameters:', { startTime: timeToString(startTime), endTime: timeToString(endTime) });
      return 0;
    }
    
    try {
      const start = startTime;
      const end = endTime;
      
      if (end.isBefore(start) || end.isSame(start)) {
        console.log('Invalid time range - end before or equal to start');
        return 0;
      }
      
      const diffInMinutes = end.diff(start, 'minute');
      const hours = diffInMinutes / 60;
      
      // Round to nearest quarter hour and cap at 24 hours
      const result = Math.min(Math.round(hours * 4) / 4, 24);
      console.log('Calculated hours:', result);
      return result;
      
    } catch (error) {
      console.error('Error calculating hours:', error);
      return 0;
    }
  };

  // AI Suggestion Functions
  const handleApplyAISuggestion = (selectedHours: number | null, selectedDescription: string) => {
    console.log('Applying AI suggestion:', { selectedHours, selectedDescription });
    
    // Apply hours if selected
    if (selectedHours !== null && startTimeObj) {
      const newEndTime = startTimeObj.add(selectedHours, 'hour');
      setEndTimeObj(newEndTime);
      
      // Recalculate hours worked
      const calculatedHours = newEndTime.diff(startTimeObj, 'minute') / 60;
      setHoursWorked(parseFloat(calculatedHours.toFixed(2)));
    }
    
    // Apply description if selected
    if (selectedDescription) {
      setDescription(selectedDescription);
    }
    
    aiSuggestion.applySuggestion();
  };

  const handleDismissAISuggestion = () => {
    console.log('Dismissing AI suggestion');
    aiSuggestion.dismissSuggestion();
  };

  const handleAIFeedback = (accepted: boolean) => {
    console.log('AI feedback:', accepted ? 'accepted' : 'rejected');
    aiSuggestion.provideFeedback(accepted);
  };

  const toggleAISuggestions = () => {
    const newValue = !showAISuggestions;
    setShowAISuggestions(newValue);
    localStorage.setItem('timesheet_ai_suggestions_enabled', String(newValue));
    
    // Collapse when disabling
    if (!newValue) {
      setAiSuggestionExpanded(false);
    }
  };

  // Update hours when start or end time changes with debouncing
  useEffect(() => {
    // Only calculate if both times are set and valid
    if (startTimeObj && endTimeObj && startTimeObj.isValid() && endTimeObj.isValid()) {
      const timer = setTimeout(() => {
        const calculatedHours = calculateHours(startTimeObj, endTimeObj);
        console.log(`Calculating hours: ${timeToString(startTimeObj)} -> ${timeToString(endTimeObj)} = ${calculatedHours}h`);
        setHoursWorked(calculatedHours);
      }, 100); // Small delay to handle rapid changes

      return () => clearTimeout(timer);
    }
  }, [startTimeObj, endTimeObj]);

  // Generate AI suggestions when dialog opens for new entry
  useEffect(() => {
    if (dialogOpen && !selectedEntry && selectedDate && projectId && taskId && locationId) {
      const project = projects.find(p => p.id === projectId);
      const task = tasks.find(t => t.id === taskId);
      const location = locations.find(l => l.id === parseInt(locationId, 10));
      
      if (project && task && location) {
        const context = {
          project_name: project.name,
          task_name: task.name,
          location_name: location.name,
          date: formatDate(selectedDate),
          technician_name: user?.name || '',
          project_description: project.description || '',
          task_description: task.description || '',
        };
        
        console.log('Requesting AI suggestion with context:', context);
        aiSuggestion.getSuggestion(context);
      }
    }
  }, [dialogOpen, selectedEntry, selectedDate, projectId, taskId, locationId, projects, tasks, locations, user]);

  const handleDateSelect = (selectInfo: DateSelectArg) => {
    if (isReadOnlyMode) {
      showReadOnlyWarning();
      return;
    }
    console.log('Date select:', selectInfo.startStr, 'allDay:', selectInfo.allDay, 'isMobile:', isMobile);
    
    const startDateTime = dayjs(selectInfo.startStr);
    const endDateTime = dayjs(selectInfo.endStr);
    
    setSelectedDate(startDateTime);
    setSelectedEntry(null);
    resetForm();
    
    // Se não for all-day (clicou numa hora específica na vista week/day)
    if (!selectInfo.allDay) {
      console.log('Time-specific click detected:', startDateTime.format('HH:mm'), '->', endDateTime.format('HH:mm'));
      setStartTimeObj(startDateTime);
      setEndTimeObj(endDateTime);
      
      // Calcular horas automaticamente
      const hours = calculateHours(startDateTime, endDateTime);
      setHoursWorked(hours);
    }
    
    setDialogOpen(true);
  };

  const handleDateClick = (clickInfo: DateClickArg) => {
    if (isReadOnlyMode) {
      showReadOnlyWarning();
      return;
    }
    // Ignore click if it's on a travel indicator
    const clickTarget = clickInfo.jsEvent?.target;
    if (clickTarget instanceof HTMLElement && clickTarget.classList.contains('travel-indicator')) {
      console.log('🛫 Ignoring dateClick - clicked on travel indicator');
      return;
    }
    
    console.log('Date click:', clickInfo.dateStr, 'allDay:', clickInfo.allDay, 'isMobile:', isMobile);
    
    const clickDateTime = dayjs(clickInfo.dateStr);
    setSelectedDate(clickDateTime);
    setSelectedEntry(null);
    resetForm();
    
    // Se clicou numa hora específica (não all-day)
    if (!clickInfo.allDay && clickInfo.date) {
      const startTime = dayjs(clickInfo.date);
      const endTime = startTime.add(1, 'hour'); // Auto-incrementa 1 hora
      
      console.log('Time-specific click:', startTime.format('HH:mm'), '->', endTime.format('HH:mm'));
      setStartTimeObj(startTime);
      setEndTimeObj(endTime);
      
      // Calcular horas automaticamente
      const hours = calculateHours(startTime, endTime);
      setHoursWorked(hours);
    }
    
    setDialogOpen(true);
  };

  const handleTravelIndicatorClick = (dateStr: string, travels: TravelSegment[]) => {
    console.log('Travel indicator clicked:', dateStr, travels);
    setSelectedTravelDate(dateStr);
    setSelectedTravels(travels);
    setTravelDetailsOpen(true);
  };

  const handleEventClick = (clickInfo: EventClickArg) => {
    if (isReadOnlyMode) {
      showReadOnlyWarning();
      return;
    }
    const timesheetId = parseInt(clickInfo.event.id);
    console.log('Event clicked:', timesheetId);
    
    const timesheet = timesheets.find(t => t.id === timesheetId);
    if (timesheet) {
      // Check if user owns this entry
      const isOwner = isTimesheetOwnedByUser(timesheet);
      
      // Check if user manages this project
      const managesProject = Boolean(userIsManager && user?.managed_projects?.includes(timesheet.project_id));
      
      // Owner, Manager of project, or Admin can edit timesheets
      const canEdit = isOwner || userIsAdmin || managesProject;
      
      if (!canEdit) {
        console.log('User cannot edit this entry - not owner, not manager of project, and not admin');
        // Show warning notification
        showWarning('You can only edit your own timesheets or timesheets from projects you manage.');
        return;
      }
      
      console.log('Opening timesheet for editing:', timesheet);
      
      // Convert hours_worked to decimal format (e.g., 5.5 for 5h30m)
      const decimalHours = typeof timesheet.hours_worked === 'string' 
        ? parseFloat(timesheet.hours_worked)
        : timesheet.hours_worked;
      
      console.log('Duration conversion:', {
        original: timesheet.hours_worked,
        decimal: decimalHours,
        formatted: `${Math.floor(decimalHours)}h${Math.round((decimalHours % 1) * 60)}m`
      });
      
      setSelectedEntry(timesheet);
      setSelectedDate(dayjs(timesheet.date));
      setProjectId(timesheet.project_id);
      setTaskId(timesheet.task_id || 0);
      setLocationId(timesheet.location_id ? timesheet.location_id.toString() : '');
      
      // Debug: Log technician info
      console.log('Setting technician ID:', {
        technician_id: timesheet.technician_id,
        technician_object: timesheet.technician,
        available_technicians: availableTechnicians.map(t => ({ id: t.id, name: t.name }))
      });
      
      setSelectedTechnicianId(timesheet.technician_id || '');
      setHoursWorked(decimalHours);
      setDescription(timesheet.description || '');

      const entryDate = dayjs(timesheet.date).format('YYYY-MM-DD');
      const parsedStart = parseTimesheetDateTime(entryDate, timesheet.start_time);
      const parsedEnd = parseTimesheetDateTime(entryDate, timesheet.end_time);
      
      console.log('Time parsing:', {
        date: entryDate,
        start_time: timesheet.start_time,
        end_time: timesheet.end_time,
        parsedStart: parsedStart?.format('HH:mm'),
        parsedEnd: parsedEnd?.format('HH:mm')
      });

      setStartTimeObj(parsedStart);
      setEndTimeObj(parsedEnd);
      
      setDialogOpen(true);
    }
  };

  const resetForm = () => {
    setProjectId(0);
    setTaskId(0);
    setLocationId('');
    setSelectedTechnicianId('');
    setHoursWorked(0);
    setDescription('');
    // Set default working hours (9:00 AM + 1 hour = 10:00 AM)
    const defaultStart = dayjs().hour(9).minute(0).second(0);
    const defaultEnd = defaultStart.add(1, 'hour'); // Auto-increment by 1 hour
    setStartTimeObj(defaultStart);
    setEndTimeObj(defaultEnd);
    
    // Reset AI suggestion state
    setAiSuggestionExpanded(false);
    aiSuggestion.dismissSuggestion();
  };

  const handleSave = async () => {
    if (isReadOnlyMode) {
      showReadOnlyWarning();
      return;
    }
    // Sequential validation: focus first invalid field and show error
    if (!projectId) {
      showError('Project is required');
      document.getElementById('timesheet-project-field')?.focus();
      return;
    }
    if (!taskId) {
      showError('Task is required');
      document.getElementById('timesheet-task-field')?.focus();
      return;
    }
    if (!locationId) {
      showError('Location is required');
      document.getElementById('timesheet-location-field')?.focus();
      return;
    }
    if (!description.trim() || description.trim().length < 3) {
      showError('Description is required (min 3 characters)');
      document.getElementById('timesheet-description-field')?.focus();
      return;
    }
    if (!selectedTechnicianId) {
      showError('Worker is required');
      document.getElementById('timesheet-worker-field')?.focus();
      return;
    }
    if (!selectedDate) {
      showError('Date is required');
      return;
    }
    if (!startTimeObj || !endTimeObj) {
      showError('Start and end times are required');
      return;
    }
    if (hoursWorked <= 0) {
      showError('Hours worked must be greater than 0');
      return;
    }
    if (hoursWorked > 24) {
      showError('Hours worked cannot exceed 24 hours');
      return;
    }

    try {
      setLoading(true);

      // Additional security check: prevent editing other users' timesheets
      if (selectedEntry) {
        const isOwner = isTimesheetOwnedByUser(selectedEntry);
        const managesProject = Boolean(userIsManager && user?.managed_projects?.includes(selectedEntry.project_id));
        const canEdit = isOwner || userIsAdmin || managesProject;
        
        if (!canEdit) {
          showWarning('You can only edit your own timesheets or timesheets from projects you manage.');
          setLoading(false);
          return;
        }
      }

      const timesheet = {
        technician_id: selectedTechnicianId, // Use selected technician ID
        project_id: projectId,
        task_id: taskId,
        location_id: parseInt(locationId, 10), // Convert string to number
        date: formatDate(selectedDate),
        hours_worked: hoursWorked,
        description: description.trim(),
        start_time: timeToString(startTimeObj),
        end_time: timeToString(endTimeObj),
        status: 'submitted'
      };

      console.log('Saving timesheet:', timesheet);
      console.log('Selected technician ID from state:', selectedTechnicianId);
      console.log('Available technicians:', availableTechnicians);

      const requestedTechnicianId = typeof selectedTechnicianId === 'number' ? selectedTechnicianId : null;

      const extractWarningMessage = (result: unknown): string | null => {
        const w = (result as { warning?: unknown } | null | undefined)?.warning;
        if (!w) return null;
        if (typeof w === 'string') return w;
        if (typeof (w as { message?: unknown }).message === 'string' && (w as { message: string }).message.trim()) {
          return (w as { message: string }).message;
        }
        if (typeof (w as { detail?: unknown }).detail === 'string' && (w as { detail: string }).detail.trim()) {
          return (w as { detail: string }).detail;
        }
        return null;
      };

      if (selectedEntry) {
        // Update existing timesheet
        const result: unknown = await timesheetsApi.update(selectedEntry.id, timesheet);
        const warningMessage = extractWarningMessage(result);
        if (warningMessage) {
          showWarning(warningMessage);
        }
        showSuccess('Timesheet updated successfully');
      } else {
        // Create new timesheet
        const result: unknown = await timesheetsApi.create(timesheet);
        const warningMessage = extractWarningMessage(result);
        if (warningMessage) {
          showWarning(warningMessage);
        }

        const resultData = (result as { data?: unknown } | null | undefined)?.data;
        const savedTechnicianId = Number(
          (resultData as { technician_id?: unknown; technician?: { id?: unknown } } | null | undefined)?.technician_id ??
            (resultData as { technician?: { id?: unknown } } | null | undefined)?.technician?.id
        );
        const hasOverride =
          requestedTechnicianId !== null &&
          Number.isFinite(savedTechnicianId) &&
          savedTechnicianId > 0 &&
          savedTechnicianId !== requestedTechnicianId;

        if (hasOverride) {
          setSelectedTechnicianId(savedTechnicianId);
          if (!warningMessage) {
            showWarning('Selected worker was not allowed; the timesheet was saved for your technician.');
          }
        }
        showSuccess('Timesheet created successfully');
      }

      console.log('Timesheet saved successfully');
      await loadTimesheets(); // Reload data
      setDialogOpen(false);
      resetForm();
      
      // Provide feedback to AI if suggestion was used
      if (!selectedEntry && aiSuggestion.suggestion) {
        handleAIFeedback(true); // Successful save indicates good suggestion
      }
    } catch (err: unknown) {
      console.error('Error saving timesheet:', err);

      const error = err as {
        response?: {
          status?: number;
          data?: {
            message?: unknown;
            error?: unknown;
            errors?: Record<string, unknown>;
          };
        };
        message?: unknown;
      };
      let shouldRefresh = false;

      if (error.response?.status === 403) {
        const message =
          (typeof error.response?.data?.message === 'string' && error.response.data.message) ||
          (typeof error.response?.data?.error === 'string' && error.response.data.error) ||
          'Forbidden';
        showError(message);
      
        // Provide negative feedback to AI if suggestion was used
        if (!selectedEntry && aiSuggestion.suggestion) {
          handleAIFeedback(false);
        }
        return;
      }
      
      // Handle time overlap errors (422/409)
      if (error.response?.status === 422 || error.response?.status === 409) {
        const message =
          (typeof error.response?.data?.message === 'string' && error.response.data.message) ||
          (typeof error.response?.data?.error === 'string' && error.response.data.error) ||
          '';
        const isOverlapError = message.toLowerCase().includes('overlap') || 
                               message.toLowerCase().includes('sobreposição') ||
                               Boolean((error.response?.data?.errors as Record<string, unknown> | undefined)?.time_overlap);
        
        if (isOverlapError) {
          const timeRange = `${formatTenantTime(startTimeObj, tenantContext)} - ${formatTenantTime(endTimeObj, tenantContext)}`;
          showError(
            `⚠️ Time conflict detected for ${timeRange}. ` +
            `There is already an entry in this time period. ` +
            `Please choose a different time slot or check existing entries.`
          );
          shouldRefresh = true; // Refresh to show latest data
        } else {
          // Other validation errors
          if (error.response?.data?.errors) {
            const validationErrors = Object.entries(error.response.data.errors)
              .map(([field, messages]) => {
                const msgList = Array.isArray(messages)
                  ? messages.filter((m): m is string => typeof m === 'string')
                  : [];
                return `${field}: ${msgList.join(', ')}`;
              })
              .join('\n');
            console.error('Validation errors:', validationErrors);
            showError(`Validation failed: ${validationErrors}`);
          } else {
            showError(message);
          }
        }
      } else if (error.response?.data?.message) {
        // Check if message is about status immutability (should be warning, not error)
        const message = typeof error.response.data.message === 'string' ? error.response.data.message : '';
        const isStatusWarning = message.includes('cannot be edited') || message.includes('Approved or closed');
        if (isStatusWarning) {
          showWarning(message);
        } else {
          showError(message);
          // Refresh on 500 errors to ensure data consistency
          if (error.response?.status === 500) {
            shouldRefresh = true;
          }
        }
      } else {
        const errorMsg = error instanceof Error ? error.message : 'Failed to save timesheet';
        showError(errorMsg);
      }
      
      // Auto-refresh after validation/overlap errors to show current state
      if (shouldRefresh) {
        console.log('Auto-refreshing data after error...');
        await loadTimesheets();
      }
      
      // Provide negative feedback to AI if suggestion was used
      if (!selectedEntry && aiSuggestion.suggestion) {
        handleAIFeedback(false);
      }
    } finally {
      setLoading(false);
    }
  };

  const handleDelete = async () => {
    if (isReadOnlyMode) {
      showReadOnlyWarning();
      return;
    }
    if (!selectedEntry) return;

    const project = projects.find(p => p.id === selectedEntry.project_id);
    const task = tasks.find(t => t.id === selectedEntry.task_id);
    
    setConfirmDialog({
      open: true,
      title: 'Delete Timesheet Entry',
      message: 'Are you sure you want to delete this timesheet entry? This action cannot be undone.',
      recordDetails: {
        date: formatDate(dayjs(selectedEntry.date)),
        project: project?.name,
        task: task?.description,
        hours: selectedEntry.hours_worked
      },
      action: async () => {
        try {
          setLoading(true);
          
          console.log('Deleting timesheet:', selectedEntry.id);
          await timesheetsApi.delete(selectedEntry.id);
          
          console.log('Timesheet deleted successfully');
          showSuccess('Timesheet deleted successfully');
          await loadTimesheets();
          setDialogOpen(false);
          resetForm();
        } catch (error: unknown) {
          console.error('Error deleting timesheet:', error);
          const axiosish = error as { response?: { data?: { message?: unknown } }; message?: unknown };
          const errorMessage =
            (typeof axiosish.response?.data?.message === 'string' && axiosish.response.data.message) ||
            (typeof axiosish.message === 'string' && axiosish.message) ||
            'Failed to delete timesheet';
          showError(errorMessage);
        } finally {
          setLoading(false);
        }
        setConfirmDialog({ ...confirmDialog, open: false });
      }
    });
  };

  const isTimesheetOwnedByUser = useCallback((timesheet: Timesheet): boolean => {
    if (!user) {
      return false;
    }

    // Check via technician.user_id (technicians table has user_id FK)
    if (timesheet.technician?.user_id && user.id) {
      return timesheet.technician.user_id === user.id;
    }

    // Fallback to email comparison
    if (timesheet.technician?.email && user.email) {
      return timesheet.technician.email === user.email;
    }

    return false;
  }, [user]);
  const uiFilteredTimesheets = useMemo(() => {
    if (!user) {
      return [] as Timesheet[];
    }

    return applyTimesheetUiFilters(policyVisibleTimesheets, {
      scope: timesheetScope,
      validationFilter,
      overCapIds: validationSummary.overCapIds,
      isOwnedByUser: isTimesheetOwnedByUser,
    });
  }, [user, policyVisibleTimesheets, timesheetScope, validationFilter, validationSummary.overCapIds, isTimesheetOwnedByUser]);

  const caOt2Candidates = useMemo(() => {
    if (currentCalendarViewType !== 'timeGridWeek') return [];

    const weekStartDate = weekSummary?.workweek_start ?? currentWeekStartDate;
    if (!weekStartDate) return [];

    // Grouping fields used ("what is a day"):
    // - Timesheet.date (YYYY-MM-DD) is treated as the local work day key.
    // - Timesheet.hours_worked is summed for that day.
    // NOTE: We do NOT use /api/timesheets/summary for per-day breakdown (it doesn't include it).
    return computeCaDailyOt2Candidates(
      policyVisibleTimesheets.map((ts) => ({
        technician_id: ts.technician_id,
        date: ts.date,
        hours_worked: ts.hours_worked,
      })),
      weekStartDate
    );
  }, [currentCalendarViewType, currentWeekStartDate, weekSummary?.workweek_start, policyVisibleTimesheets]);

  const policyPillLabel = useMemo(() => {
    const raw = weekSummary?.policy_key ?? tenantContext?.policy_key;
    if (typeof raw !== 'string') return null;
    const trimmed = raw.trim();
    return trimmed ? `Policy: ${trimmed}` : null;
  }, [tenantContext?.policy_key, weekSummary?.policy_key]);

  const weeklySummaryPillLabel = useMemo(() => {
    if (currentCalendarViewType !== 'timeGridWeek') return null;

    if (weekSummaryStatus === 'loading') return 'Summary: loading…';
    if (weekSummaryStatus === 'error') return 'Summary unavailable';
    if (weekSummaryStatus !== 'loaded' || !weekSummary) return 'Summary unavailable';

    const regular = formatTenantNumber(weekSummary.regular_hours ?? 0, tenantContext, 2);
    const overtimeRate = formatTenantNumber(weekSummary.overtime_rate ?? 1.5, tenantContext, 1);
    const overtime = formatTenantNumber(weekSummary.overtime_hours ?? 0, tenantContext, 2);
    const overtime2 = formatTenantNumber(weekSummary.overtime_hours_2_0 ?? 0, tenantContext, 2);

    return `Regular ${regular}h | OT${overtimeRate} ${overtime}h | OT2.0 ${overtime2}h`;
  }, [currentCalendarViewType, tenantContext, weekSummary, weekSummaryStatus]);

  const caOt2Alert = useMemo(() => {
    const region = String(tenantContext?.region ?? '').toUpperCase();
    const state = String(tenantContext?.state ?? '').toUpperCase();

    if (currentCalendarViewType !== 'timeGridWeek') return null;
    if (region !== 'US' || state !== 'CA') return null;

    // Requires week summary AND a positive OT(2.0x) total from backend.
    // We only *pinpoint days* using a UI heuristic; we do not compute totals here.
    if (!weekSummary || weekSummaryStatus !== 'loaded') return null;

    const ot2Total = weekSummary.overtime_hours_2_0 ?? 0;
    if (ot2Total <= 0) return null;

    // Compute total daily hours (across all visible timesheets) for the same week window.
    const weekStartDate = weekSummary?.workweek_start ?? currentWeekStartDate;
    const weekStart = dayjs(weekStartDate, 'YYYY-MM-DD', true);
    if (!weekStart.isValid()) return null;
    const weekEndExclusive = weekStart.add(7, 'day');

    const dailyTotalsByDate = new Map<string, number>();
    for (const ts of policyVisibleTimesheets) {
      const date = typeof ts?.date === 'string' ? ts.date : '';
      if (!date) continue;
      const dateObj = dayjs(date, 'YYYY-MM-DD', true);
      if (!dateObj.isValid()) continue;
      if (dateObj.isBefore(weekStart) || !dateObj.isBefore(weekEndExclusive)) continue;

      const hours = typeof ts.hours_worked === 'number' ? ts.hours_worked : Number(ts.hours_worked);
      if (!Number.isFinite(hours)) continue;

      const key = dateObj.format('YYYY-MM-DD');
      dailyTotalsByDate.set(key, (dailyTotalsByDate.get(key) ?? 0) + hours);
    }

    const rows = (Array.isArray(caOt2Candidates) ? caOt2Candidates : [])
      .map((candidate) => {
        const totalHours = dailyTotalsByDate.get(candidate.date);
        if (!Number.isFinite(totalHours)) return null;

        return {
          date: candidate.date,
          ot2Hours: candidate.ot2Hours,
          totalHours,
        };
      })
      .filter((row): row is { date: string; ot2Hours: number; totalHours: number } => row !== null);

    if (rows.length === 0) {
      return {
        title: t('rightPanel.timesheets.caOt2.title'),
        severity: 'info' as const,
        message: t('rightPanel.timesheets.caOt2.noDays'),
      };
    }

    return {
      title: t('rightPanel.timesheets.caOt2.title'),
      severity: 'error' as const,
      rows,
    };
  }, [tenantContext, currentCalendarViewType, currentWeekStartDate, policyVisibleTimesheets, weekSummary, weekSummaryStatus, caOt2Candidates, t]);

  const insightsAlertsCount = useMemo(() => {
    let count = 0;
    if (policyAlert) count += 1;
    if (caOt2Alert) count += 1;
    return count;
  }, [policyAlert, caOt2Alert]);

  const alertsSummary = useTimesheetAlertsSummary({
    aiAlertsCount: validationSummary.aiFlagged,
    hasPolicyAlert: Boolean(policyAlert),
    hasCaOt2Alert: Boolean(caOt2Alert),
  });

  const handleToggleInsightsPanel = useRightPanelTabToggle('timesheet-insights', () => {
    const isCurrentlyOpen = isRightPanelOpen && rightPanelActiveTabId === 'timesheet-insights';
    if (isCurrentlyOpen) return;

    const defaultTab: 'alerts' | 'weekly' =
      insightsAlertsCount > 0
        ? 'alerts'
        : currentCalendarViewType === 'timeGridWeek' && (weekSummaryStatus === 'loaded' || Boolean(weekSummary))
          ? 'weekly'
          : 'alerts';

    setInsightsTab(defaultTab);
  });

  const timesheetInsightsTab = useMemo(
    () => ({
      id: 'timesheet-insights',
      label: t('rightPanel.tabs.insights'),
      order: -10,
      render: () => (
        <Box sx={{ display: 'flex', flexDirection: 'column', height: '100%' }}>
          <Tabs
            value={insightsTab}
            onChange={(_, next) => {
              if (next === 'alerts' || next === 'weekly') {
                setInsightsTab(next);
              }
            }}
            variant="fullWidth"
          >
            <Tab
              value="alerts"
              label={t('rightPanel.timesheets.tabs.alerts', { count: alertsSummary.insightsAlertsCount })}
            />
            <Tab value="weekly" label={t('rightPanel.timesheets.tabs.weekly')} />
          </Tabs>

          <Box sx={{ pt: 2 }}>
            {insightsTab === 'alerts' ? (
              <Box>
                {alertsSummary.aiAlertsCount > 0 && (
                  <Alert
                    severity="warning"
                    variant="outlined"
                    sx={{ mb: 1 }}
                    action={
                      <Button
                        color="inherit"
                        size="small"
                        onClick={() => toggleValidationFilter('ai_flagged')}
                        sx={{ textTransform: 'none' }}
                      >
                        {t('rightPanel.timesheets.aiReview', { count: alertsSummary.aiAlertsCount })}
                      </Button>
                    }
                  >
                    <AlertTitle>{t('rightPanel.timesheets.aiAvailableTitle')}</AlertTitle>
                    {t('rightPanel.timesheets.aiAvailableBody', { count: alertsSummary.aiAlertsCount })}
                  </Alert>
                )}

                {policyAlert && (
                  <Alert
                    severity={policyAlert.severity}
                    sx={{ mb: 1 }}
                    action={
                      policyAlert.cta ? (
                        <Button color="inherit" size="small" onClick={() => navigate(policyAlert.cta!.to)}>
                          {policyAlert.cta.label}
                        </Button>
                      ) : null
                    }
                  >
                    <AlertTitle>{policyAlert.title}</AlertTitle>
                    {policyAlert.message}
                  </Alert>
                )}

                {caOt2Alert && (
                  <Alert severity={caOt2Alert.severity} sx={{ mb: 1 }}>
                    <AlertTitle>{caOt2Alert.title}</AlertTitle>
                    {'message' in caOt2Alert ? (
                      <Typography variant="body2">{caOt2Alert.message}</Typography>
                    ) : (
                      <Box component="ul" sx={{ pl: 2, my: 0 }}>
                        {caOt2Alert.rows.map((row) => {
                          const dateLabel = formatTenantDate(row.date, tenantContext);
                          const ot2Label = formatTenantNumber(row.ot2Hours, tenantContext, 2);
                          const totalLabel = formatTenantNumber(row.totalHours, tenantContext, 2);
                          return (
                            <li key={row.date}>
                              {t('rightPanel.timesheets.caOt2.row', {
                                date: dateLabel,
                                ot2: ot2Label,
                                total: totalLabel,
                              })}
                            </li>
                          );
                        })}
                      </Box>
                    )}
                  </Alert>
                )}

                {!policyAlert && !caOt2Alert && alertsSummary.aiAlertsCount === 0 && (
                  <Box>
                    <Typography variant="body2" sx={{ color: 'text.secondary', mb: 1 }}>
                      {t('rightPanel.timesheets.noAlerts')}
                    </Typography>
                    <Box component="ul" sx={{ pl: 2, my: 0, color: 'text.secondary' }}>
                      <li>{t('rightPanel.timesheets.alertExamples.overtime')}</li>
                      <li>{t('rightPanel.timesheets.alertExamples.missingBreaks')}</li>
                      <li>{t('rightPanel.timesheets.alertExamples.policyDrift')}</li>
                    </Box>
                  </Box>
                )}
              </Box>
            ) : (
              <Box>
                {currentCalendarViewType !== 'timeGridWeek' ? (
                  <Box>
                    <Typography variant="body2" sx={{ color: 'text.secondary', mb: 1 }}>
                      {t('rightPanel.timesheets.weeklyOnlyInWeek')}
                    </Typography>
                    <Box component="ul" sx={{ pl: 2, my: 0, color: 'text.secondary', mb: 1 }}>
                      <li>{t('rightPanel.timesheets.weeklyPreview.regularHours')}</li>
                      <li>{t('rightPanel.timesheets.weeklyPreview.overtimeSplit')}</li>
                      <li>{t('rightPanel.timesheets.weeklyPreview.policyKey')}</li>
                    </Box>
                    <Button variant="outlined" size="small" onClick={handleSwitchToWeekView}>
                      {t('rightPanel.timesheets.weeklyCta')}
                    </Button>
                  </Box>
                ) : (
                  <Card sx={{ borderRadius: 2, boxShadow: 0, border: '1px solid', borderColor: 'grey.200' }}>
                    <CardContent sx={{ py: 1.25, '&:last-child': { pb: 1.25 } }}>
                      <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', gap: 1, flexWrap: 'wrap' }}>
                        <Typography variant="subtitle2" sx={{ fontWeight: 700 }}>
                          {t('rightPanel.timesheets.weeklyTitle')}
                        </Typography>
                        {weekSummary?.policy_key && (
                          <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                            {t('rightPanel.timesheets.weeklyPolicy', { policy: String(weekSummary.policy_key) })}
                          </Typography>
                        )}
                        {weekSummary?.workweek_start && (
                          <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                            {t('rightPanel.timesheets.weeklyStart', {
                              date: formatTenantDate(weekSummary.workweek_start, tenantContext),
                            })}
                          </Typography>
                        )}
                      </Box>

                      {weekSummaryStatus === 'loading' && (
                        <Typography variant="body2" sx={{ mt: 0.5, color: 'text.secondary' }}>
                          {t('rightPanel.timesheets.weeklyLoading')}
                        </Typography>
                      )}

                      {weekSummaryStatus === 'error' && (
                        <Typography variant="body2" sx={{ mt: 0.5, color: 'text.secondary' }}>
                          {t('rightPanel.timesheets.weeklyUnavailable')}
                        </Typography>
                      )}

                      {weekSummaryStatus === 'loaded' && weekSummary && (
                        <Grid container spacing={1} sx={{ mt: 0.25 }}>
                          <Grid item xs={12} sm={4}>
                            <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                              {t('rightPanel.timesheets.weeklyRegular')}
                            </Typography>
                            <Typography variant="body2" sx={{ fontWeight: 700 }}>
                              {formatTenantNumber(weekSummary.regular_hours ?? 0, tenantContext, 2)} h
                            </Typography>
                          </Grid>
                          <Grid item xs={12} sm={4}>
                            <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                              {t('rightPanel.timesheets.weeklyOvertime', {
                                rate: formatTenantNumber(weekSummary.overtime_rate ?? 1.5, tenantContext, 1),
                              })}
                            </Typography>
                            <Typography variant="body2" sx={{ fontWeight: 700 }}>
                              {formatTenantNumber(weekSummary.overtime_hours ?? 0, tenantContext, 2)} h
                            </Typography>
                          </Grid>
                          <Grid item xs={12} sm={4}>
                            <Typography variant="caption" sx={{ color: 'text.secondary' }}>
                              {t('rightPanel.timesheets.weeklyOvertime2')}
                            </Typography>
                            <Typography variant="body2" sx={{ fontWeight: 700 }}>
                              {formatTenantNumber(weekSummary.overtime_hours_2_0 ?? 0, tenantContext, 2)} h
                            </Typography>
                          </Grid>
                        </Grid>
                      )}
                    </CardContent>
                  </Card>
                )}
              </Box>
            )}
          </Box>
        </Box>
      ),
    }),
    [
      insightsTab,
      alertsSummary.aiAlertsCount,
      alertsSummary.insightsAlertsCount,
      policyAlert,
      caOt2Alert,
      navigate,
      tenantContext,
      currentCalendarViewType,
      handleSwitchToWeekView,
      weekSummaryStatus,
      weekSummary,
      t,
    ]
  );

  useRegisterRightPanelTab(timesheetInsightsTab);

  // Filter tasks for selected project
  const filteredTasks = useMemo(() => {
    const pid = Number(projectId);
    if (!pid) return [] as Task[];
    return (tasks || []).filter((task) => task.project_id === pid);
  }, [tasks, projectId]);

  // Filter locations for selected task
  const filteredLocations = useMemo(() => {
    if (!taskId || taskId === 0) {
      // No task selected = no locations (force user to select task first)
      return [];
    }
    
    // Find the selected task and get its associated locations
    const selectedTask = tasks.find(t => t.id === taskId);
    if (!selectedTask || !selectedTask.locations || selectedTask.locations.length === 0) {
      // Task has no locations = no locations (no valid locations for this task)
      return [];
    }
    
    // Return only locations associated with the selected task
    const taskLocationIds = selectedTask.locations.map(loc => loc.id);
    return (locations || []).filter(loc => taskLocationIds.includes(loc.id));
  }, [taskId, tasks, locations]);

  const handleTimesheetScopeChange = (_event: React.MouseEvent<HTMLElement>, newScope: 'mine' | 'others' | 'all' | null) => {
    if (!newScope) {
      return;
    }

    console.log('Timesheet scope changed from', timesheetScope, 'to', newScope);
    setTimesheetScope(newScope);
  };

  // Generate calendar events from timesheets
  const calendarEvents = useMemo((): EventInput[] => {
    if (!uiFilteredTimesheets) {
      return [];
    }

    return uiFilteredTimesheets.map((timesheet) => {
      const isOwner = isTimesheetOwnedByUser(timesheet);
      const managesProject = Boolean(userIsManager && user?.managed_projects?.includes(timesheet.project_id));
      const canEdit = isOwner || userIsAdmin || managesProject;
      const eventClassNames = [canEdit ? 'editable-event' : 'readonly-event'];

      const statusKey = timesheet.status && STATUS_STYLE_MAP[timesheet.status]
        ? timesheet.status
        : 'default';

      eventClassNames.push(`status-${statusKey}`);

      if (isOwner) {
        eventClassNames.push('owner-event');
      } else {
        eventClassNames.push('member-event');
      }

      const statusStyle = STATUS_STYLE_MAP[statusKey] ?? STATUS_STYLE_MAP.default;
      const eventTextColor = '#0d47a1';

      // Extract date in YYYY-MM-DD format
      const dateOnly = dayjs(timesheet.date).format('YYYY-MM-DD');

      const eventData: EventInput = {
        id: timesheet.id.toString(),
        title: `${timesheet.project?.name || 'Project'} - ${decimalToHHMM(timesheet.hours_worked)}`,
        backgroundColor: statusStyle.background,
        borderColor: '#e0e0e0',
        textColor: eventTextColor,
        extendedProps: {
          timesheet: timesheet, // Pass full timesheet object for time rendering
          technician: timesheet.technician,
          project: timesheet.project,
          task: timesheet.task,
          location: timesheet.location,
          hours_worked: timesheet.hours_worked,
          description: timesheet.description,
          status: timesheet.status,
          start_time: timesheet.start_time,
          end_time: timesheet.end_time,
          isOwner,
          canEdit,
          managesProject
        },
        className: eventClassNames.join(' ')
      };

      const startDateTime = parseTimesheetDateTime(dateOnly, timesheet.start_time);
      const endDateTime = parseTimesheetDateTime(dateOnly, timesheet.end_time);
      
      // Convert hours_worked to decimal (e.g., "5.50" -> 5.5 hours)
      const hoursDecimal = typeof timesheet.hours_worked === 'string' 
        ? parseFloat(timesheet.hours_worked)
        : timesheet.hours_worked;
      const workedMinutes = hoursDecimal * 60; // Convert to minutes

      if (startDateTime) {
        eventData.start = startDateTime.toDate();

        // Use endDateTime if available and valid, otherwise calculate from duration
        if (endDateTime && endDateTime.isAfter(startDateTime)) {
          eventData.end = endDateTime.toDate();
        } else {
          // Fallback: calculate end from start + duration
          eventData.end = startDateTime.add(workedMinutes, 'minute').toDate();
        }
        eventData.allDay = false;
      } else {
        eventData.start = dateOnly;
        eventData.allDay = true;
      }

      return eventData;
    });
  }, [uiFilteredTimesheets, user, userIsManager, userIsAdmin, isTimesheetOwnedByUser]);

  // Day cell renderer - add travel indicators to calendar days
  const handleDayCellDidMount = (info: DayCellMountArg) => {
    const dateStr = dayjs(info.date).format('YYYY-MM-DD');
    const travelsForDay = travelsByDate[dateStr];
    
    // Debug: Log every day cell mount
    if (dateStr === '2025-11-12' || dateStr === '2025-11-13') {
      console.log(`🛫 [DAY CELL] ${dateStr}:`, {
        travelsForDay,
        allTravels: travelsByDate,
        viewType: info.view.type
      });
    }
    
    if (!travelsForDay || travelsForDay.length === 0) {
      return; // No travels on this day
    }
    
    // Show indicators in month view and list views
    const viewType = info.view.type;
    const isMonthView = viewType === 'dayGridMonth';
    const isListView = viewType === 'listWeek' || viewType === 'listMonth';
    
    if (!isMonthView && !isListView) {
      return; // Skip for timeGridWeek (week calendar view)
    }
    
    console.log(`🛫 [INDICATOR] Creating badge for ${dateStr} with ${travelsForDay.length} travels in ${viewType}`);
    
    // For list views, we need to find/create a different container
    if (isListView) {
      // In list view, we need to inject travel info as list items
      // Find the list day element for this date
      const listDayEl = document.querySelector(`[data-date="${dateStr}"]`);
      if (!listDayEl) {
        console.warn(`🛫 [INDICATOR] No list element found for ${dateStr}`);
        return;
      }
      
      // Check if we already added travel items for this date
      if (listDayEl.querySelector('.travel-list-item')) {
        return; // Already added
      }
      
      // Create travel list items for each travel
      travelsForDay.forEach((travel, index) => {
        const travelItem = document.createElement('div');
        travelItem.className = 'fc-list-event travel-list-item';
        travelItem.style.cssText = `
          cursor: pointer;
          border-left: 4px solid #2196f3;
          background-color: #e3f2fd;
          margin: 2px 0;
          padding: 8px 12px;
        `;
        
        const statusColor = travel.status === 'completed' ? '#4caf50' : 
                           travel.status === 'cancelled' ? '#9e9e9e' : '#ff9800';
        
        travelItem.innerHTML = `
          <div style="display: flex; align-items: center; gap: 8px;">
            <span style="font-size: 18px; color: ${statusColor};">✈</span>
            <div style="flex: 1;">
              <div style="font-weight: 600; color: #1976d2;">
                ${travel.origin_city || travel.origin_country} → ${travel.destination_city || travel.destination_country}
              </div>
              <div style="font-size: 0.85em; color: #666;">
                ${travel.direction || 'Travel'} • ${travel.status}
              </div>
            </div>
          </div>
        `;
        
        travelItem.addEventListener('click', (e) => {
          e.preventDefault();
          e.stopPropagation();
          handleTravelIndicatorClick(dateStr, travelsForDay);
        });
        
        // Insert after the date header in list view
        const listFrame = listDayEl.closest('.fc-list-day-frame');
        if (listFrame && index === 0) { // Add after date row only once
          listFrame.insertAdjacentElement('afterend', travelItem);
        }
      });
      
      console.log(`🛫 [INDICATOR] Added ${travelsForDay.length} travel items to list view for ${dateStr}`);
      return;
    }
    
    // Month view indicator (existing code)
    // Find the day-top element (contains the day number)
    const dayTop = info.el.querySelector('.fc-daygrid-day-top');
    if (!dayTop) {
      console.warn(`🛫 [INDICATOR] No day-top found for ${dateStr}`, {
        element: info.el,
        innerHTML: info.el.innerHTML,
        classList: info.el.classList
      });
      return;
    }
    
    console.log(`🛫 [INDICATOR] Found day-top for ${dateStr}`, dayTop);
    
    // Determine plane color based on travel status (matching timesheet colors)
    const getPlaneColor = (travels: TravelSegment[]) => {
      // If multiple travels, use the most "important" status
      const hasCompleted = travels.some(t => t.status === 'completed');
      const hasCancelled = travels.some(t => t.status === 'cancelled');
      const hasPlanned = travels.some(t => t.status === 'planned');
      
      if (hasCompleted) return '#4caf50';    // Green (approved/completed)
      if (hasPlanned) return '#ff9800';      // Orange (submitted/planned)
      if (hasCancelled) return '#9e9e9e';    // Gray (cancelled)
      return '#ff9800';                       // Default to orange
    };
    
    const planeColor = getPlaneColor(travelsForDay);
    
    // Create travel indicator badge - blue border, colored plane
    const indicator = document.createElement('div');
    indicator.className = 'travel-indicator';
    indicator.style.cssText = `
      position: absolute;
      top: 4px;
      left: 4px;
      background-color: transparent;
      color: ${planeColor};
      border: 2px solid #2196f3;
      border-radius: 50%;
      width: 36px;
      height: 36px;
      display: flex;
      align-items: center;
      justify-content: center;
      font-size: 20px;
      font-weight: bold;
      cursor: pointer;
      z-index: 1000;
      box-shadow: 0 2px 6px rgba(0,0,0,0.2);
      pointer-events: auto;
      line-height: 1;
    `;
    indicator.textContent = '✈';
    indicator.title = `${travelsForDay.length} travel(s) - Click to view details`;
    
    // Add click handler to show travel details - PREVENT dateClick propagation
    indicator.addEventListener('click', (e) => {
      e.preventDefault();
      e.stopPropagation();
      e.stopImmediatePropagation();
      console.log('🛫 Travel indicator clicked, opening dialog');
      handleTravelIndicatorClick(dateStr, travelsForDay);
    });
    
    // Make day cell position relative and append indicator to cell (not day-top)
    info.el.style.position = 'relative';
    info.el.appendChild(indicator);
    
    console.log(`🛫 [INDICATOR] Badge appended to ${dateStr} cell`, {
      cellElement: info.el,
      indicatorPosition: {
        top: indicator.style.top,
        right: indicator.style.right
      }
    });
  };

  // Custom event content renderer to show technician name
  // Event rendering - use eventDidMount instead of eventContent to preserve height calculation
  const handleEventDidMount = (info: EventMountArg) => {
    const { technician, isOwner, project, task, location } = info.event.extendedProps;
    const technicianName = technician?.name || 'Unknown';
    
    // Get initials for badge
    const initials = technicianName
      .split(' ')
      .map((word: string) => word[0])
      .join('')
      .toUpperCase()
      .substring(0, 2);

    // Only customize timeGrid events (Week view) - let others render normally
    if (info.view.type === 'timeGridWeek' || info.view.type === 'timeGridDay') {
      const fcContent = info.el.querySelector('.fc-event-main');
      if (fcContent) {
        // Keep the time element that FullCalendar created at the top
        const fcTitle = fcContent.querySelector('.fc-event-title');
        
        if (fcTitle instanceof HTMLElement) {
          // Clear the title content (we'll rebuild it with more info)
          fcTitle.innerHTML = '';
          fcTitle.style.cssText = `
            display: flex;
            flex-direction: column;
            gap: 2px;
            overflow: hidden;
          `;
          
          // Create badge + project line
          const projectLine = document.createElement('div');
          projectLine.style.cssText = `
            display: flex;
            align-items: center;
            gap: 4px;
            overflow: hidden;
          `;
          
          // Create badge element
          const badge = document.createElement('span');
          badge.style.cssText = `
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 14px;
            height: 14px;
            border-radius: 50%;
            background-color: ${isOwner ? '#1976d2' : '#757575'};
            color: white;
            font-size: 0.5rem;
            font-weight: bold;
            flex-shrink: 0;
          `;
          badge.textContent = initials;
          badge.title = technicianName;
          
          // Create project name
          const projectName = document.createElement('span');
          projectName.style.cssText = `
            font-size: 0.7rem;
            font-weight: 600;
            overflow: hidden;
            text-overflow: ellipsis;
            white-space: nowrap;
            flex: 1;
          `;
          projectName.textContent = project?.name || 'Project';
          projectName.title = project?.name || '';
          
          projectLine.appendChild(badge);
          projectLine.appendChild(projectName);
          fcTitle.appendChild(projectLine);
          
          // Check for travels on this day (Section 13.3 - Weekly/Daily view integration)
          const eventDate = dayjs(info.event.start).format('YYYY-MM-DD');
          const travelsForDay = travelsByDate[eventDate];
          
          if (travelsForDay && travelsForDay.length > 0) {
            const travelLine = document.createElement('div');
            travelLine.style.cssText = `
              font-size: 0.65rem;
              color: #2196f3;
              font-weight: 600;
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap;
              padding-left: 2px;
              cursor: pointer;
            `;
            travelLine.textContent = `✈ ${travelsForDay.length} travel${travelsForDay.length > 1 ? 's' : ''}`;
            travelLine.title = 'Click to view travel details';
            travelLine.addEventListener('click', (e) => {
              e.stopPropagation();
              handleTravelIndicatorClick(eventDate, travelsForDay);
            });
            fcTitle.appendChild(travelLine);
          }
          
          // Add task if available
          if (task?.name) {
            const taskLine = document.createElement('div');
            taskLine.style.cssText = `
              font-size: 0.65rem;
              color: rgba(0,0,0,0.7);
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap;
              padding-left: 2px;
            `;
            taskLine.textContent = `📋 ${task.name}`;
            taskLine.title = task.name;
            fcTitle.appendChild(taskLine);
          }
          
          // Add location if available
          if (location?.name) {
            const locationLine = document.createElement('div');
            locationLine.style.cssText = `
              font-size: 0.6rem;
              color: rgba(0,0,0,0.6);
              overflow: hidden;
              text-overflow: ellipsis;
              white-space: nowrap;
              padding-left: 2px;
            `;
            locationLine.textContent = `📍 ${location.name}`;
            locationLine.title = location.name;
            fcTitle.appendChild(locationLine);
          }
        }
      }
    }
    
    // Customize dayGrid events (Month view) - add badge to title
    if (info.view.type === 'dayGridMonth') {
      // Try different selectors for dayGrid events
      const fcEventMain = info.el.querySelector('.fc-event-main');
      const fcEventMainFrame = info.el.querySelector('.fc-event-main-frame');
      const fcContent = fcEventMain || fcEventMainFrame || info.el;
      
      if (fcContent) {
        // Check if badge already exists
        if (!fcContent.querySelector('.tech-badge')) {
          const fcTitle = fcContent.querySelector('.fc-event-title') || 
                         fcContent.querySelector('.fc-event-title-container');
          
          if (fcTitle && fcTitle.parentNode) {
            // Create badge element
            const badge = document.createElement('span');
            badge.className = 'tech-badge'; // Add class to prevent duplicates
            badge.style.cssText = `
              display: inline-flex;
              align-items: center;
              justify-content: center;
              min-width: 14px;
              height: 14px;
              border-radius: 50%;
              background-color: ${isOwner ? '#1976d2' : '#757575'};
              color: white;
              font-size: 0.5rem;
              font-weight: bold;
              margin-right: 4px;
              flex-shrink: 0;
              vertical-align: middle;
            `;
            badge.textContent = initials;
            badge.title = technicianName;
            
            // Insert badge BEFORE the title element (not inside it)
            fcTitle.parentNode.insertBefore(badge, fcTitle);
          }
        }
      }
    }
    
    // Customize list events (Week List and Month List) - add badge before title
    if (info.view.type === 'listWeek' || info.view.type === 'listMonth') {
      const fcListEventTitle = info.el.querySelector('.fc-list-event-title');
      
      if (fcListEventTitle) {
        // Check if badge already exists
        if (!fcListEventTitle.querySelector('.tech-badge')) {
          // Create badge element
          const badge = document.createElement('span');
          badge.className = 'tech-badge'; // Add class to prevent duplicates
          badge.style.cssText = `
            display: inline-flex;
            align-items: center;
            justify-content: center;
            min-width: 16px;
            height: 16px;
            border-radius: 50%;
            background-color: ${isOwner ? '#1976d2' : '#757575'};
            color: white;
            font-size: 0.6rem;
            font-weight: bold;
            margin-right: 6px;
            flex-shrink: 0;
            vertical-align: middle;
          `;
          badge.textContent = initials;
          badge.title = technicianName;
          
          // Prepend badge to title
          fcListEventTitle.insertBefore(badge, fcListEventTitle.firstChild);
          
          // Check for travels on this day (Section 13.3 - List view integration)
          const eventDate = dayjs(info.event.start).format('YYYY-MM-DD');
          const travelsForDay = travelsByDate[eventDate];
          
          // Add task, location, and travel info after the time (project already shown in title)
          if (task?.name || location?.name || (travelsForDay && travelsForDay.length > 0)) {
            const detailsContainer = document.createElement('div');
            detailsContainer.style.cssText = `
              display: flex;
              flex-direction: column;
              gap: 2px;
              margin-top: 4px;
              font-size: 0.75rem;
              color: rgba(0,0,0,0.7);
            `;
            
            // Add travel indicator first if present
            if (travelsForDay && travelsForDay.length > 0) {
              const travelInfo = document.createElement('span');
              travelInfo.style.cssText = `
                color: #2196f3;
                font-weight: 600;
                cursor: pointer;
              `;
              travelInfo.textContent = `✈ ${travelsForDay.length} travel${travelsForDay.length > 1 ? 's' : ''}`;
              travelInfo.title = 'Click to view travel details';
              travelInfo.addEventListener('click', (e) => {
                e.stopPropagation();
                handleTravelIndicatorClick(eventDate, travelsForDay);
              });
              detailsContainer.appendChild(travelInfo);
            }
            
            if (task?.name) {
              const taskInfo = document.createElement('span');
              taskInfo.textContent = `📋 ${task.name}`;
              taskInfo.title = task.name;
              detailsContainer.appendChild(taskInfo);
            }
            
            if (location?.name) {
              const locationInfo = document.createElement('span');
              locationInfo.textContent = `📍 ${location.name}`;
              locationInfo.title = location.name;
              detailsContainer.appendChild(locationInfo);
            }
            
            fcListEventTitle.appendChild(detailsContainer);
          }
        }
      }
    }
  };

  return (
    <Box sx={{ 
      p: 0,
      width: '100%',
      maxWidth: '100%',
      height: '100vh',
      display: 'flex',
      flexDirection: 'column',
      overflow: 'hidden'
    }}>
      {/* Header Card with Status Legend - STICKY - Compacto */}
      <Card 
        sx={{ 
          mb: 0,
          background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
          color: 'white',
          borderRadius: 0,
          boxShadow: 'none',
          borderBottom: '2px solid rgba(255,255,255,0.2)',
          position: 'sticky',
          top: 0,
          zIndex: 100,
          flexShrink: 0
        }}
      >
        <CardContent sx={{ p: { xs: 0.75, sm: 1 }, '&:last-child': { pb: { xs: 0.75, sm: 1 } } }}>
          {/* Linha única: Título + Legendas */}
          <Box 
            sx={{ 
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              flexWrap: { xs: 'wrap', sm: 'nowrap' },
              gap: 1.5
            }}
          >
            <Typography 
              variant="h6" 
              component="h2" 
              sx={{ 
                fontWeight: 600,
                fontSize: { xs: '1.1rem', sm: '1.25rem' },
                flexShrink: 0,
                display: 'flex',
                alignItems: 'center',
                gap: 1
              }}
            >
              Timesheet
            </Typography>
            
            <Box
              sx={{
                display: 'flex',
                flexWrap: 'wrap',
                alignItems: 'center',
                gap: 1,
                justifyContent: { xs: 'flex-start', sm: 'flex-end' }
              }}
            >
              <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.75 }}>
                <Chip 
                  icon={<Avatar sx={{ bgcolor: '#ff9800 !important', width: 12, height: 12 }}>●</Avatar>} 
                  label="Submitted" 
                  variant="filled"
                  size="small"
                  sx={{ bgcolor: 'rgba(255,255,255,0.2)', color: 'white', height: 24, fontSize: '0.75rem' }}
                />
                <Chip 
                  icon={<Avatar sx={{ bgcolor: '#4caf50 !important', width: 12, height: 12 }}>●</Avatar>} 
                  label="Approved" 
                  variant="filled"
                  size="small"
                  sx={{ bgcolor: 'rgba(255,255,255,0.2)', color: 'white', height: 24, fontSize: '0.75rem' }}
                />
                <Chip 
                  icon={<Avatar sx={{ bgcolor: '#f44336 !important', width: 12, height: 12 }}>●</Avatar>} 
                  label="Rejected" 
                  variant="filled"
                  size="small"
                  sx={{ bgcolor: 'rgba(255,255,255,0.2)', color: 'white', height: 24, fontSize: '0.75rem' }}
                />
                <Chip 
                  icon={<Avatar sx={{ bgcolor: '#9c27b0 !important', width: 12, height: 12 }}>●</Avatar>} 
                  label="Closed" 
                  variant="filled"
                  size="small"
                  sx={{ bgcolor: 'rgba(255,255,255,0.2)', color: 'white', height: 24, fontSize: '0.75rem' }}
                />
              </Box>
              {(userIsManager || userIsAdmin) && (
                <ToggleButtonGroup
                  value={timesheetScope}
                  exclusive
                  onChange={handleTimesheetScopeChange}
                  size="small"
                  sx={{
                    ml: { xs: 0, sm: 1.5 },
                    backgroundColor: 'rgba(255,255,255,0.12)',
                    borderRadius: '999px',
                    border: '1px solid rgba(255,255,255,0.25)',
                    '& .MuiToggleButton-root': {
                      color: 'rgba(255,255,255,0.85)',
                      fontSize: '0.75rem',
                      px: 1.5,
                      border: 'none',
                      textTransform: 'none',
                      '&.Mui-selected': {
                        backgroundColor: 'rgba(255,255,255,0.3)',
                        color: '#ffffff'
                      },
                      '&:hover': {
                        backgroundColor: 'rgba(255,255,255,0.2)'
                      }
                    }
                  }}
                >
                  <ToggleButton value="mine">Mine</ToggleButton>
                  <ToggleButton value="others">Others</ToggleButton>
                  <ToggleButton value="all">All</ToggleButton>
                </ToggleButtonGroup>
              )}
            </Box>
          </Box>
        </CardContent>
      </Card>

      {/* Compact top strip: filters (left) + policy/summary pills + Insights (right) */}
      <Box
        sx={{
          pt: 0.5,
          mb: 0.5,
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 1,
          flexWrap: { xs: 'wrap', sm: 'nowrap' },
        }}
      >
        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, alignItems: 'center' }}>
          <Tooltip title="Days where technician's total exceeds 12h">
            <span>
              <Chip
                label={`Daily > ${DAILY_HOUR_CAP}h (${validationSummary.overCap})`}
                color={validationFilter === 'overcap' ? 'warning' : 'default'}
                variant={validationSummary.overCap ? 'filled' : 'outlined'}
                onClick={() => toggleValidationFilter('overcap')}
                disabled={!validationSummary.overCap}
                sx={{ fontWeight: 600 }}
              />
            </span>
          </Tooltip>
          {validationFilter !== 'all' && (
            <Chip
              label="Clear filter"
              onClick={() => setValidationFilter('all')}
              variant="outlined"
            />
          )}
        </Box>

        <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, alignItems: 'center', justifyContent: 'flex-end' }}>
          {policyPillLabel && (
            <Chip label={policyPillLabel} size="small" variant="outlined" sx={{ fontWeight: 700 }} />
          )}

          {weeklySummaryPillLabel && (
            <Chip label={weeklySummaryPillLabel} size="small" color="primary" variant="outlined" sx={{ fontWeight: 700 }} />
          )}
        </Box>
      </Box>

      <RightPanelTrigger
        tabId="timesheet-insights"
        tooltip={t('rightPanel.trigger.tooltip')}
        icon={<SmartToyIcon fontSize="small" />}
        ariaLabel={{
          open: t('rightPanel.trigger.open', { tab: t('rightPanel.tabs.insights') }),
          close: t('rightPanel.trigger.close', { tab: t('rightPanel.tabs.insights') }),
        }}
        onClick={handleToggleInsightsPanel}
        badge={
          alertsSummary.aiAlertsCount > 0
            ? { variant: 'count', count: alertsSummary.aiAlertsCount }
            : alertsSummary.insightsAlertsCount > 0
              ? { variant: 'dot', show: true }
              : undefined
        }
        sx={{ zIndex: theme.zIndex.drawer + 2 }}
      />

      {/* Calendar Container - Scrollable */}
      <Paper 
        elevation={0}
        sx={{ 
          borderRadius: 0,
          overflow: 'auto',
          flex: 1,
          border: '1px solid',
          borderColor: 'grey.200',
          display: 'flex',
          flexDirection: 'column',
          '& .fc': {
            height: '100%',
            width: '100%',
            fontFamily: theme.typography.fontFamily,
            display: 'flex',
            flexDirection: 'column'
          },
          '& .fc-view-harness': {
            flex: 1,
            overflow: 'auto',
            marginTop: '0 !important',
            paddingTop: '0 !important'
          },
          // Remove ALL space between toolbar and calendar body
          '& .fc-scrollgrid-section': {
            marginTop: '0 !important',
            paddingTop: '0 !important'
          },
          '& .fc-scrollgrid-section-header': {
            paddingTop: '0 !important',
            marginTop: '0 !important'
          },
          '& .fc-scrollgrid-section-header > *': {
            marginTop: '0 !important',
            paddingTop: '0 !important'
          },
          '& .fc-col-header': {
            marginTop: '0 !important',
            paddingTop: '0 !important'
          },
          '& .fc-daygrid-body, & .fc-timegrid-body': {
            marginTop: '0 !important',
            paddingTop: '0 !important'
          },
          '& .fc-scroller-harness': {
            marginTop: '0 !important',
            paddingTop: '0 !important'
          },
          '& .fc-daygrid, & .fc-timegrid': {
            marginTop: '0 !important'
          },
          '& table': {
            marginTop: '0 !important'
          },
          // Enhanced calendar styling
          '& .fc-header-toolbar': {
            flexDirection: { xs: 'column', sm: 'row' },
            gap: { xs: 0.5, sm: 0 },
            padding: { xs: '4px 8px', sm: '6px 12px' },
            backgroundColor: '#f8f9fa',
            borderBottom: '2px solid #e9ecef',
            position: 'sticky',
            top: 0,
            zIndex: 10,
            flexShrink: 0,
            marginBottom: '0 !important',
            paddingBottom: '6px !important'
          },
          '& .fc-header-toolbar .fc-toolbar-chunk': {
            display: 'flex',
            alignItems: 'center',
            gap: { xs: 0.5, sm: 0.75 },
            flexWrap: 'wrap',
            justifyContent: { xs: 'center', sm: 'flex-start' }
          },
          '& .fc-header-toolbar .fc-toolbar-chunk:last-of-type': {
            justifyContent: { xs: 'center', sm: 'flex-end' }
          },
          '& .fc-toolbar-title': {
            fontSize: { xs: '1.25rem', sm: '1.5rem' },
            fontWeight: 600,
            color: '#495057'
          },
          '& .fc-button': {
            padding: { xs: '6px 12px', sm: '8px 16px' },
            fontSize: { xs: '0.8rem', sm: '0.875rem' },
            fontWeight: 500,
            borderRadius: '8px',
            border: 'none',
            boxShadow: '0 2px 4px rgba(0,0,0,0.1)',
            transition: 'all 0.2s ease'
          },
          '& .fc-button:hover': {
            transform: 'translateY(-1px)',
            boxShadow: '0 4px 8px rgba(0,0,0,0.15)'
          },
          '& .fc-button-primary': {
            backgroundColor: '#667eea',
            '&:hover': {
              backgroundColor: '#5a6fd8'
            }
          },
          '& .fc-button-primary:not(:disabled).fc-button-active': {
            backgroundColor: '#4c63d2',
            boxShadow: 'inset 0 2px 4px rgba(0,0,0,0.2)'
          },
          '& .fc-daygrid-day': {
            cursor: 'pointer',
            transition: 'background-color 0.2s ease',
            '&:hover': {
              backgroundColor: 'rgba(102, 126, 234, 0.05)'
            }
          },
          '& .fc-daygrid-day-number': {
            fontWeight: 500,
            padding: '4px',
            borderRadius: '50%',
            transition: 'all 0.2s ease'
          },
          '& .fc-day-today .fc-daygrid-day-number': {
            backgroundColor: '#667eea',
            color: 'white',
            fontWeight: 600
          },
          // TimeGrid column headers - show weekday and day number
          '& .fc-col-header-cell': {
            padding: '4px 4px',
            fontWeight: 600,
            fontSize: '0.875rem'
          },
          '& .fc-col-header-cell-cushion': {
            display: 'flex',
            flexDirection: 'column',
            alignItems: 'center',
            gap: '2px'
          },
          // Today column highlighting in week view
          '& .fc-day-today': {
            backgroundColor: 'rgba(102, 126, 234, 0.08) !important'
          },
          // Base event styles - minimal to let FullCalendar handle positioning
          '& .fc-event': {
            cursor: 'pointer',
            transition: 'all 0.2s ease',
            color: '#0d47a1',
            fontWeight: 400
          },
          // DayGrid and List specific styling - custom padding only for these views
          '& .fc-daygrid-event, & .fc-list-event': {
            borderRadius: '8px',
            padding: { xs: '4px 8px 4px 18px', sm: '6px 10px 6px 20px' },
            fontSize: { xs: '0.65rem', sm: '0.75rem' },
            border: '1px solid #e0e0e0',
            borderLeft: '4px solid transparent',
            boxShadow: '0 2px 4px rgba(0,0,0,0.08)',
            backgroundClip: 'padding-box',
            position: 'relative'
          },
          // TimeGrid events - minimal styling to preserve FullCalendar's height/position calculations
          '& .fc-timegrid-event': {
            borderRadius: '4px',
            fontSize: { xs: '0.65rem', sm: '0.7rem' },
            border: '1px solid #e0e0e0',
            borderLeft: '4px solid transparent',
            padding: '3px 5px' // Minimal padding to avoid interfering with height
          },
          // TimeGrid event time - make it prominent at the top
          '& .fc-timegrid-event .fc-event-time': {
            fontSize: '0.65rem',
            fontWeight: 600,
            display: 'block',
            marginBottom: '2px',
            color: '#0d47a1'
          },
          // TimeGrid event title - allow multi-line content
          '& .fc-timegrid-event .fc-event-title': {
            fontSize: '0.65rem',
            lineHeight: '1.2',
            display: 'block'
          },
          '& .fc-daygrid-event-dot': {
            display: 'none'
          },
          // TimeGrid specific: Let FullCalendar handle event sizing and positioning
          
          // Status identification: Colored left border
          '& .fc-daygrid-event.status-submitted, & .fc-timegrid-event.status-submitted, & .fc-list-event.status-submitted': {
            borderLeftColor: '#f57c00 !important',
            borderLeftWidth: '4px !important'
          },
          '& .fc-daygrid-event.status-approved, & .fc-timegrid-event.status-approved, & .fc-list-event.status-approved': {
            borderLeftColor: '#388e3c !important',
            borderLeftWidth: '4px !important'
          },
          '& .fc-daygrid-event.status-rejected, & .fc-timegrid-event.status-rejected, & .fc-list-event.status-rejected': {
            borderLeftColor: '#d32f2f !important',
            borderLeftWidth: '4px !important'
          },
          '& .fc-daygrid-event.status-closed, & .fc-timegrid-event.status-closed, & .fc-list-event.status-closed': {
            borderLeftColor: '#7b1fa2 !important',
            borderLeftWidth: '4px !important'
          },
          '& .fc-daygrid-event.status-default, & .fc-timegrid-event.status-default, & .fc-list-event.status-default': {
            borderLeftColor: '#90a4ae !important',
            borderLeftWidth: '4px !important'
          },
          // List view: Force white background for ALL entries by default
          '& .fc-list-day': {
            backgroundColor: '#ffffff !important'
          },
          '& .fc-list-event': {
            backgroundColor: '#ffffff !important'
          },
          '& .fc-list-event:hover': {
            backgroundColor: '#f5f5f5 !important'
          },
          // Hover effects - only scale for daygrid and list to avoid layout shifts in timeGrid
          '& .fc-daygrid-event:hover, & .fc-list-event:hover': {
            transform: 'scale(1.02)',
            boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
            zIndex: 10
          },
          // TimeGrid events - subtle hover without scale to preserve alignment
          '& .fc-timegrid-event:hover': {
            boxShadow: '0 2px 8px rgba(0,0,0,0.2)',
            zIndex: 10
          },
          '& .owner-event, & .member-event': {
            '& .fc-event-title, & .fc-event-time': {
              color: '#0d47a1 !important',
              fontWeight: 400
            }
          },
          '& .readonly-event': {
            opacity: 0.6,
            cursor: 'not-allowed !important',
            '&:hover': {
              transform: 'none !important',
              boxShadow: 'none !important'
            }
          }
        }}
      >
        <FullCalendar
          key={`weekFirstDay:${weekFirstDay}`}
          ref={calendarRef}
          plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]}
          initialView="dayGridMonth"
          headerToolbar={{
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listWeek,listMonth'
          }}
          buttonText={{
            today: 'Today',
            dayGridMonth: 'Month',
            timeGridWeek: 'Week',
            listWeek: 'Week List',
            listMonth: 'Month List'
          }}
          events={calendarEvents}
          selectable={!isReadOnlyMode}
          selectMirror={true}
          selectOverlap={true}
          selectConstraint={{
            startTime: '00:00',
            endTime: '24:00',
          }}
          eventDidMount={handleEventDidMount}
          dayCellDidMount={handleDayCellDidMount}
          datesSet={handleViewChange}
          dayMaxEvents={isMobile ? 2 : 3}
          weekends={true}
          select={handleDateSelect}
          eventClick={handleEventClick}
          dateClick={handleDateClick}
          height="100%" // Usar 100% da altura disponível
          locale="en"
          firstDay={
            currentCalendarViewType === 'timeGridWeek' ||
            currentCalendarViewType === 'listWeek' ||
            currentCalendarViewType === 'dayGridMonth' ||
            currentCalendarViewType === 'listMonth'
              ? weekFirstDay
              : undefined
          }
          views={{
            // Manual QA:
            // - EU tenant (week_start monday): Week/ListWeek views are Monday-first.
            // - US tenant (week_start sunday): Week/ListWeek views are Sunday-first.
            // - Month/ListMonth also follow tenant week_start.
            // - DevTools Network: switching to Week triggers GET /api/timesheets/summary?date=YYYY-MM-DD
            //   where date corresponds to the visible first day column.
            dayGridMonth: {
              firstDay: weekFirstDay,
            },
            timeGridWeek: {
              firstDay: weekFirstDay,
            },
            listWeek: {
              firstDay: weekFirstDay,
            },
            listMonth: {
              firstDay: weekFirstDay,
            },
          }}
          weekNumbers={!isMobile}
          // Time grid configurations - Todas as 24 horas
          slotMinTime="00:00:00" // Início: meia-noite
          slotMaxTime="24:00:00" // Fim: meia-noite do dia seguinte (23:59)
          slotDuration="00:30:00" // Intervalo de 30 minutos
          slotLabelInterval="01:00:00" // Mostrar label a cada 1 hora
          slotLabelFormat={{
            hour: '2-digit',
            minute: '2-digit',
            hour12: timePickerAmpm
          }}
          eventTimeFormat={{
            hour: '2-digit',
            minute: '2-digit',
            hour12: timePickerAmpm
          }}
          displayEventTime={true}
          displayEventEnd={true}
          scrollTime="08:00:00"
          scrollTimeReset={false}
          selectAllow={(selectInfo) => {
            if (isReadOnlyMode) {
              return false;
            }
            const start = dayjs(selectInfo.start);
            const end = dayjs(selectInfo.end);
            return start.isSame(end, 'day') || start.add(1, 'second').isSame(end, 'day');
          }}
          // Enhanced mobile-specific configurations
          longPressDelay={isMobile ? 150 : 1000}
          selectLongPressDelay={isMobile ? 150 : 1000}
          eventLongPressDelay={isMobile ? 150 : 1000}
          // Improve touch interactions  
          selectMinDistance={isMobile ? 3 : 0}
          // Ensure events are clickable on all devices
          eventStartEditable={false}
          eventDurationEditable={false}
          // Additional mobile optimizations
          stickyHeaderDates={true} // Headers das datas também sticky
          dayHeaderFormat={
            currentCalendarViewType === 'dayGridMonth'
              ? (isMobile ? { weekday: 'short' } : { weekday: 'long' })
              : (isMobile ? { weekday: 'short', day: 'numeric' } : { weekday: 'long', day: 'numeric' })
          }
          allDaySlot={false} // Remove all-day slot - not used
          nowIndicator={true} // Mostrar linha do horário atual
        />
      </Paper>

      {/* Enhanced Timesheet Entry Dialog */}
      <LocalizationProvider dateAdapter={AdapterDayjs} adapterLocale={getTenantUiLocale(tenantContext)}>
        <Dialog
          open={dialogOpen}
          onClose={handleDialogClose}
          maxWidth="sm"
          fullWidth
          PaperProps={{
            sx: {
              borderRadius: 2,
              mx: { xs: 1, sm: 2 },
              my: { xs: 1, sm: 2 },
              maxHeight: { xs: '98vh', sm: '92vh' },
              maxWidth: { xs: '100%', sm: '510px' },
              overflow: 'hidden'
            }
          }}
        >
          <DialogTitle 
            sx={{ 
              background: selectedEntry 
                ? (selectedEntry.status === 'approved' ? 'linear-gradient(135deg, #4caf50 0%, #388e3c 100%)' :
                   selectedEntry.status === 'rejected' ? 'linear-gradient(135deg, #f44336 0%, #d32f2f 100%)' :
                   selectedEntry.status === 'closed' ? 'linear-gradient(135deg, #9c27b0 0%, #7b1fa2 100%)' :
                   'linear-gradient(135deg, #ff9800 0%, #f57c00 100%)') // submitted or default
                : 'linear-gradient(135deg, #ff9800 0%, #f57c00 100%)', // new entries default to submitted color
              color: 'white',
              p: 1.5,
              position: 'sticky',
              top: 0,
              zIndex: 1
            }}
          >
            <Box sx={{ 
              display: 'flex', 
              justifyContent: 'space-between', 
              alignItems: 'center',
              flexDirection: isMobile ? 'column' : 'row',
              gap: isMobile ? 1 : 0
            }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                {selectedEntry ? <EditIcon /> : <AddIcon />}
                <Typography 
                  variant={isMobile ? "h6" : "h5"} 
                  component="span"
                  sx={{ fontWeight: 600 }}
                >
                  {selectedEntry ? 'Edit Entry' : 'New Entry'}
                </Typography>
              </Box>
              
              <Box sx={{ 
                display: 'flex', 
                gap: 1, 
                alignItems: 'center',
                flexWrap: 'wrap',
                justifyContent: isMobile ? 'center' : 'flex-end'
              }}>
                {selectedEntry && selectedEntry.technician && (
                  <Chip
                    label={isMobile ? selectedEntry.technician.name : `Owner: ${selectedEntry.technician.name}`}
                    color="primary"
                    size="small"
                    variant="filled"
                    sx={{ 
                      bgcolor: 'rgba(255,255,255,0.2)',
                      color: 'white',
                      fontWeight: 500
                    }}
                  />
                )}
                
                {hoursWorked > 0 && (
                  <Chip 
                    label={`${decimalToHHMM(hoursWorked)}`}
                    size="small"
                    variant="filled"
                    icon={<DurationIcon />}
                    sx={{ 
                      bgcolor: 'rgba(255,255,255,0.2)',
                      color: 'white',
                      fontWeight: 500,
                      '& .MuiChip-icon': { color: 'white' }
                    }}
                  />
                )}
                
                {/* AI Suggestions Toggle - Only show for new entries */}
                {!selectedEntry && (
                  <Badge
                    badgeContent={aiSuggestion.suggestion ? '!' : 0}
                    color="error"
                    variant="dot"
                    invisible={!aiSuggestion.suggestion}
                    sx={{
                      '& .MuiBadge-badge': {
                        animation: aiSuggestion.suggestion ? 'pulse 2s infinite' : 'none',
                        '@keyframes pulse': {
                          '0%': {
                            transform: 'scale(1)',
                            opacity: 1,
                          },
                          '50%': {
                            transform: 'scale(1.3)',
                            opacity: 0.8,
                          },
                          '100%': {
                            transform: 'scale(1)',
                            opacity: 1,
                          },
                        },
                      },
                    }}
                  >
                    <IconButton 
                      onClick={toggleAISuggestions}
                      sx={{ 
                        color: 'white',
                        bgcolor: showAISuggestions ? 'rgba(255,255,255,0.2)' : 'transparent',
                        '&:hover': {
                          bgcolor: 'rgba(255,255,255,0.3)'
                        }
                      }}
                      size={isMobile ? "medium" : "small"}
                      title={showAISuggestions ? 'Hide AI Suggestions' : 'Show AI Suggestions'}
                    >
                      <Typography sx={{ fontSize: '1.2rem' }}>🤖</Typography>
                    </IconButton>
                  </Badge>
                )}
                
                <IconButton 
                  onClick={handleDialogClose}
                  sx={{ color: 'white' }}
                  size={isMobile ? "medium" : "small"}
                >
                  <CloseIcon />
                </IconButton>
              </Box>
            </Box>
          </DialogTitle>

          <DialogContent sx={{ p: 1.5, backgroundColor: '#fafafa' }}>
            <Fade in={dialogOpen}>
              <Box component="form" id="timesheet-form" onSubmit={(e) => { e.preventDefault(); handleSave(); }}>
                {/* AI Suggestion Component - Collapsible */}
                {!selectedEntry && showAISuggestions && (
                  <Collapse in={aiSuggestionExpanded || aiSuggestion.suggestion !== null || aiSuggestion.isLoading}>
                    <Box sx={{ mb: 2 }}>
                      <AISuggestionCard
                        suggestion={aiSuggestion.suggestion}
                        isLoading={aiSuggestion.isLoading}
                        isAIAvailable={aiSuggestion.isAIAvailable}
                        error={aiSuggestion.error}
                        onApply={handleApplyAISuggestion}
                        onDismiss={handleDismissAISuggestion}
                        onFeedback={handleAIFeedback}
                      />
                    </Box>
                  </Collapse>
                )}

                <Grid container spacing={1.5}>
                  {/* Worker Selection Row - FIRST */}
                  <Grid item xs={12}>
                    <Paper sx={{ p: 1.5, borderRadius: 2, bgcolor: 'white' }}>
                      <Typography variant="h6" sx={{ mb: 1, display: 'flex', alignItems: 'center', gap: 1, fontSize: '1.1rem' }}>
                        👤 Worker
                      </Typography>
                      
                      <TextField
                        select
                        fullWidth
                        size="small"
                        label="Worker"
                        value={selectedTechnicianId || ''}
                        onChange={(e) => {
                          const newTechId = e.target.value === '' ? '' : parseInt(e.target.value);
                          console.log('Technician manually changed to:', newTechId);
                          setSelectedTechnicianId(newTechId);
                        }}
                        disabled={!userIsManager && !userIsAdmin && availableTechnicians.length === 1}
                        id="timesheet-worker-field"
                      >
                        <MenuItem value="" disabled>
                          Select Worker
                        </MenuItem>
                        {/* Show current timesheet owner even if not in availableTechnicians */}
                        {selectedEntry && selectedEntry.technician && 
                         !availableTechnicians.find(t => t.id === selectedEntry.technician_id) && (
                          <MenuItem key={selectedEntry.technician.id} value={selectedEntry.technician.id}>
                            {selectedEntry.technician.name} (Entry Owner)
                          </MenuItem>
                        )}
                        {availableTechnicians.map((tech) => (
                          <MenuItem key={tech.id} value={tech.id}>
                            {tech.name} {tech.email === user?.email ? '(You)' : ''}
                          </MenuItem>
                        ))}
                      </TextField>
                    </Paper>
                  </Grid>

                  {/* Date and Time Row */}
                  <Grid item xs={12}>
                    <Paper sx={{ p: 1.5, borderRadius: 2, bgcolor: 'white' }}>
                      <Typography variant="h6" sx={{ mb: 1, display: 'flex', alignItems: 'center', gap: 1, fontSize: '1.1rem' }}>
                        <TimeIcon color="primary" />
                        Date & Time
                      </Typography>
                      
                      <Grid container spacing={2}>
                        <Grid item xs={12} sm={4}>
                          <DatePicker
                            label="Date"
                            value={selectedDate}
                            onChange={(newDate) => setSelectedDate(newDate)}
                            format={datePickerFormat}
                            slotProps={{
                              textField: {
                                fullWidth: true,
                                variant: 'outlined',
                                size: 'small'
                              }
                            }}
                          />
                        </Grid>
                        
                        <Grid item xs={6} sm={4}>
                          <TimePicker
                            label="Start Time"
                            value={startTimeObj}
                            onChange={(newTime) => {
                              setStartTimeObj(newTime);
                              // Only auto-adjust end time if it would become invalid (before start time)
                              if (newTime && endTimeObj) {
                                // If new start time is after current end time, adjust end time
                                if (newTime.isAfter(endTimeObj) || newTime.isSame(endTimeObj)) {
                                  const newEndTime = newTime.add(1, 'hour');
                                  setEndTimeObj(newEndTime);
                                }
                                // Otherwise keep the existing end time (user already set it)
                              } else if (newTime && !endTimeObj) {
                                // If no end time set yet, auto-increment by 1 hour
                                const newEndTime = newTime.add(1, 'hour');
                                setEndTimeObj(newEndTime);
                              }
                            }}
                            ampm={timePickerAmpm}
                            format={timePickerFormat}
                            minutesStep={15}
                            slotProps={{
                              textField: {
                                fullWidth: true,
                                variant: 'outlined',
                                size: 'small'
                              }
                            }}
                          />
                        </Grid>
                        
                        <Grid item xs={6} sm={4}>
                          <TimePicker
                            label="End Time"
                            value={endTimeObj}
                            onChange={(newTime) => setEndTimeObj(newTime)}
                            ampm={timePickerAmpm}
                            format={timePickerFormat}
                            minutesStep={15}
                            slotProps={{
                              textField: {
                                fullWidth: true,
                                variant: 'outlined',
                                size: 'small'
                              }
                            }}
                          />
                        </Grid>
                      </Grid>
                    </Paper>
                  </Grid>

                  {/* Project Details Row */}
                  <Grid item xs={12}>
                    <Paper sx={{ p: 1.5, borderRadius: 2, bgcolor: 'white' }}>
                      <Typography variant="h6" sx={{ mb: 1, display: 'flex', alignItems: 'center', gap: 1, fontSize: '1.1rem' }}>
                        <ProjectIcon color="primary" />
                        Project Details
                      </Typography>
                      
                      <Grid container spacing={2}>
                        <Grid item xs={12} sm={6}>
                          <TextField
                            select
                            fullWidth
                            required
                            size="small"
                            label="Project"
                            value={projectId}
                            onChange={(e) => {
                              const value = e.target.value;
                              setProjectId(value === '' ? '' : Number(value));
                              setTaskId(0); // Reset task when project changes
                            }}
                            variant="outlined"
                            id="timesheet-project-field"
                            SelectProps={{
                              renderValue: (value) => {
                                if (!value || value === '') return 'Select a project';
                                const selectedProject = projects.find(p => p.id === value);
                                return selectedProject ? selectedProject.name : 'Select a project';
                              }
                            }}
                          >
                            <MenuItem value="">Select a project</MenuItem>
                            {(projects || []).map((project) => (
                              <MenuItem key={project.id} value={project.id}>
                                <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', width: '100%', gap: 2 }}>
                                  <Typography variant="body2" component="span" sx={{ fontWeight: 500 }}>
                                    {project.name}
                                  </Typography>
                                  {projectRoleMap[project.id] && (
                                    <Box sx={{ display: 'flex', gap: 0.5, alignItems: 'center' }}>
                                      {roleIsAssigned(projectRoleMap[project.id]?.projectRole) && (
                                        <Chip
                                          size="small"
                                          icon={<span style={{ fontSize: '14px' }}>⏱️</span>}
                                          color={projectRoleMap[project.id]?.projectRole === 'manager' ? 'primary' : 'default'}
                                          label={formatRoleLabel(projectRoleMap[project.id]?.projectRole)}
                                          sx={{ 
                                            height: '22px',
                                            '& .MuiChip-label': { px: 1, fontSize: '0.75rem' }
                                          }}
                                        />
                                      )}
                                      {roleIsAssigned(projectRoleMap[project.id]?.expenseRole) && (
                                        <Chip
                                          size="small"
                                          icon={<span style={{ fontSize: '14px' }}>💰</span>}
                                          color={projectRoleMap[project.id]?.expenseRole === 'manager' ? 'warning' : 'default'}
                                          label={formatRoleLabel(projectRoleMap[project.id]?.expenseRole)}
                                          sx={{ 
                                            height: '22px',
                                            '& .MuiChip-label': { px: 1, fontSize: '0.75rem' }
                                          }}
                                        />
                                      )}
                                    </Box>
                                  )}
                                </Box>
                              </MenuItem>
                            ))}
                          </TextField>
                        </Grid>
                        
                        <Grid item xs={12} sm={6}>
                          <TextField
                            select
                            fullWidth
                            required
                            size="small"
                            label="Task"
                            value={taskId || 0}
                            onChange={(e) => setTaskId(Number(e.target.value))}
                            variant="outlined"
                            disabled={!projectId}
                            id="timesheet-task-field"
                            SelectProps={{
                              renderValue: (value) => {
                                if (!value || value === 0) return 'Select a task';
                                const selectedTask = filteredTasks.find(t => t.id === value);
                                return selectedTask ? selectedTask.name : 'Select a task';
                              }
                            }}
                          >
                            <MenuItem value={0}>Select a task</MenuItem>
                            {(filteredTasks || []).map((task) => (
                              <MenuItem key={task.id} value={task.id}>
                                {task.name}
                              </MenuItem>
                            ))}
                          </TextField>
                        </Grid>
                        
                        <Grid item xs={12}>
                          <TextField
                            select
                            fullWidth
                            required
                            size="small"
                            label="Location"
                            value={locationId}
                            onChange={(e) => setLocationId(e.target.value)}
                            variant="outlined"
                            id="timesheet-location-field"
                            InputProps={{
                              startAdornment: <LocationIcon color="action" sx={{ mr: 1 }} />
                            }}
                          >
                            <MenuItem value="">Select a location</MenuItem>
                            {(filteredLocations || []).map((location) => {
                              const latitude = Number(location.latitude);
                              const longitude = Number(location.longitude);
                              const hasCoordinates = !Number.isNaN(latitude) && !Number.isNaN(longitude);

                              return (
                                <MenuItem key={location.id} value={location.id.toString()}>
                                  <Box sx={{ display: 'flex', flexDirection: 'column' }}>
                                    <Typography variant="body2" component="span">
                                      {location.name}
                                    </Typography>
                                    <Typography variant="caption" color="text.secondary">
                                      {[location.city, location.country].filter(Boolean).join(', ')}
                                      {hasCoordinates && (
                                        <> — {latitude.toFixed(4)}, {longitude.toFixed(4)}</>
                                      )}
                                    </Typography>
                                  </Box>
                                </MenuItem>
                              );
                            })}
                          </TextField>
                        </Grid>
                      </Grid>
                    </Paper>
                  </Grid>

                  {/* Description Row */}
                  <Grid item xs={12}>
                    <Paper sx={{ p: 1.5, borderRadius: 2, bgcolor: 'white' }}>
                      <Typography variant="h6" sx={{ mb: 1, display: 'flex', alignItems: 'center', gap: 1, fontSize: '1.1rem' }}>
                        <TaskIcon color="primary" />
                        Description
                      </Typography>
                      
                      <TextField
                        fullWidth
                        multiline
                        required
                        size="small"
                        minRows={1}
                        maxRows={4}
                        label="Work Description"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        placeholder="Describe the work performed..."
                        variant="outlined"
                        helperText={description.length ? `${description.length} characters` : ''}
                        id="timesheet-description-field"
                        sx={{
                          '& .MuiInputBase-root': {
                            resize: 'vertical',
                            overflow: 'auto',
                            minHeight: '40px'
                          }
                        }}
                      />
                    </Paper>
                  </Grid>
                </Grid>
              </Box>
            </Fade>
          </DialogContent>

          <DialogActions sx={{ p: 1.5, bgcolor: '#f5f5f5', gap: 1 }}>
            <Button
              onClick={handleDialogClose}
              variant="outlined"
              size="small"
              sx={{ minWidth: 80 }}
            >
              Cancel
            </Button>
            {selectedEntry && (
              <Button
                onClick={handleDelete}
                color="error"
                variant="outlined"
                size="small"
                disabled={loading || isReadOnlyMode || (selectedEntry.status === 'approved')}
                sx={{ minWidth: 80 }}
              >
                Delete
              </Button>
            )}
            <Button
              type="submit"
              form="timesheet-form"
              variant="contained"
              size="small"
              disabled={loading || isReadOnlyMode}
              startIcon={loading ? null : <SaveIcon />}
              sx={{ 
                minWidth: 90,
                background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                '&:hover': {
                  background: 'linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%)'
                },
                '&:disabled': {
                  background: '#ccc',
                  color: '#888'
                }
              }}
            >
              {loading ? 'Saving...' : 'SAVE'}
            </Button>
          </DialogActions>
        </Dialog>

        {/* Travel Details Dialog */}
        <Dialog
          open={travelDetailsOpen}
          onClose={() => setTravelDetailsOpen(false)}
          maxWidth="md"
          fullWidth
        >
          <DialogTitle>
            Travel Details - {selectedTravelDate ? formatTenantDate(selectedTravelDate, tenantContext) : ''}
          </DialogTitle>
          <DialogContent>
            <Box sx={{ mt: 2 }}>
              {selectedTravels.length === 0 ? (
                <Typography>No travels found for this date.</Typography>
              ) : (
                selectedTravels.map((travel) => (
                  <Box
                    key={travel.id}
                    sx={{
                      mb: 2,
                      p: 2,
                      border: '1px solid #e0e0e0',
                      borderRadius: 1,
                      bgcolor: '#f9f9f9'
                    }}
                  >
                    <Grid container spacing={2}>
                      <Grid item xs={12} sm={6}>
                        <Typography variant="subtitle2" color="text.secondary">
                          Technician
                        </Typography>
                        <Typography variant="body1">
                          {travel.technician?.name || 'Unknown'}
                        </Typography>
                      </Grid>
                      <Grid item xs={12} sm={6}>
                        <Typography variant="subtitle2" color="text.secondary">
                          Project
                        </Typography>
                        <Typography variant="body1">
                          {travel.project?.name || 'Unknown'}
                        </Typography>
                      </Grid>
                      <Grid item xs={12} sm={6}>
                        <Typography variant="subtitle2" color="text.secondary">
                          Departure
                        </Typography>
                        <Typography variant="body1">
                          {travel.start_at 
                            ? formatTenantDateTime(travel.start_at, tenantContext)
                            : travel.travel_date 
                              ? formatTenantDate(travel.travel_date, tenantContext)
                              : 'N/A'}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {travel.origin_city ? `${travel.origin_city}, ` : ''}{travel.origin_country}
                        </Typography>
                      </Grid>
                      <Grid item xs={12} sm={6}>
                        <Typography variant="subtitle2" color="text.secondary">
                          Arrival
                        </Typography>
                        <Typography variant="body1">
                          {travel.end_at 
                            ? formatTenantDateTime(travel.end_at, tenantContext)
                            : 'N/A'}
                        </Typography>
                        <Typography variant="caption" color="text.secondary">
                          {travel.destination_city ? `${travel.destination_city}, ` : ''}{travel.destination_country}
                        </Typography>
                      </Grid>
                      {travel.duration_minutes && travel.duration_minutes > 0 && (
                        <Grid item xs={12} sm={6}>
                          <Typography variant="subtitle2" color="text.secondary">
                            Duration
                          </Typography>
                          <Typography variant="body1">
                            {Math.floor(travel.duration_minutes / 60)}h {travel.duration_minutes % 60}min
                          </Typography>
                        </Grid>
                      )}
                      <Grid item xs={12} sm={6}>
                        <Typography variant="subtitle2" color="text.secondary">
                          Direction
                        </Typography>
                        <Typography variant="body1">
                          {travel.direction?.replace(/_/g, ' ').toUpperCase() || 'OTHER'}
                        </Typography>
                      </Grid>
                      <Grid item xs={12} sm={6}>
                        <Typography variant="subtitle2" color="text.secondary">
                          Status
                        </Typography>
                        <Chip 
                          label={travel.status?.toUpperCase() || 'UNKNOWN'}
                          size="small"
                          color={
                            travel.status === 'completed' ? 'success' : 
                            travel.status === 'cancelled' ? 'default' : 
                            'warning'
                          }
                        />
                      </Grid>
                      {travel.classification_reason && (
                        <Grid item xs={12}>
                          <Typography variant="subtitle2" color="text.secondary">
                            Classification Reason
                          </Typography>
                          <Typography variant="body2">
                            {travel.classification_reason}
                          </Typography>
                        </Grid>
                      )}
                    </Grid>
                  </Box>
                ))
              )}
            </Box>
          </DialogContent>
          <DialogActions>
            <Button onClick={() => setTravelDetailsOpen(false)} variant="outlined">
              Close
            </Button>
          </DialogActions>
        </Dialog>

        <ConfirmationDialog
          open={confirmDialog.open}
          title={confirmDialog.title}
          message={confirmDialog.message}
          recordDetails={confirmDialog.recordDetails}
          confirmText="Delete"
          cancelText="Cancel"
          confirmColor="error"
          onConfirm={confirmDialog.action}
          onCancel={() => setConfirmDialog({ ...confirmDialog, open: false })}
        />
      </LocalizationProvider>
    </Box>
  );
};

export default TimesheetCalendar;
