import React, { useState, useEffect, useMemo } from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  TextField,
  MenuItem,
  Box,
  Grid,
  Autocomplete,
  CircularProgress,
  IconButton,
  Tooltip,
  Typography,
} from '@mui/material';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs, { Dayjs } from 'dayjs';
import { AutoAwesome as AIIcon } from '@mui/icons-material';
import { useNotification } from '../../contexts/NotificationContext';
import { travelsApi } from '../../services/travels';
import type { TravelSegment } from '../../services/travels';
import { projectsApi, techniciansApi } from '../../services/api';

import api from '../../services/api';

interface TravelFormProps {
  open: boolean;
  onClose: () => void;
  onSave: () => void;
  editingTravel: TravelSegment | null;
}

interface Project {
  id: number;
  name: string;
}

interface Technician {
  id: number;
  name: string;
  worker_contract_country?: string;
}

interface Location {
  id: number;
  name: string;
  country: string;
  city?: string | null;
  address?: string | null;
  is_active: boolean;
}

const TravelForm: React.FC<TravelFormProps> = ({ open, onClose, onSave, editingTravel }) => {
  const { showSuccess, showError } = useNotification();
  const [loading, setLoading] = useState(false);
  const [loadingSuggestion, setLoadingSuggestion] = useState(false);
  const [projects, setProjects] = useState<Project[]>([]);
  const [technicians, setTechnicians] = useState<Technician[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);
  const [workerContractCountry, setWorkerContractCountry] = useState<string>('');
  const [destinationLocations, setDestinationLocations] = useState<Location[]>([]);
  
  const [formData, setFormData] = useState<{
    technician_id: string;
    project_id: string;
    start_at: Dayjs | null;
    end_at: Dayjs | null;
    origin_country: string;
    origin_location_id: string;
    destination_country: string;
    destination_location_id: string;
    status: string;
  }>({
    technician_id: '',
    project_id: '',
    start_at: null,
    end_at: null,
    origin_country: '',
    origin_location_id: '',
    destination_country: '',
    destination_location_id: '',
    status: 'planned',
  });

  useEffect(() => {
    if (open) {
      fetchProjects();
      fetchTechnicians();
      fetchLocations();
      
      if (editingTravel) {
        setFormData({
          technician_id: editingTravel.technician_id.toString(),
          project_id: editingTravel.project_id.toString(),
          start_at: editingTravel.start_at ? dayjs(editingTravel.start_at) : null,
          end_at: editingTravel.end_at ? dayjs(editingTravel.end_at) : null,
          origin_country: editingTravel.origin_country,
          origin_location_id: editingTravel.origin_location_id?.toString() || '',
          destination_country: editingTravel.destination_country,
          destination_location_id: editingTravel.destination_location_id?.toString() || '',
          status: editingTravel.status,
        });
      } else {
        resetForm();
      }
    }
  }, [open, editingTravel]);

  // Extract worker contract country from technician list when technician changes
  useEffect(() => {
    if (formData.technician_id && technicians.length > 0) {
      const selectedTech = technicians.find(t => t.id.toString() === formData.technician_id);
      // Access worker_contract_country if available in technician object
      const contractCountry = selectedTech?.worker_contract_country || '';
      setWorkerContractCountry(contractCountry);
    } else {
      setWorkerContractCountry('');
    }
  }, [formData.technician_id, technicians]);

  // Filter destination locations based on origin country and worker contract country
  useEffect(() => {
    if (!formData.origin_country || !formData.project_id) {
      setDestinationLocations([]);
      return;
    }

    // Rule: If origin country = worker contract country → show ONLY project locations
    // Rule: If origin country ≠ worker contract country → show ALL locations
    if (formData.origin_country === workerContractCountry) {
      // Filter locations by selected project
      // For now, since backend doesn't provide project->locations relationship,
      // we show all locations (to be refined when backend implements project locations endpoint)
      // TODO: Call /api/projects/{id}/locations when available
      setDestinationLocations(locations);
    } else {
      // Origin country differs from contract country → show all available locations
      setDestinationLocations(locations);
    }
  }, [formData.origin_country, formData.project_id, workerContractCountry, locations]);

  // Calculate duration preview
  const durationLabel = useMemo(() => {
    if (!formData.start_at || !formData.end_at) return null;

    if (formData.end_at.isBefore(formData.start_at)) return null;

    const diff = formData.end_at.diff(formData.start_at, 'minute');
    const hours = Math.floor(diff / 60);
    const remainingMinutes = diff % 60;

    return `${hours}h ${String(remainingMinutes).padStart(2, '0')}m`;
  }, [formData.start_at, formData.end_at]);

  const fetchProjects = async () => {
    try {
      // Get projects where user is manager OR member (same as Timesheet)
      // Uses /user/projects endpoint (TimesheetController::getUserProjects)
      const projects = await projectsApi.getForCurrentUser();
      setProjects(Array.isArray(projects) ? projects : []);
    } catch (error) {
      console.error('Failed to load projects:', error);
      setProjects([]);
    }
  };

  const fetchTechnicians = async () => {
    try {
      // Backend automatically filters: Owner sees all, Admin sees non-owners,
      // Managers see only members from managed projects, Users see self
      // Uses techniciansApi.getAll() (same as Timesheet)
      const response = await techniciansApi.getAll();
      const techList = response.data || [];
      setTechnicians(Array.isArray(techList) ? techList : []);
    } catch (error) {
      console.error('Failed to load technicians:', error);
      setTechnicians([]);
    }
  };

  const fetchLocations = async () => {
    try {
      // Get all active locations (will be filtered by project later)
      const locationsResponse = await api.get('/api/locations');
      const allLocations = locationsResponse.data.data || locationsResponse.data || [];
      
      const activeLocations = allLocations.filter((loc: Location) => loc.is_active);
      setLocations(activeLocations);
    } catch (error) {
      console.error('Failed to load locations:', error);
      setLocations([]);
    }
  };

  const resetForm = () => {
    setFormData({
      technician_id: '',
      project_id: '',
      start_at: null,
      end_at: null,
      origin_country: '',
      origin_location_id: '',
      destination_country: '',
      destination_location_id: '',
      status: 'planned',
    });
    setLocations([]);
  };

  const handleSuggest = async () => {
    if (!formData.technician_id || !formData.project_id) {
      showError('Please select technician and project first');
      return;
    }

    try {
      setLoadingSuggestion(true);
      const suggestion = await travelsApi.getSuggestions(
        parseInt(formData.technician_id),
        parseInt(formData.project_id)
      );

      setFormData(prev => ({
        ...prev,
        origin_country: suggestion.origin_country || prev.origin_country,
        origin_location_id: suggestion.origin_location_id?.toString() || prev.origin_location_id,
        destination_country: suggestion.destination_country || prev.destination_country,
        destination_location_id: suggestion.destination_location_id?.toString() || prev.destination_location_id,
      }));

      showSuccess('AI suggestion applied');
    } catch (error) {
      showError('Failed to get AI suggestion');
    } finally {
      setLoadingSuggestion(false);
    }
  };

  const handleSubmit = async (e?: React.FormEvent) => {
    if (e) {
      e.preventDefault();
    }

    try {
      setLoading(true);
      const payload: Partial<TravelSegment> = {
        technician_id: parseInt(formData.technician_id),
        project_id: parseInt(formData.project_id),
        start_at: formData.start_at ? formData.start_at.format('YYYY-MM-DD') : undefined,
        end_at: formData.end_at ? formData.end_at.format('YYYY-MM-DD') : null,
        origin_country: formData.origin_country,
        destination_country: formData.destination_country,
        origin_location_id: formData.origin_location_id ? parseInt(formData.origin_location_id) : null,
        destination_location_id: formData.destination_location_id ? parseInt(formData.destination_location_id) : null,
        status: formData.status as 'planned' | 'completed' | 'cancelled',
      };

      if (editingTravel) {
        await travelsApi.update(editingTravel.id, payload);
        showSuccess('Travel segment updated successfully');
      } else {
        await travelsApi.create(payload);
        showSuccess('Travel segment created successfully');
      }

      onSave();
      onClose();
    } catch (error: any) {
      showError(error?.response?.data?.message || 'Failed to save travel segment');
    } finally {
      setLoading(false);
    }
  };

  return (
    <Dialog open={open} onClose={onClose} maxWidth="md" fullWidth>
      <DialogTitle>
        {editingTravel ? 'Edit Travel Segment' : 'New Travel Segment'}
      </DialogTitle>
      <DialogContent>
        <Box component="form" onSubmit={handleSubmit} id="travel-form" sx={{ mt: 2 }}>
          <Grid container spacing={2}>
            <Grid item xs={12} sm={6}>
              <Autocomplete
                options={technicians}
                getOptionLabel={(option) => option.name}
                value={technicians.find(t => t.id.toString() === formData.technician_id) || null}
                onChange={(_, newValue) => {
                  setFormData({ ...formData, technician_id: newValue ? newValue.id.toString() : '' });
                }}
                renderInput={(params) => (
                  <TextField
                    {...params}
                    label="Technician"
                    required
                    placeholder="Search technicians..."
                    helperText="Only team members from managed projects"
                  />
                )}
                loading={technicians.length === 0}
              />
            </Grid>

            <Grid item xs={12} sm={6}>
              <Autocomplete
                options={projects}
                getOptionLabel={(option) => option.name}
                value={projects.find(p => p.id.toString() === formData.project_id) || null}
                onChange={(_, newValue) => {
                  setFormData({ ...formData, project_id: newValue ? newValue.id.toString() : '' });
                }}
                renderInput={(params) => (
                  <TextField
                    {...params}
                    label="Project"
                    required
                    placeholder="Search projects..."
                  />
                )}
                loading={projects.length === 0}
              />
            </Grid>

            <Grid item xs={12} sm={6}>
              <DatePicker
                label="Start Date"
                value={formData.start_at}
                onChange={(newValue) => setFormData({ ...formData, start_at: newValue })}
                format="DD/MM/YYYY"
                slotProps={{
                  textField: {
                    fullWidth: true,
                    required: true
                  }
                }}
              />
            </Grid>

            <Grid item xs={12} sm={6}>
              <DatePicker
                label="End Date"
                value={formData.end_at}
                onChange={(newValue) => setFormData({ ...formData, end_at: newValue })}
                format="DD/MM/YYYY"
                slotProps={{
                  textField: {
                    fullWidth: true,
                    helperText: formData.status === 'completed' ? 'Required for completed status' : ''
                  }
                }}
              />
            </Grid>

            {durationLabel && (
              <Grid item xs={12}>
                <Typography 
                  variant="body2" 
                  sx={{ 
                    color: '#fff', 
                    fontWeight: 600,
                    bgcolor: 'primary.main',
                    px: 2,
                    py: 1,
                    borderRadius: 1,
                    display: 'inline-block'
                  }}
                >
                  ⏱️ Duration: {durationLabel}
                </Typography>
              </Grid>
            )}

            <Grid item xs={12} sm={6}>
              <TextField
                select
                label="Status"
                fullWidth
                value={formData.status}
                onChange={(e) => setFormData({ ...formData, status: e.target.value })}
              >
                <MenuItem value="planned">Planned</MenuItem>
                <MenuItem value="completed">Completed</MenuItem>
                <MenuItem value="cancelled">Cancelled</MenuItem>
              </TextField>
            </Grid>

            <Grid item xs={12}>
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 1 }}>
                <Box sx={{ fontWeight: 600, color: 'text.secondary' }}>Origin</Box>
                <Tooltip title="Get AI suggestion">
                  <span>
                    <IconButton
                      size="small"
                      onClick={handleSuggest}
                      disabled={loadingSuggestion || !formData.technician_id || !formData.project_id}
                      sx={{ color: '#667eea' }}
                    >
                      {loadingSuggestion ? <CircularProgress size={20} /> : <AIIcon />}
                    </IconButton>
                  </span>
                </Tooltip>
              </Box>
            </Grid>

            <Grid item xs={12} sm={6}>
              <Autocomplete
                options={Array.from(new Set(locations.map(l => l.country)))}
                getOptionLabel={(option) => option}
                value={formData.origin_country || null}
                onChange={(_, newValue) => {
                  setFormData({ 
                    ...formData, 
                    origin_country: newValue || '',
                    origin_location_id: '' // Reset location when country changes
                  });
                }}
                disabled={!formData.technician_id || !formData.project_id}
                renderInput={(params) => (
                  <TextField 
                    {...params} 
                    label="Origin Country" 
                    required
                    helperText={
                      !formData.technician_id || !formData.project_id
                        ? "Select technician and project first"
                        : ""
                    }
                  />
                )}
              />
            </Grid>

            <Grid item xs={12} sm={6}>
              <Autocomplete
                options={locations.filter(l => l.country === formData.origin_country)}
                getOptionLabel={(option) => `${option.name} (${option.city || option.country})`}
                value={locations.find(l => l.id.toString() === formData.origin_location_id) || null}
                onChange={(_, newValue) => setFormData({ ...formData, origin_location_id: newValue?.id.toString() || '' })}
                disabled={!formData.origin_country}
                renderInput={(params) => (
                  <TextField
                    {...params}
                    label="Origin Location (Optional)"
                    placeholder="Select origin location"
                    helperText={!formData.origin_country ? "Select origin country first" : "From project locations"}
                  />
                )}
              />
            </Grid>

            <Grid item xs={12}>
              <Box sx={{ fontWeight: 600, color: 'text.secondary', mb: 1 }}>Destination</Box>
            </Grid>

            <Grid item xs={12} sm={6}>
              <Autocomplete
                options={Array.from(new Set(destinationLocations.map(l => l.country)))}
                getOptionLabel={(option) => option}
                value={formData.destination_country || null}
                onChange={(_, newValue) => {
                  setFormData({ 
                    ...formData, 
                    destination_country: newValue || '',
                    destination_location_id: '' // Reset location when country changes
                  });
                }}
                disabled={!formData.origin_country}
                renderInput={(params) => (
                  <TextField 
                    {...params} 
                    label="Destination Country" 
                    required
                    helperText={
                      !formData.origin_country
                        ? "Select origin country first"
                        : formData.origin_country === workerContractCountry
                        ? "Showing project locations only"
                        : "Showing all locations"
                    }
                  />
                )}
              />
            </Grid>

            <Grid item xs={12} sm={6}>
              <Autocomplete
                options={destinationLocations.filter(l => l.country === formData.destination_country)}
                getOptionLabel={(option) => `${option.name} (${option.city || option.country})`}
                value={destinationLocations.find(l => l.id.toString() === formData.destination_location_id) || null}
                onChange={(_, newValue) => setFormData({ ...formData, destination_location_id: newValue?.id.toString() || '' })}
                disabled={!formData.destination_country}
                renderInput={(params) => (
                  <TextField
                    {...params}
                    label="Destination Location (Optional)"
                    placeholder="Select destination location"
                    helperText={
                      !formData.destination_country
                        ? "Select destination country first"
                        : formData.origin_country === workerContractCountry
                        ? "Project locations only"
                        : "All available locations"
                    }
                  />
                )}
              />
            </Grid>
          </Grid>
        </Box>
      </DialogContent>
      <DialogActions sx={{ p: 2 }}>
        <Button onClick={onClose}>
          Cancel
        </Button>
        <Button
          type="submit"
          form="travel-form"
          variant="contained"
          color="primary"
          disabled={loading}
        >
          {loading ? 'Saving...' : (editingTravel ? 'Update' : 'Save')}
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default TravelForm;
