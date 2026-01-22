import React, { useState, useEffect, useMemo } from 'react';
import {
  Dialog, DialogTitle, DialogContent, DialogActions,
  TextField, Button, Grid, Paper, Typography, Box, Chip,
  IconButton, MenuItem, Fade
} from '@mui/material';
import { DatePicker, TimePicker } from '@mui/x-date-pickers';
import {
  Close as CloseIcon, Edit as EditIcon, Add as AddIcon,
  AccessTime as TimeIcon, Work as ProjectIcon,
  Description as DescriptionIcon, Schedule as DurationIcon
} from '@mui/icons-material';
import dayjs, { Dayjs } from 'dayjs';
import type { Timesheet, Project, Task, Location } from '../../types';
import { useAuth } from '../Auth/AuthContext';
import { getTenantDatePickerFormat, getTenantHourCycle, getTenantTimeFormat } from '../../utils/tenantFormatting';

interface TimesheetEditDialogProps {
  open: boolean;
  onClose: () => void;
  onSave: (data: any) => Promise<void>;
  onDelete?: () => Promise<void>;
  timesheet: Timesheet | null;
  projects: Project[];
  tasks: Task[];
  locations: Location[];
  readOnly?: boolean;
  showApprovalButtons?: boolean;
  onApprove?: () => void;
  onReject?: () => void;
}

