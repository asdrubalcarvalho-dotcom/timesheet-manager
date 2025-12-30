import React, { useEffect, useMemo, useState } from 'react';
import {
  Alert,
  Badge,
  Box,
  Button,
  Checkbox,
  CircularProgress,
  Collapse,
  Dialog,
  DialogActions,
  DialogContent,
  DialogTitle,
  FormControl,
  FormHelperText,
  IconButton,
  InputLabel,
  MenuItem,
  Select,
  ListItemText,
  Stack,
  Tooltip,
  Typography,
} from '@mui/material';
import InfoOutlinedIcon from '@mui/icons-material/InfoOutlined';
import api, {
  aiSuggestionsApi,
  taskLocationsApi,
  type TaskLocationSuggestion,
  type TaskLocationSuggestionWeights,
} from '../../services/api';
import { useNotification } from '../../contexts/NotificationContext';
import { useBilling } from '../../contexts/BillingContext';
import { useFeatures } from '../../contexts/FeatureContext';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';

export type TaskLocationsDialogTask = {
  id: number;
  name: string;
};

type Location = {
  id: number;
  name: string;
  city?: string;
  country?: string;
};

type Props = {
  open: boolean;
  task: TaskLocationsDialogTask | null;
  onClose: () => void;
  onSaved: () => Promise<void> | void;
};

const normalizeApiResponse = <T,>(payload: any): T[] => {
  if (Array.isArray(payload)) return payload;
  if (payload && Array.isArray(payload.data)) return payload.data;
  if (payload && payload.success && Array.isArray(payload.data)) return payload.data;
  return [];
};

