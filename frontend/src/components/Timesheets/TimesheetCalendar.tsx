import React, { useState, useEffect } from 'react';
import FullCalendar from '@fullcalendar/react';
import dayGridPlugin from '@fullcalendar/daygrid';
import timeGridPlugin from '@fullcalendar/timegrid';
import listPlugin from '@fullcalendar/list';
import interactionPlugin from '@fullcalendar/interaction';
import { timesheetsApi, projectsApi, tasksApi, locationsApi } from '../../services/api';
import type { Project, Timesheet, Task, Location } from '../../types';
import { useAuth } from '../Auth/AuthContext';

// API Response types
interface ApiResponse<T> {
  data: T[];
  user_permissions: Record<string, boolean>;
}

type TimesheetApiResponse = Timesheet[] | ApiResponse<Timesheet>;
type ProjectApiResponse = Project[] | ApiResponse<Project>;
type TaskApiResponse = Task[] | ApiResponse<Task>;
type LocationApiResponse = Location[] | ApiResponse<Location>;
import {
  Box,
  Typography,
  Button,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  TextField,
  MenuItem,
  Alert,
  Card,
  CardContent,
  Chip,
  IconButton,
  Grid,
  Paper,
  Fade,
  useTheme,
  useMediaQuery,
  Avatar
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
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { TimePicker } from '@mui/x-date-pickers/TimePicker';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs';
import dayjs, { Dayjs } from 'dayjs';
import 'dayjs/locale/en';
import localizedFormat from 'dayjs/plugin/localizedFormat';
import customParseFormat from 'dayjs/plugin/customParseFormat';
import type { DateSelectArg, EventClickArg } from '@fullcalendar/core';

import { useTimesheetAISuggestion } from '../../hooks/useTimesheetAISuggestion';
import AISuggestionCard from '../AI/AISuggestionCard';


// Configure dayjs plugins and locale
dayjs.extend(localizedFormat);
dayjs.extend(customParseFormat);
dayjs.locale('en');

// Custom date formatter to ensure English format
const formatDate = (date: Dayjs | null): string => {
  if (!date) return '';
  return date.format('YYYY-MM-DD');
};

const timeToString = (time: Dayjs | null): string => {
  if (!time) return '';
  return time.format('HH:mm');
};





interface TimesheetCalendarProps {}

const TimesheetCalendar: React.FC<TimesheetCalendarProps> = () => {
  const { user, isManager, isTechnician, isAdmin, canValidateTimesheets, hasPermission } = useAuth();
  const theme = useTheme();
  const isMobile = useMediaQuery(theme.breakpoints.down('sm'));
  
  // State variables
  const [timesheets, setTimesheets] = useState<Timesheet[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [loading, setLoading] = useState(false);
  const [error, setError] = useState('');
  const [dialogOpen, setDialogOpen] = useState(false);
  const [selectedEntry, setSelectedEntry] = useState<Timesheet | null>(null);
  
  // Form state
  const [selectedDate, setSelectedDate] = useState<Dayjs | null>(null);
  const [projectId, setProjectId] = useState<number>(0);
  const [taskId, setTaskId] = useState<number>(0);
  const [locationId, setLocationId] = useState<number>(0);
  const [hoursWorked, setHoursWorked] = useState<number>(0);
  const [description, setDescription] = useState('');
  const [startTimeObj, setStartTimeObj] = useState<Dayjs | null>(dayjs().hour(9).minute(0).second(0));
  const [endTimeObj, setEndTimeObj] = useState<Dayjs | null>(dayjs().hour(17).minute(30).second(0));

  // AI Suggestion Hook
  const aiSuggestion = useTimesheetAISuggestion();

  // Load initial data
  useEffect(() => {
    loadTimesheets();
    loadProjects();
    loadTasks();
    loadLocations();
  }, []);

  const loadTimesheets = async () => {
    try {
      setLoading(true);
      const response: TimesheetApiResponse = await timesheetsApi.getAll();
      console.log('Loaded timesheets:', response);
      // A nova API retorna { data: [...], user_permissions: {...} }
      const timesheetsData = Array.isArray(response) 
        ? response 
        : (response as ApiResponse<Timesheet>).data || [];
      setTimesheets(timesheetsData);
    } catch (error) {
      console.error('Error loading timesheets:', error);
      setError('Failed to load timesheets');
    } finally {
      setLoading(false);
    }
  };

  const loadProjects = async () => {
    try {
      const data: ProjectApiResponse = await projectsApi.getAll();
      console.log('Loaded projects:', data);
      // Handle both direct array and wrapped response formats
      const projectsArray = Array.isArray(data) 
        ? data 
        : (data as ApiResponse<Project>).data || [];
      setProjects(projectsArray);
    } catch (error) {
      console.error('Error loading projects:', error);
      setError('Failed to load projects');
    }
  };

  const loadTasks = async () => {
    try {
      const data: TaskApiResponse = await tasksApi.getAll();
      console.log('Loaded tasks:', data);
      // Handle both direct array and wrapped response formats
      const tasksArray = Array.isArray(data) 
        ? data 
        : (data as ApiResponse<Task>).data || [];
      setTasks(tasksArray);
    } catch (error) {
      console.error('Error loading tasks:', error);
      setError('Failed to load tasks');
    }
  };

  const loadLocations = async () => {
    try {
      const data: LocationApiResponse = await locationsApi.getAll();
      console.log('Loaded locations:', data);
      // Handle both direct array and wrapped response formats
      const locationsArray = Array.isArray(data) 
        ? data 
        : (data as ApiResponse<Location>).data || [];
      setLocations(locationsArray);
    } catch (error) {
      console.error('Error loading locations:', error);
      setError('Failed to load locations');
    }
  };

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
  const handleApplyAISuggestion = () => {
    if (aiSuggestion.suggestion) {
      console.log('Applying AI suggestion:', aiSuggestion.suggestion);
      
      // Apply suggested hours
      setHoursWorked(aiSuggestion.suggestion.suggested_hours);
      
      // Apply suggested description
      setDescription(aiSuggestion.suggestion.suggested_description);
      
      // If hours are provided, calculate reasonable start/end times
      const suggestedHours = aiSuggestion.suggestion.suggested_hours;
      const startTime = dayjs().hour(9).minute(0); // Start at 9 AM
      const endTime = startTime.add(suggestedHours, 'hour');
      
      setStartTimeObj(startTime);
      setEndTimeObj(endTime);
      
      aiSuggestion.applySuggestion();
    }
  };

  const handleDismissAISuggestion = () => {
    console.log('Dismissing AI suggestion');
    aiSuggestion.dismissSuggestion();
  };

  const handleAIFeedback = (accepted: boolean) => {
    console.log('AI feedback:', accepted ? 'accepted' : 'rejected');
    aiSuggestion.provideFeedback(accepted);
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
      const location = locations.find(l => l.id === locationId);
      
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
    console.log('Date selected:', selectInfo.startStr);
    setSelectedDate(dayjs(selectInfo.startStr));
    setSelectedEntry(null);
    resetForm();
    setDialogOpen(true);
  };

  const handleEventClick = (clickInfo: EventClickArg) => {
    const timesheetId = parseInt(clickInfo.event.id);
    console.log('Event clicked:', timesheetId);
    
    const timesheet = timesheets.find(t => t.id === timesheetId);
    if (timesheet) {
      // Check if user can edit this entry
      if (timesheet.technician && user?.email !== timesheet.technician.email && user?.role !== 'Manager') {
        console.log('User cannot edit this entry - not owner and not manager');
        return; // Don't open dialog for entries the user cannot edit
      }
      
      console.log('Opening timesheet for editing:', timesheet);
      setSelectedEntry(timesheet);
      setSelectedDate(dayjs(timesheet.date));
      setProjectId(timesheet.project_id);
      setTaskId(timesheet.task_id || 0);
      setLocationId(timesheet.location_id || 0);
      setHoursWorked(timesheet.hours_worked);
      setDescription(timesheet.description || '');
      
      // Set start and end times if available
      if (timesheet.start_time) {
        setStartTimeObj(dayjs(`2023-01-01 ${timesheet.start_time}`));
      }
      if (timesheet.end_time) {
        setEndTimeObj(dayjs(`2023-01-01 ${timesheet.end_time}`));
      }
      
      setDialogOpen(true);
    }
  };

  const resetForm = () => {
    setProjectId(0);
    setTaskId(0);
    setLocationId(0);
    setHoursWorked(0);
    setDescription('');
    // Set default working hours (9:00 AM to 5:30 PM)
    const defaultStart = dayjs().hour(9).minute(0).second(0);
    const defaultEnd = dayjs().hour(17).minute(30).second(0);
    setStartTimeObj(defaultStart);
    setEndTimeObj(defaultEnd);
    setError('');
  };

  const handleSave = async () => {
    if (!projectId) {
      setError('Please select a project');
      return;
    }
    
    if (!taskId) {
      setError('Please select a task');
      return;
    }
    
    if (!locationId) {
      setError('Please select a location');
      return;
    }
    
    if (!selectedDate) {
      setError('Please select a date');
      return;
    }
    
    if (!startTimeObj || !endTimeObj) {
      setError('Please set start and end times');
      return;
    }
    
    if (hoursWorked <= 0) {
      setError('Hours worked must be greater than 0');
      return;
    }
    
    if (hoursWorked > 24) {
      setError('Hours worked cannot exceed 24 hours');
      return;
    }

    try {
      setLoading(true);
      setError('');

      const timesheet = {
        project_id: projectId,
        task_id: taskId,
        location_id: locationId,
        date: formatDate(selectedDate),
        hours_worked: hoursWorked,
        description: description.trim(),
        start_time: timeToString(startTimeObj),
        end_time: timeToString(endTimeObj)
      };

      console.log('Saving timesheet:', timesheet);

      if (selectedEntry) {
        // Update existing timesheet
        await timesheetsApi.update(selectedEntry.id, timesheet);
      } else {
        // Create new timesheet
        await timesheetsApi.create(timesheet);
      }

      console.log('Timesheet saved successfully');
      await loadTimesheets(); // Reload data
      setDialogOpen(false);
      resetForm();
      
      // Provide feedback to AI if suggestion was used
      if (!selectedEntry && aiSuggestion.suggestion) {
        handleAIFeedback(true); // Successful save indicates good suggestion
      }
    } catch (error) {
      console.error('Error saving timesheet:', error);
      setError(error instanceof Error ? error.message : 'Failed to save timesheet');
      
      // Provide negative feedback to AI if suggestion was used
      if (!selectedEntry && aiSuggestion.suggestion) {
        handleAIFeedback(false);
      }
    } finally {
      setLoading(false);
    }
  };

  const handleDelete = async () => {
    if (!selectedEntry) return;

    try {
      setLoading(true);
      await timesheetsApi.delete(selectedEntry.id);

      if (true) {
        console.log('Timesheet deleted successfully');
        await loadTimesheets();
        setDialogOpen(false);
        resetForm();
      } else {
        throw new Error('Failed to delete timesheet');
      }
    } catch (error) {
      console.error('Error deleting timesheet:', error);
      setError('Failed to delete timesheet');
    } finally {
      setLoading(false);
    }
  };

  // Filter tasks for selected project
  const filteredTasks = (tasks || []).filter(task => task.project_id === projectId);

  // Generate calendar events from timesheets
  const calendarEvents = (timesheets || []).map((timesheet) => {
    const isOwner = timesheet.technician && user?.email === timesheet.technician.email;
    const canEdit = isOwner || user?.role === 'Manager';
    
    let eventData: any = {
      id: timesheet.id.toString(),
      title: `${timesheet.project?.name || 'Project'} - ${timesheet.hours_worked}h`,
      date: timesheet.date,
      backgroundColor: 
        timesheet.status === 'approved' ? '#4caf50' :
        timesheet.status === 'rejected' ? '#f44336' :
        timesheet.status === 'submitted' ? '#ff9800' : '#2196f3',
      borderColor: 
        timesheet.status === 'approved' ? '#388e3c' :
        timesheet.status === 'rejected' ? '#d32f2f' :
        timesheet.status === 'submitted' ? '#f57c00' : '#1976d2',
      textColor: '#ffffff',
      extendedProps: {
        technician: timesheet.technician,
        project: timesheet.project,
        task: timesheet.task,
        location: timesheet.location,
        hours_worked: timesheet.hours_worked,
        description: timesheet.description,
        status: timesheet.status,
        start_time: timesheet.start_time,
        end_time: timesheet.end_time,
        isOwner: isOwner,
        canEdit: canEdit
      },
      className: canEdit ? 'editable-event' : 'readonly-event'
    };

    // If we have start and end times, use them for time display
    if (timesheet.start_time && timesheet.end_time) {
      const startTime = dayjs(`${timesheet.date} ${timesheet.start_time}`);
      const endTime = dayjs(`${timesheet.date} ${timesheet.end_time}`);
      
      // Validate the dates before using toISOString
      if (startTime.isValid() && endTime.isValid()) {
        eventData.start = startTime.toISOString();
        eventData.end = endTime.toISOString();
      } else {
        // Fallback to all-day event
        const eventDate = dayjs(timesheet.date);
        if (eventDate.isValid()) {
          eventData.start = eventDate.format('YYYY-MM-DD');
          eventData.allDay = true;
        } else {
          console.error('Invalid date:', timesheet.date);
          return null; // Skip this event
        }
      }
    } else {
      // All-day event
      const eventDate = dayjs(timesheet.date);
      if (eventDate.isValid()) {
        eventData.start = eventDate.format('YYYY-MM-DD');
        eventData.allDay = true;
      } else {
        console.error('Invalid date:', timesheet.date);
        return null; // Skip this event
      }
    }

    return eventData;
  }).filter(event => event !== null); // Filter out null events

  return (
    <Box sx={{ 
      p: { xs: 1, sm: 2 },
      width: '100%',
      maxWidth: '100%'
    }}>
      {/* Header Card with Status Legend */}
      <Card 
        sx={{ 
          mb: 3,
          background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
          color: 'white',
          borderRadius: 3,
          boxShadow: '0 8px 32px rgba(0,0,0,0.12)'
        }}
      >
        <CardContent sx={{ p: { xs: 2, sm: 3 } }}>
          {/* Primeira linha: Título à esquerda e legendas à direita */}
          <Box 
            sx={{ 
              display: 'flex',
              justifyContent: 'space-between',
              alignItems: 'center',
              mb: 1,
              flexWrap: { xs: 'wrap', sm: 'nowrap' },
              gap: 2
            }}
          >
            <Typography 
              variant="h5" 
              component="h2" 
              sx={{ 
                fontWeight: 700,
                fontSize: { xs: '1.5rem', sm: '1.75rem' },
                flexShrink: 0,
                display: 'flex',
                alignItems: 'center',
                gap: 1
              }}
            >
              Timesheet
              {/* Demonstração prática das funções de role */}
              {isManager() && (
                <Chip 
                  size="small" 
                  label="Manager" 
                  color="primary" 
                  sx={{ ml: 1 }} 
                />
              )}
              {isTechnician() && (
                <Chip 
                  size="small" 
                  label="Technician" 
                  color="secondary" 
                  sx={{ ml: 1 }} 
                />
              )}
              {isAdmin() && (
                <Chip 
                  size="small" 
                  label="Admin" 
                  color="error" 
                  sx={{ ml: 1 }} 
                />
              )}
            </Typography>
            
            <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 1, justifyContent: { xs: 'flex-start', sm: 'flex-end' } }}>
              <Chip 
                icon={<Avatar sx={{ bgcolor: '#ff9800 !important', width: 16, height: 16 }}>●</Avatar>} 
                label="Submitted" 
                variant="filled"
                size="small"
                sx={{ bgcolor: 'rgba(255,255,255,0.2)', color: 'white' }}
              />
              <Chip 
                icon={<Avatar sx={{ bgcolor: '#4caf50 !important', width: 16, height: 16 }}>●</Avatar>} 
                label="Approved" 
                variant="filled"
                size="small"
                sx={{ bgcolor: 'rgba(255,255,255,0.2)', color: 'white' }}
              />
              <Chip 
                icon={<Avatar sx={{ bgcolor: '#f44336 !important', width: 16, height: 16 }}>●</Avatar>} 
                label="Rejected" 
                variant="filled"
                size="small"
                sx={{ bgcolor: 'rgba(255,255,255,0.2)', color: 'white' }}
              />
              <Chip 
                icon={<Avatar sx={{ bgcolor: '#9c27b0 !important', width: 16, height: 16 }}>●</Avatar>} 
                label="Closed" 
                variant="filled"
                size="small"
                sx={{ bgcolor: 'rgba(255,255,255,0.2)', color: 'white' }}
              />
            </Box>

            {/* Demonstração prática da função hasPermission() */}
            {hasPermission('manage-users') && (
              <Box sx={{ mt: 1, p: 1, bgcolor: 'rgba(255,255,255,0.1)', borderRadius: 1 }}>
                <Typography variant="caption" sx={{ color: 'white', opacity: 0.9 }}>
                  🛠️ Admin Panel: User management features available
                </Typography>
              </Box>
            )}
          </Box>
          
          {/* Segunda linha: Instruções à esquerda */}
          <Typography 
            variant="body2" 
            sx={{ 
              opacity: 0.9,
              fontSize: { xs: '0.875rem', sm: '1rem' },
              mb: 1
            }}
          >
            Click on a date to create a new entry. Click on your entries to edit them.
            {user?.role === 'Manager' && ' As a manager, you can edit all entries.'}
          </Typography>
        </CardContent>
      </Card>

      {/* Calendar Container */}
      <Paper 
        elevation={0}
        sx={{ 
          borderRadius: 3,
          overflow: 'hidden',
          border: '1px solid',
          borderColor: 'grey.200',
          '& .fc': {
            height: { xs: '500px', sm: '600px', md: '700px' },
            width: '100%',
            fontFamily: theme.typography.fontFamily
          },
          // Enhanced calendar styling
          '& .fc-header-toolbar': {
            flexDirection: { xs: 'column', sm: 'row' },
            gap: { xs: 1, sm: 0 },
            padding: { xs: '12px', sm: '16px' },
            backgroundColor: '#f8f9fa',
            borderBottom: '2px solid #e9ecef'
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
          '& .fc-event': {
            borderRadius: '6px',
            padding: '2px 6px',
            fontSize: { xs: '0.7rem', sm: '0.8rem' },
            fontWeight: 500,
            cursor: 'pointer',
            transition: 'all 0.2s ease',
            border: 'none !important',
            boxShadow: '0 2px 4px rgba(0,0,0,0.1)'
          },
          '& .fc-event:hover': {
            transform: 'scale(1.02)',
            boxShadow: '0 4px 12px rgba(0,0,0,0.15)',
            zIndex: 10
          },
          '& .readonly-event': {
            opacity: 0.6,
            cursor: 'not-allowed !important',
            '&:hover': {
              transform: 'none !important'
            }
          }
        }}
      >
        <FullCalendar
          plugins={[dayGridPlugin, timeGridPlugin, listPlugin, interactionPlugin]}
          initialView="dayGridMonth"
          headerToolbar={{
            left: 'prev,next today',
            center: 'title',
            right: isMobile ? 'dayGridMonth,listWeek' : 'dayGridMonth,timeGridWeek,listWeek,listMonth'
          }}
          events={calendarEvents}
          selectable={true}
          selectMirror={true}
          dayMaxEvents={isMobile ? 2 : 3}
          weekends={true}
          select={handleDateSelect}
          eventClick={handleEventClick}
          height="auto"
          locale="en"
          firstDay={1}
          weekNumbers={!isMobile}
        />
      </Paper>

      {/* Enhanced Timesheet Entry Dialog */}
      <LocalizationProvider dateAdapter={AdapterDayjs} adapterLocale="en">
        <Dialog 
          open={dialogOpen} 
          onClose={() => setDialogOpen(false)} 
          maxWidth="md" 
          fullWidth
          PaperProps={{
            sx: {
              borderRadius: 3,
              mx: { xs: 1, sm: 2 },
              my: { xs: 1, sm: 2 },
              maxHeight: { xs: '95vh', sm: '90vh' },
              overflow: 'hidden'
            }
          }}
        >
          <DialogTitle 
            sx={{ 
              background: selectedEntry 
                ? 'linear-gradient(135deg, #ff9800 0%, #f57c00 100%)'
                : 'linear-gradient(135deg, #4caf50 0%, #388e3c 100%)',
              color: 'white',
              p: 3
            }}
          >
            <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                {selectedEntry ? <EditIcon /> : <AddIcon />}
                <Typography 
                  variant="h6" 
                  component="span"
                  sx={{ fontWeight: 600 }}
                >
                  {selectedEntry ? 'Edit Entry' : 'New Entry'}
                </Typography>
              </Box>
              
              <Box sx={{ display: 'flex', gap: 1, alignItems: 'center' }}>
                {selectedEntry && selectedEntry.technician && (
                  <Chip
                    label={`Owner: ${selectedEntry.technician.name}`}
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
                
                {selectedEntry && (
                  <Chip
                    label={selectedEntry.status.charAt(0).toUpperCase() + selectedEntry.status.slice(1)}
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
                    label={`${hoursWorked}h`}
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
                
                <IconButton 
                  onClick={() => setDialogOpen(false)}
                  sx={{ color: 'white' }}
                  size="small"
                >
                  <CloseIcon />
                </IconButton>
              </Box>
            </Box>
          </DialogTitle>

          <DialogContent sx={{ p: 3, backgroundColor: '#fafafa' }}>
            <Fade in={dialogOpen}>
              <Box>
                {error && (
                  <Alert 
                    severity="error" 
                    sx={{ mb: 3, borderRadius: 2 }}
                    onClose={() => setError('')}
                  >
                    {error}
                  </Alert>
                )}
                
                {/* AI Suggestion Component */}
                {!selectedEntry && (
                  <Box sx={{ mb: 3 }}>
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
                )}

                <Grid container spacing={3}>
                  {/* Date and Time Row */}
                  <Grid item xs={12}>
                    <Paper sx={{ p: 3, borderRadius: 2, bgcolor: 'white' }}>
                      <Typography variant="h6" sx={{ mb: 2, display: 'flex', alignItems: 'center', gap: 1 }}>
                        <TimeIcon color="primary" />
                        Date & Time
                      </Typography>
                      
                      <Grid container spacing={2}>
                        <Grid item xs={12} sm={4}>
                          <DatePicker
                            label="Date"
                            value={selectedDate}
                            onChange={(newDate) => setSelectedDate(newDate)}
                            format="DD/MM/YYYY"
                            slotProps={{
                              textField: {
                                fullWidth: true,
                                variant: 'outlined'
                              }
                            }}
                          />
                        </Grid>
                        
                        <Grid item xs={6} sm={3}>
                          <TimePicker
                            label="Start Time"
                            value={startTimeObj}
                            onChange={(newTime) => {
                              setStartTimeObj(newTime);
                              if (newTime) {
                                const newEndTime = newTime.add(1, 'hour'); // Increment by 1 hour
                                setEndTimeObj(newEndTime);
                              }
                            }}
                            ampm={false}
                            format="HH:mm"
                            minutesStep={15}
                            slotProps={{
                              textField: {
                                fullWidth: true,
                                variant: 'outlined'
                              }
                            }}
                          />
                        </Grid>
                        
                        <Grid item xs={6} sm={3}>
                          <TimePicker
                            label="End Time"
                            value={endTimeObj}
                            onChange={(newTime) => setEndTimeObj(newTime)}
                            ampm={false}
                            format="HH:mm"
                            minutesStep={15}
                            slotProps={{
                              textField: {
                                fullWidth: true,
                                variant: 'outlined'
                              }
                            }}
                          />
                        </Grid>
                        
                        <Grid item xs={12} sm={2}>
                          <TextField
                            fullWidth
                            label="Duration"
                            value={`${hoursWorked}h`}
                            InputProps={{
                              readOnly: true,
                              endAdornment: <DurationIcon color="action" />
                            }}
                            sx={{
                              '& .MuiInputBase-input': {
                                fontWeight: 600,
                                fontSize: '1.1rem',
                                textAlign: 'center',
                                color: hoursWorked > 0 ? 'success.main' : 'text.secondary'
                              }
                            }}
                          />
                        </Grid>
                      </Grid>
                    </Paper>
                  </Grid>

                  {/* Project Details Row */}
                  <Grid item xs={12}>
                    <Paper sx={{ p: 3, borderRadius: 2, bgcolor: 'white' }}>
                      <Typography variant="h6" sx={{ mb: 2, display: 'flex', alignItems: 'center', gap: 1 }}>
                        <ProjectIcon color="primary" />
                        Project Details
                      </Typography>
                      
                      <Grid container spacing={2}>
                        <Grid item xs={12} sm={6}>
                          <TextField
                            select
                            fullWidth
                            label="Project"
                            value={projectId}
                            onChange={(e) => {
                              const newProjectId = Number(e.target.value);
                              setProjectId(newProjectId);
                              setTaskId(0); // Reset task when project changes
                            }}
                            variant="outlined"
                          >
                            <MenuItem value={0}>Select a project</MenuItem>
                            {(projects || []).map((project) => (
                              <MenuItem key={project.id} value={project.id}>
                                {project.name}
                              </MenuItem>
                            ))}
                          </TextField>
                        </Grid>
                        
                        <Grid item xs={12} sm={6}>
                          <TextField
                            select
                            fullWidth
                            label="Task"
                            value={taskId}
                            onChange={(e) => setTaskId(Number(e.target.value))}
                            variant="outlined"
                            disabled={!projectId}
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
                            label="Location"
                            value={locationId}
                            onChange={(e) => setLocationId(Number(e.target.value))}
                            variant="outlined"
                            InputProps={{
                              startAdornment: <LocationIcon color="action" sx={{ mr: 1 }} />
                            }}
                          >
                            <MenuItem value={0}>Select a location</MenuItem>
                            {(locations || []).map((location) => (
                              <MenuItem key={location.id} value={location.id}>
                                {location.name}
                              </MenuItem>
                            ))}
                          </TextField>
                        </Grid>
                      </Grid>
                    </Paper>
                  </Grid>

                  {/* Description Row */}
                  <Grid item xs={12}>
                    <Paper sx={{ p: 3, borderRadius: 2, bgcolor: 'white' }}>
                      <Typography variant="h6" sx={{ mb: 2, display: 'flex', alignItems: 'center', gap: 1 }}>
                        <TaskIcon color="primary" />
                        Description
                      </Typography>
                      
                      <TextField
                        fullWidth
                        multiline
                        rows={3}
                        label="Work Description"
                        value={description}
                        onChange={(e) => setDescription(e.target.value)}
                        placeholder="Describe the work performed..."
                        variant="outlined"
                      />
                    </Paper>
                  </Grid>
                </Grid>
              </Box>
            </Fade>
          </DialogContent>

          <DialogActions sx={{ p: 3, bgcolor: '#f5f5f5', gap: 2 }}>
            <Button
              onClick={() => setDialogOpen(false)}
              variant="outlined"
              size="large"
              sx={{ minWidth: 100 }}
            >
              Cancel
            </Button>
            
            {selectedEntry && (
              <Button
                onClick={handleDelete}
                color="error"
                variant="outlined"
                size="large"
                disabled={loading || (selectedEntry.status === 'approved')}
                sx={{ minWidth: 100 }}
              >
                Delete
              </Button>
            )}

            {/* Demonstração prática da função canValidateTimesheets() */}
            {selectedEntry && canValidateTimesheets() && selectedEntry.status === 'submitted' && (
              <>
                <Button
                  onClick={() => {
                    // TODO: Implementar aprovação
                    console.log('Approving timesheet:', selectedEntry.id);
                  }}
                  color="success"
                  variant="contained"
                  size="large"
                  disabled={loading}
                  sx={{ minWidth: 100 }}
                >
                  Approve
                </Button>
                <Button
                  onClick={() => {
                    // TODO: Implementar rejeição
                    console.log('Rejecting timesheet:', selectedEntry.id);
                  }}
                  color="warning"
                  variant="outlined"
                  size="large"
                  disabled={loading}
                  sx={{ minWidth: 100 }}
                >
                  Reject
                </Button>
              </>
            )}
            
            <Button
              onClick={handleSave}
              variant="contained"
              size="large"
              disabled={loading}
              startIcon={loading ? null : <SaveIcon />}
              sx={{ 
                minWidth: 120,
                background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                '&:hover': {
                  background: 'linear-gradient(135deg, #5a6fd8 0%, #6a4190 100%)'
                }
              }}
            >
              {loading ? 'Saving...' : 'Save Entry'}
            </Button>
          </DialogActions>
        </Dialog>
      </LocalizationProvider>
    </Box>
  );
};

export default TimesheetCalendar;