const TimesheetEditDialog: React.FC<TimesheetEditDialogProps> = ({
  open,
  onClose,
  onSave,
  timesheet,
  projects,
  tasks,
  locations,
  readOnly = false
}) => {
  const { tenantContext } = useAuth();
  const [loading, setLoading] = useState(false);

  const datePickerFormat = useMemo(() => getTenantDatePickerFormat(tenantContext), [tenantContext]);
  const timePickerAmpm = useMemo(() => getTenantHourCycle(tenantContext) === 12, [tenantContext]);
  const timePickerFormat = useMemo(() => getTenantTimeFormat(tenantContext), [tenantContext]);
  
  // Form state
  const [selectedDate, setSelectedDate] = useState<Dayjs | null>(null);
  const [projectId, setProjectId] = useState<number>(0);
  const [taskId, setTaskId] = useState<number>(0);
  const [locationId, setLocationId] = useState<number>(0);
  const [startTimeObj, setStartTimeObj] = useState<Dayjs | null>(null);
  const [endTimeObj, setEndTimeObj] = useState<Dayjs | null>(null);
  const [description, setDescription] = useState('');

  // Calculate hours worked
  const hoursWorked = useMemo(() => {
    if (!startTimeObj || !endTimeObj) return 0;
    const diff = endTimeObj.diff(startTimeObj, 'minute');
    return Math.max(0, Math.round((diff / 60) * 100) / 100);
  }, [startTimeObj, endTimeObj]);

  // Filter tasks by selected project
  const filteredTasks = useMemo(() => {
    if (!projectId || !tasks) return [];
    return tasks.filter(task => task.project_id === projectId);
  }, [tasks, projectId]);

  // Filter locations by selected task
  const filteredLocations = useMemo(() => {
    if (!taskId || taskId === 0) return []; // No task selected = no locations
    const selectedTask = tasks.find(t => t.id === taskId);
    if (!selectedTask?.locations?.length) return []; // Task has no locations = no locations
    const taskLocationIds = selectedTask.locations.map(loc => loc.id);
    return (locations || []).filter(loc => taskLocationIds.includes(loc.id));
  }, [taskId, tasks, locations]);

  // Populate form when timesheet changes
  useEffect(() => {
    if (timesheet) {
      console.log('TimesheetEditDialog received timesheet:', timesheet);
      
      setSelectedDate(dayjs(timesheet.date));
      
      // Handle both direct IDs and nested objects (for TimesheetManagerRow compatibility)
      setProjectId(timesheet.project_id || (timesheet as any).project?.id || 0);
      setTaskId(timesheet.task_id || (timesheet as any).task?.id || 0);
      setLocationId(timesheet.location_id || (timesheet as any).location?.id || 0);
      setDescription(timesheet.description || '');
      
      if (timesheet.start_time) {
        setStartTimeObj(dayjs(`2023-01-01 ${timesheet.start_time}`));
      }
      if (timesheet.end_time) {
        setEndTimeObj(dayjs(`2023-01-01 ${timesheet.end_time}`));
      }
    } else {
      // Reset for new entry
      setSelectedDate(dayjs());
      setProjectId(0);
      setTaskId(0);
      setLocationId(0);
      setStartTimeObj(dayjs().hour(9).minute(0));
      setEndTimeObj(dayjs().hour(17).minute(0));
      setDescription('');
    }
  }, [timesheet, open]);

  // Reset location when task changes (only if current location is not in task's locations)
  useEffect(() => {
    if (taskId && taskId !== 0 && locationId) {
      const selectedTask = tasks.find(t => t.id === taskId);
      if (selectedTask?.locations && selectedTask.locations.length > 0) {
        const taskLocationIds = selectedTask.locations.map(loc => loc.id);
        const currentLocationId = typeof locationId === 'string' ? parseInt(locationId) : locationId;
        if (!taskLocationIds.includes(currentLocationId)) {
          setLocationId(0); // Clear location if not in task's locations
        }
      }
    }
  }, [taskId, locationId, tasks]);

  const handleSave = async () => {
    if (readOnly) {
      return;
    }
    if (!projectId || !taskId || !locationId || !selectedDate || !startTimeObj || !endTimeObj) {
      console.error('Please fill all required fields');
      return;
    }

    if (hoursWorked <= 0) {
      console.error('Hours worked must be greater than 0');
      return;
    }

    try {
      setLoading(true);
      
      const data = {
        project_id: projectId,
        task_id: taskId,
        location_id: locationId,
        date: selectedDate.format('YYYY-MM-DD'),
        start_time: startTimeObj.format('HH:mm'),
        end_time: endTimeObj.format('HH:mm'),
        hours_worked: hoursWorked,
        description: description.trim()
      };

      await onSave(data);
      onClose();
    } catch (err: any) {
      console.error('Failed to save timesheet:', err.response?.data?.message || err.message);
    } finally {
      setLoading(false);
    }
  };

  const isEditable = !readOnly && (timesheet?.status === 'draft' || timesheet?.status === 'submitted' || timesheet?.status === 'rejected' || !timesheet);

  return (
    <Dialog 
      open={open} 
      onClose={onClose} 
      maxWidth="sm" 
      fullWidth
      PaperProps={{
        sx: {
          borderRadius: 2,
          maxHeight: '85vh'
        }
      }}
    >
      <DialogTitle 
        sx={{ 
          background: timesheet 
            ? 'linear-gradient(135deg, #ff9800 0%, #f57c00 100%)'
            : 'linear-gradient(135deg, #4caf50 0%, #388e3c 100%)',
          color: 'white',
          p: 2,
          pb: 1.5
        }}
      >
        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            {timesheet ? <EditIcon fontSize="small" /> : <AddIcon fontSize="small" />}
            <Typography variant="h6" fontWeight={600} fontSize="1.1rem">
              {timesheet ? 'Edit Entry' : 'New Entry'}
            </Typography>
          </Box>
          
          <Box sx={{ display: 'flex', gap: 0.5, alignItems: 'center' }}>
            {timesheet?.technician && (
              <Chip
                label={timesheet.technician.name}
                size="small"
                sx={{ 
                  bgcolor: 'rgba(255,255,255,0.2)', 
                  color: 'white', 
                  fontWeight: 500,
                  fontSize: '0.75rem',
                  height: '24px'
                }}
              />
            )}
            
            {timesheet && (
              <Chip
                label={timesheet.status.charAt(0).toUpperCase() + timesheet.status.slice(1)}
                size="small"
                sx={{ 
                  bgcolor: 'rgba(255,255,255,0.2)', 
                  color: 'white', 
                  fontWeight: 500,
                  fontSize: '0.75rem',
                  height: '24px'
                }}
              />
            )}
            
            {hoursWorked > 0 && (
              <Chip 
                label={`${hoursWorked}h`}
                size="small"
                icon={<DurationIcon sx={{ fontSize: '0.9rem' }} />}
                sx={{ 
                  bgcolor: 'rgba(255,255,255,0.2)',
                  color: 'white',
                  fontWeight: 500,
                  fontSize: '0.75rem',
                  height: '24px',
                  '& .MuiChip-icon': { color: 'white', ml: 0.5 }
                }}
              />
            )}
            
            <IconButton onClick={onClose} sx={{ color: 'white', p: 0.5 }} size="small">
              <CloseIcon fontSize="small" />
            </IconButton>
          </Box>
        </Box>
      </DialogTitle>

      <DialogContent sx={{ p: 2, bgcolor: '#f5f5f5' }}>
        <Fade in={open}>
          <Box>
            <Grid container spacing={1.5}>
              {/* Date & Time Section */}
              <Grid item xs={12}>
                <Paper sx={{ p: 2, borderRadius: 1.5 }}>
                  <Typography variant="subtitle2" sx={{ mb: 1.5, display: 'flex', alignItems: 'center', gap: 1, fontWeight: 600 }}>
                    <TimeIcon color="primary" fontSize="small" />
                    Date & Time
                  </Typography>
                  
                  <Grid container spacing={1.5}>
                    <Grid item xs={12}>
                      <DatePicker
                        label="Date"
                        value={selectedDate}
                        onChange={(newDate) => setSelectedDate(newDate)}
                        format={datePickerFormat}
                        disabled={!isEditable}
                        slotProps={{ textField: { fullWidth: true, size: 'small' } }}
                      />
                    </Grid>
                    
                    <Grid item xs={4}>
                      <TimePicker
                        label="Start Time"
                        value={startTimeObj}
                        onChange={(newTime) => {
                          setStartTimeObj(newTime);
                          if (newTime) {
                            const newEndTime = newTime.add(1, 'hour');
                            setEndTimeObj(newEndTime);
                          }
                        }}
                        ampm={timePickerAmpm}
                        format={timePickerFormat}
                        minutesStep={15}
                        disabled={!isEditable}
                        slotProps={{ textField: { fullWidth: true, size: 'small' } }}
                      />
                    </Grid>
                    
                    <Grid item xs={4}>
                      <TimePicker
                        label="End Time"
                        value={endTimeObj}
                        onChange={(newTime) => setEndTimeObj(newTime)}
                        ampm={timePickerAmpm}
                        format={timePickerFormat}
                        minutesStep={15}
                        disabled={!isEditable}
                        slotProps={{ textField: { fullWidth: true, size: 'small' } }}
                      />
                    </Grid>
                    
                    <Grid item xs={4}>
                      <TextField
                        fullWidth
                        label="Duration"
                        value={`${hoursWorked}h`}
                        InputProps={{ readOnly: true }}
                        size="small"
                        sx={{
                          '& .MuiInputBase-input': {
                            fontWeight: 600,
                            fontSize: '1rem',
                            textAlign: 'center',
                            color: hoursWorked > 0 ? 'success.main' : 'text.secondary'
                          }
                        }}
                      />
                    </Grid>
                  </Grid>
                </Paper>
              </Grid>

              {/* Project Details Section */}
              <Grid item xs={12}>
                <Paper sx={{ p: 2, borderRadius: 1.5 }}>
                  <Typography variant="subtitle2" sx={{ mb: 1.5, display: 'flex', alignItems: 'center', gap: 1, fontWeight: 600 }}>
                    <ProjectIcon color="primary" fontSize="small" />
                    Project Details
                  </Typography>
                  
                  <Grid container spacing={1.5}>
                    <Grid item xs={12}>
                      <TextField
                        select
                        fullWidth
                        label="Project"
                        value={projectId}
                        onChange={(e) => {
                          setProjectId(Number(e.target.value));
                          setTaskId(0);
                        }}
                        disabled={!isEditable}
                        size="small"
                      >
                        <MenuItem value={0}>Select a project</MenuItem>
                        {projects.map((project) => (
                          <MenuItem key={project.id} value={project.id}>
                            {project.name}
                          </MenuItem>
                        ))}
                      </TextField>
                    </Grid>
                    
                    <Grid item xs={12}>
                      <TextField
                        select
                        fullWidth
                        label="Task"
                        value={taskId}
                        onChange={(e) => setTaskId(Number(e.target.value))}
                        size="small"
                        disabled={!projectId || !isEditable}
                      >
                        <MenuItem value={0}>Select a task</MenuItem>
                        {filteredTasks.map((task) => (
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
                        disabled={!isEditable}
                        size="small"
                      >
                        <MenuItem value={0}>Select a location</MenuItem>
                        {filteredLocations.map((location) => (
                          <MenuItem key={location.id} value={location.id}>
                            {location.name} - {location.city}, {location.country}
                          </MenuItem>
                        ))}
                      </TextField>
                    </Grid>
                  </Grid>
                </Paper>
              </Grid>

              {/* Description Section */}
              <Grid item xs={12}>
                <Paper sx={{ p: 2, borderRadius: 1.5 }}>
                  <Typography variant="subtitle2" sx={{ mb: 1.5, display: 'flex', alignItems: 'center', gap: 1, fontWeight: 600 }}>
                    <DescriptionIcon color="primary" fontSize="small" />
                    Description
                  </Typography>
                  
                  <TextField
                    fullWidth
                    multiline
                    rows={2}
                    label="Work Description"
                    value={description}
                    onChange={(e) => setDescription(e.target.value)}
                    placeholder="Describe the work performed..."
                    disabled={!isEditable}
                    size="small"
                  />
                </Paper>
              </Grid>
            </Grid>
          </Box>
        </Fade>
      </DialogContent>

      <DialogActions sx={{ p: 2, bgcolor: '#f5f5f5', gap: 1.5 }}>
        <Button onClick={onClose} variant="outlined">
          Cancel
        </Button>

        {isEditable && (
          <Button
            onClick={handleSave}
            color="primary"
            variant="contained"
            disabled={loading}
          >
            {loading ? 'Saving...' : 'Save'}
          </Button>
        )}
      </DialogActions>
    </Dialog>
  );
};

export default TimesheetEditDialog;