const TaskLocationsDialog: React.FC<Props> = ({ open, task, onClose, onSaved }) => {
  const { showSuccess, showError } = useNotification();
  const { billingSummary } = useBilling();
  const { hasAI } = useFeatures();
  const { isReadOnly, ensureWritable, warn } = useReadOnlyGuard('task-locations');

  const [allLocations, setAllLocations] = useState<Location[]>([]);
  const [loadingLocations, setLoadingLocations] = useState(false);
  const [loadingTaskLocations, setLoadingTaskLocations] = useState(false);
  const [saving, setSaving] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [selectedLocationIds, setSelectedLocationIds] = useState<number[]>([]);
  const [aiSuggestions, setAiSuggestions] = useState<TaskLocationSuggestion[]>([]);
  const [aiWeights, setAiWeights] = useState<TaskLocationSuggestionWeights | null>(null);
  const [loadingAiSuggestions, setLoadingAiSuggestions] = useState(false);
  const [selectedAiSuggestionIds, setSelectedAiSuggestionIds] = useState<number[]>([]);
  const [showAiSuggestionsPanel, setShowAiSuggestionsPanel] = useState(false);

  const aiFeatureRaw = billingSummary?.features?.ai;
  const aiFeatureObject = aiFeatureRaw && typeof aiFeatureRaw === 'object' ? aiFeatureRaw : null;
  const aiFeatureEnabled = hasAI;
  const aiEntitled = aiFeatureObject?.entitled ?? Boolean(billingSummary?.entitlements?.ai);
  const aiToggleEnabled = aiFeatureObject?.toggle ?? Boolean(billingSummary?.toggles?.ai_enabled);
  const aiLocked = !aiEntitled;
  const aiDisabledByTenant = aiEntitled && !aiFeatureEnabled;

  const canLoad = open && !!task;

  useEffect(() => {
    if (!canLoad) return;

    let cancelled = false;

    const loadAllLocations = async () => {
      try {
        setLoadingLocations(true);
        const res = await api.get('/api/locations');
        const list = normalizeApiResponse<Location>(res.data);
        if (!cancelled) setAllLocations(list);
      } catch (e: any) {
        if (!cancelled) {
          setAllLocations([]);
          setError(e?.response?.data?.message || 'Failed to load locations');
        }
      } finally {
        if (!cancelled) setLoadingLocations(false);
      }
    };

    loadAllLocations();

    return () => {
      cancelled = true;
    };
  }, [canLoad]);

  useEffect(() => {
    if (!canLoad || !task) return;

    let cancelled = false;

    const loadTaskLocations = async () => {
      try {
        setLoadingTaskLocations(true);
        setError(null);
        const res = await taskLocationsApi.get(task.id);
        const currentLocations = res.data?.locations || [];
        const currentIds = (currentLocations as any[])
          .map((l) => Number(l?.id))
          .filter((v) => Number.isFinite(v));
        if (!cancelled) setSelectedLocationIds(currentIds);
      } catch (e: any) {
        if (!cancelled) {
          setSelectedLocationIds([]);
          setError(e?.response?.data?.message || 'Failed to load task locations');
        }
      } finally {
        if (!cancelled) setLoadingTaskLocations(false);
      }
    };

    loadTaskLocations();

    return () => {
      cancelled = true;
    };
  }, [canLoad, task?.id]);

  useEffect(() => {
    if (!canLoad || !task || !aiFeatureEnabled) {
      setAiSuggestions([]);
      setAiWeights(null);
      setSelectedAiSuggestionIds([]);
      setLoadingAiSuggestions(false);
      return;
    }

    let cancelled = false;

    const loadAiSuggestions = async () => {
      try {
        setLoadingAiSuggestions(true);
        const response = await aiSuggestionsApi.suggestTaskLocations(task.id, 5);
        if (cancelled) return;

        const suggestions = response?.data?.suggestions || [];
        setAiSuggestions(Array.isArray(suggestions) ? suggestions : []);
        setAiWeights(response?.weights ?? null);
        setSelectedAiSuggestionIds([]);
      } catch (err) {
        if (!cancelled) {
          console.warn('AI suggestions unavailable', err);
          setAiSuggestions([]);
          setAiWeights(null);
          setSelectedAiSuggestionIds([]);
        }
      } finally {
        if (!cancelled) setLoadingAiSuggestions(false);
      }
    };

    loadAiSuggestions();

    return () => {
      cancelled = true;
    };
  }, [canLoad, task?.id, aiFeatureEnabled]);

  const handleClose = () => {
    if (saving) return;
    setError(null);
    setSelectedLocationIds([]);
    setAiSuggestions([]);
    setAiWeights(null);
    setSelectedAiSuggestionIds([]);
    setShowAiSuggestionsPanel(false);
    onClose();
  };

  const handleSave = async () => {
    if (!task) return;

    if (!ensureWritable()) {
      return;
    }

    try {
      setSaving(true);
      setError(null);

      await taskLocationsApi.sync(task.id, selectedLocationIds);
      await onSaved();

      showSuccess(
        selectedLocationIds.length === 0
          ? 'All locations removed from task'
          : `Task updated with ${selectedLocationIds.length} location(s)`
      );

      handleClose();
    } catch (e: any) {
      const msg = e?.response?.data?.message || 'Failed to update task locations';
      setError(msg);
      showError(msg);
    } finally {
      setSaving(false);
    }
  };

  const loading = loadingLocations || loadingTaskLocations;

  const filteredAiSuggestions = useMemo(() => {
    if (!aiFeatureEnabled || !aiSuggestions.length) return [];
    const selectedSet = new Set(selectedLocationIds);
    return aiSuggestions.filter((suggestion) => {
      const id = Number(suggestion.location_id);
      if (!Number.isFinite(id)) return false;
      return !selectedSet.has(id);
    });
  }, [aiFeatureEnabled, aiSuggestions, selectedLocationIds]);

  useEffect(() => {
    setSelectedAiSuggestionIds((prev) => {
      if (!prev.length) return prev;
      const validSet = new Set(filteredAiSuggestions.map((s) => Number(s.location_id)));
      const next = prev.filter((id) => validSet.has(id));
      return next.length === prev.length ? prev : next;
    });
  }, [filteredAiSuggestions]);

  const hasVisibleAiSuggestions = aiFeatureEnabled && filteredAiSuggestions.length > 0;
  const canToggleAiSuggestions = !!task;
  const shouldDisplayAiSection = showAiSuggestionsPanel;

  const title = useMemo(() => {
    if (!task) return 'Manage Locations for Task';
    return `Manage Locations for Task: ${task.name}`;
  }, [task]);

  useEffect(() => {
    if (!open) {
      setShowAiSuggestionsPanel(false);
    }
  }, [open]);

  useEffect(() => {
    setShowAiSuggestionsPanel(false);
  }, [task?.id]);

  const toggleAiSuggestion = (locationId: number) => {
    setSelectedAiSuggestionIds((prev) =>
      prev.includes(locationId) ? prev.filter((id) => id !== locationId) : [...prev, locationId]
    );
  };

  const applySelectedAiSuggestions = () => {
    if (selectedAiSuggestionIds.length === 0) return;
    setSelectedLocationIds((prev) => {
      const merged = new Set(prev);
      selectedAiSuggestionIds.forEach((id) => merged.add(id));
      return Array.from(merged);
    });
    setSelectedAiSuggestionIds([]);
  };

  const aiTooltip = useMemo(() => {
    if (!aiWeights) return '';
    const toPercent = (value: number) => `${Math.round(value * 100)}%`;
    return `Same project history ${toPercent(aiWeights.same_project)} · Cross project history ${toPercent(
      aiWeights.cross_project,
    )} · Assignment fallback ${toPercent(aiWeights.assignment_fallback)}`;
  }, [aiWeights]);

  const aiToggleTooltip = useMemo(() => {
    if (aiLocked) {
      return 'AI add-on required to unlock suggestions';
    }
    if (aiDisabledByTenant) {
      return aiToggleEnabled
        ? 'AI suggestions disabled in tenant settings'
        : 'AI add-on purchased but tenant toggle is off';
    }
    if (loadingAiSuggestions) {
      return 'AI suggestions loading';
    }
    return showAiSuggestionsPanel ? 'Hide AI suggestions' : 'Show AI suggestions';
  }, [aiLocked, aiDisabledByTenant, aiToggleEnabled, loadingAiSuggestions, showAiSuggestionsPanel]);

  const handleOpenBilling = () => {
    window.open('/billing', '_blank', 'noopener');
  };

  return (
    <Dialog open={open} onClose={handleClose} maxWidth="sm" fullWidth>
      <DialogTitle
        sx={{
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'space-between',
          gap: 1,
        }}
      >
        <Typography variant="h6" component="span">
          {title}
        </Typography>
        {canToggleAiSuggestions && (
          <Tooltip title={aiToggleTooltip} placement="left">
            <Badge
              color="error"
              variant="dot"
              overlap="circular"
              invisible={!hasVisibleAiSuggestions}
              sx={{
                '& .MuiBadge-badge': {
                  animation: hasVisibleAiSuggestions && !showAiSuggestionsPanel ? 'pulse 2s infinite' : 'none',
                  '@keyframes pulse': {
                    '0%': { transform: 'scale(1)', opacity: 1 },
                    '50%': { transform: 'scale(1.25)', opacity: 0.75 },
                    '100%': { transform: 'scale(1)', opacity: 1 },
                  },
                },
              }}
            >
              <IconButton
                size="small"
                onClick={() => setShowAiSuggestionsPanel((prev) => !prev)}
                disabled={!canLoad}
                sx={{
                  color: aiFeatureEnabled
                    ? showAiSuggestionsPanel
                      ? 'primary.main'
                      : 'text.secondary'
                    : aiLocked
                      ? 'text.disabled'
                      : 'warning.main',
                  bgcolor: showAiSuggestionsPanel ? 'action.hover' : 'transparent',
                  '&:hover': { bgcolor: 'action.hover' },
                }}
              >
                <Typography component="span" sx={{ fontSize: '1.2rem', lineHeight: 1 }}>
                  🤖
                </Typography>
              </IconButton>
            </Badge>
          </Tooltip>
        )}
      </DialogTitle>

      <DialogContent>
        <Box sx={{ pt: 2 }}>
          <Collapse in={shouldDisplayAiSection} timeout="auto" unmountOnExit>
            <Box
              sx={{
                mb: 3,
                p: 2,
                borderRadius: 2,
                border: '1px solid',
                borderColor: 'divider',
                backgroundColor: 'background.default',
              }}
            >
              <Stack direction="row" alignItems="center" spacing={1} sx={{ mb: 0.5 }}>
                <Typography variant="subtitle1" fontWeight={600} component="span">
                  🤖 AI Suggestions
                </Typography>
                {aiWeights && aiFeatureEnabled && (
                  <Tooltip title={aiTooltip} placement="top" arrow>
                    <IconButton size="small">
                      <InfoOutlinedIcon fontSize="inherit" />
                    </IconButton>
                  </Tooltip>
                )}
              </Stack>
              <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                Based on historical planning
              </Typography>

              {!aiEntitled ? (
                <Stack spacing={1.5}>
                  <Typography variant="body2" color="text.secondary">
                    AI Suggestions are available with the AI add-on. Upgrade in Billing to unlock automated planning insights.
                  </Typography>
                  <Button variant="outlined" size="small" onClick={handleOpenBilling}>
                    View billing options
                  </Button>
                </Stack>
              ) : !aiFeatureEnabled ? (
                <Stack spacing={1.5}>
                  <Alert severity="info">
                    AI add-on is active, but suggestions are disabled in tenant settings. Ask an administrator to re-enable the AI toggle in Billing → Tenant Settings.
                  </Alert>
                  <Button variant="outlined" size="small" onClick={handleOpenBilling}>
                    Manage AI preferences
                  </Button>
                </Stack>
              ) : loadingAiSuggestions ? (
                <Stack direction="row" alignItems="center" spacing={1} sx={{ color: 'text.secondary' }}>
                  <CircularProgress size={18} />
                  <Typography variant="body2">Loading suggestions...</Typography>
                </Stack>
              ) : hasVisibleAiSuggestions ? (
                <>
                  <Box component="ul" sx={{ listStyle: 'none', p: 0, m: 0, display: 'grid', gap: 1, mb: 2 }}>
                    {filteredAiSuggestions.map((suggestion) => {
                      const locationId = Number(suggestion.location_id);
                      const confidencePct = Math.round(Math.min(1, Math.max(0, suggestion.confidence ?? 0)) * 100);
                      const subtitle = suggestion.location?.city
                        ? `${suggestion.location?.city}${suggestion.location?.country ? `, ${suggestion.location?.country}` : ''}`
                        : suggestion.location?.country || null;
                      const checked = selectedAiSuggestionIds.includes(locationId);

                      return (
                        <Box
                          key={suggestion.location_id}
                          component="li"
                          sx={{
                            border: '1px solid',
                            borderColor: 'divider',
                            borderRadius: 1,
                            px: 1.5,
                            py: 1,
                            display: 'flex',
                            alignItems: 'center',
                            justifyContent: 'space-between',
                            gap: 2,
                          }}
                        >
                          <Stack direction="row" alignItems="center" spacing={1} sx={{ flex: 1, minWidth: 0 }}>
                            <Checkbox
                              checked={checked}
                              onChange={() => toggleAiSuggestion(locationId)}
                              size="small"
                              disabled={saving}
                            />
                            <Box sx={{ flex: 1, minWidth: 0 }}>
                              <Typography variant="body2" fontWeight={600} component="div" noWrap>
                                {suggestion.name}
                              </Typography>
                              {subtitle && (
                                <Typography variant="caption" color="text.secondary">
                                  {subtitle}
                                </Typography>
                              )}
                            </Box>
                          </Stack>
                          <Typography variant="body2" color="primary" fontWeight={600} component="div">
                            {confidencePct}%
                          </Typography>
                        </Box>
                      );
                    })}
                  </Box>

                  <Button
                    variant="outlined"
                    size="small"
                    onClick={applySelectedAiSuggestions}
                    disabled={selectedAiSuggestionIds.length === 0 || saving || !aiFeatureEnabled}
                  >
                    Apply selected suggestions
                  </Button>
                </>
              ) : (
                <Typography variant="body2" color="text.secondary">
                  No AI suggestions available for this task right now.
                </Typography>
              )}
            </Box>
          </Collapse>

          {error && (
            <Alert severity="error" sx={{ mb: 2 }} onClose={() => setError(null)}>
              {error}
            </Alert>
          )}

          {loading ? (
            <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
              <CircularProgress size={28} />
            </Box>
          ) : allLocations.length === 0 ? (
            <Alert severity="info">
              No locations available. Please create locations first in the Locations management page.
            </Alert>
          ) : (
            <FormControl fullWidth>
              <InputLabel id="location-select-label">Select Locations</InputLabel>
              <Select
                labelId="location-select-label"
                id="location-select"
                multiple
                value={selectedLocationIds}
                disabled={isReadOnly}
                onChange={(e) => {
                  const value = e.target.value;
                  setSelectedLocationIds(typeof value === 'string' ? [] : (value as number[]));
                }}
                label="Select Locations"
                renderValue={(selected) => {
                  if (selected.length === 0) return <em>No locations selected</em>;
                  return `${selected.length} location${selected.length > 1 ? 's' : ''} selected`;
                }}
              >
                {allLocations.map((location) => (
                  <MenuItem key={location.id} value={location.id}>
                    <Checkbox checked={selectedLocationIds.indexOf(location.id) > -1} disabled={isReadOnly} />
                    <ListItemText
                      primary={location.name}
                      secondary={
                        location.city && location.country ? `${location.city}, ${location.country}` : undefined
                      }
                    />
                  </MenuItem>
                ))}
              </Select>
              <FormHelperText>
                Select one or more locations to associate with this task. Leave empty to remove all locations.
              </FormHelperText>
            </FormControl>
          )}
        </Box>
      </DialogContent>

      <DialogActions>
        <Button onClick={handleClose} disabled={saving}>
          Cancel
        </Button>
        <Button
          onClick={isReadOnly ? warn : handleSave}
          variant="contained"
          color="primary"
          disabled={isReadOnly || saving || loading || allLocations.length === 0 || !task}
        >
          Save
        </Button>
      </DialogActions>
    </Dialog>
  );
};

export default TaskLocationsDialog;
