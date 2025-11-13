import React, { useState, useEffect, useMemo, useCallback } from 'react';
import {
  Box,
  Typography,
  Card,
  CardContent,
  Tabs,
  Tab,
  Chip,
  Button,
  Grid,
  TextField,
  Autocomplete,
  Paper,
  Collapse,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  InputAdornment,
  ToggleButtonGroup,
  ToggleButton,
  Badge,
  IconButton
} from '@mui/material';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import { 
  Check, 
  Close, 
  Person as PersonIcon,
  FilterList,
  Search,
  Clear,
  ExpandMore,
  ExpandLess,
  AccessTime
} from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRowSelectionModel } from '@mui/x-data-grid';
import dayjs, { Dayjs } from 'dayjs';
import type { TimesheetManagerRow, TimesheetManagerSummary, Technician, Expense, Project, Task, Location, Timesheet } from '../../types';
import { useAuth } from '../Auth/AuthContext';
import { useNotification } from '../../contexts/NotificationContext';
import { timesheetsApi, projectsApi, tasksApi, locationsApi, fetchWithAuth } from '../../services/api';
import { useTenantGuard } from '../../hooks/useTenantGuard';
import TimesheetEditDialog from '../Timesheets/TimesheetEditDialog';
import PageHeader from '../Common/PageHeader';
import { useApprovalCounts } from '../../hooks/useApprovalCounts';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import InputDialog from '../Common/InputDialog';
import ExpenseApprovalPanel from './ExpenseApprovalPanel';

type TabKey = 'timesheets' | 'expenses';

const formatDate = (value: Dayjs) => value.format('YYYY-MM-DD');

const ApprovalManager: React.FC = () => {
  const { isManager, isAdmin, user } = useAuth();
  const { counts } = useApprovalCounts(); // Hook para counts
  useTenantGuard(); // Ensure tenant_slug exists
  const canManageTimesheets = isManager() || isAdmin();
  const { showSuccess, showError } = useNotification();

  const [tabValue, setTabValue] = useState<TabKey>('timesheets');
  const [managerRows, setManagerRows] = useState<TimesheetManagerRow[]>([]);
  const [managerSummary, setManagerSummary] = useState<TimesheetManagerSummary | null>(null);
  const [managerLoading, setManagerLoading] = useState(false);
  const [dateFrom, setDateFrom] = useState<Dayjs>(dayjs().subtract(1, 'month')); // Last month
  const [dateTo, setDateTo] = useState<Dayjs>(dayjs().add(1, 'month')); // Next month
  const [technicianFilter, setTechnicianFilter] = useState<number[]>([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [minHours, setMinHours] = useState<number | ''>('');
  const [maxHours, setMaxHours] = useState<number | ''>('');
  const [sortBy, setSortBy] = useState<'date' | 'hours' | 'project' | 'technician'>('date');
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');
  const [filtersExpanded, setFiltersExpanded] = useState(true);
  const [allTechnicians, setAllTechnicians] = useState<Technician[]>([]); // Store all available technicians
  const [selectionModel, setSelectionModel] = useState<GridRowSelectionModel>([]);
  const [selectedRow, setSelectedRow] = useState<TimesheetManagerRow | null>(null);
  const [detailsOpen, setDetailsOpen] = useState(false);

  // Confirmation dialog state
  const [confirmDialog, setConfirmDialog] = useState({
    open: false,
    title: '',
    message: '',
    action: (() => {}) as () => void | Promise<void>
  });

  // Input dialog state (for rejection reason)
  const [inputDialog, setInputDialog] = useState({
    open: false,
    title: '',
    message: '',
    action: ((_value: string) => {}) as (value: string) => void | Promise<void>
  });

  // Edit mode states
  const [projects, setProjects] = useState<Project[]>([]);
  const [tasks, setTasks] = useState<Task[]>([]);
  const [locations, setLocations] = useState<Location[]>([]);

  const [expenses, setExpenses] = useState<Expense[]>([]);
  const [expenseLoading, setExpenseLoading] = useState(false);

  // Keep track of all technicians from the full dataset
  const technicianOptions = useMemo(() => {
    const map = new Map<number, Technician>();
    
    // Add from managerRows
    managerRows.forEach((row) => {
      if (row.technician) {
        map.set(row.technician.id, row.technician as Technician);
      }
    });
    
    // Add from allTechnicians state
    allTechnicians.forEach((tech) => {
      if (!map.has(tech.id)) {
        map.set(tech.id, tech);
      }
    });
    
    return Array.from(map.values());
  }, [managerRows, allTechnicians]);

  // Contar filtros ativos
  const activeFiltersCount = useMemo(() => {
    let count = 0;
    if (technicianFilter.length > 0) count++;
    if (searchTerm) count++;
    if (minHours !== '') count++;
    if (maxHours !== '') count++;
    return count;
  }, [technicianFilter, searchTerm, minHours, maxHours]);

  // Limpar todos os filtros
  const clearAllFilters = () => {
    setTechnicianFilter([]);
    setSearchTerm('');
    setMinHours('');
    setMaxHours('');
    setDateFrom(dayjs().subtract(1, 'month'));
    setDateTo(dayjs().add(1, 'month'));
  };

  // Load projects, tasks, and locations for edit mode
  useEffect(() => {
    const loadResources = async () => {
      try {
        const [projectsRes, tasksRes, locationsRes] = await Promise.all([
          projectsApi.getAll(),
          tasksApi.getAll(),
          locationsApi.getAll()
        ]);
        
        // Normalize responses - some APIs return { data: [] }, others return [] directly
        const normalizeResponse = (res: any) => {
          if (Array.isArray(res)) return res;
          if (res?.data && Array.isArray(res.data)) return res.data;
          return [];
        };
        
        setProjects(normalizeResponse(projectsRes));
        setTasks(normalizeResponse(tasksRes));
        setLocations(normalizeResponse(locationsRes));
      } catch (error) {
        console.error('Error loading resources:', error);
      }
    };
    loadResources();
  }, []);

  const fetchManagerData = useCallback(async () => {
    if (!canManageTimesheets) {
      return;
    }

    setManagerLoading(true);

    try {
      // First call: get all technicians (no filter) to populate dropdown
      if (allTechnicians.length === 0) {
        const initialResponse = await timesheetsApi.getManagerView({
          date_from: formatDate(dateFrom),
          date_to: formatDate(dateTo),
          status: 'submitted',
        });
        
        const techMap = new Map<number, Technician>();
        initialResponse.data.forEach((row) => {
          if (row.technician) {
            techMap.set(row.technician.id, row.technician as Technician);
          }
        });
        setAllTechnicians(Array.from(techMap.values()));
      }
      
      // Second call: get filtered data
      const response = await timesheetsApi.getManagerView({
        date_from: formatDate(dateFrom),
        date_to: formatDate(dateTo),
        technician_ids: technicianFilter.length ? technicianFilter : undefined,
        status: 'submitted',
      });

      setManagerRows(response.data);
      setManagerSummary(response.summary);
    } catch (error) {
      console.error('Failed to load manager view:', error);
    } finally {
      setManagerLoading(false);
    }
  }, [canManageTimesheets, dateFrom, dateTo, technicianFilter]);

  useEffect(() => {
    if (tabValue === 'timesheets') {
      fetchManagerData();
    }
  }, [fetchManagerData, tabValue]);

  const loadExpensePending = useCallback(async () => {
    setExpenseLoading(true);
    try {
      const response = await fetchWithAuth('http://localhost:8080/api/expenses/pending');

      if (response.ok) {
        const data = await response.json();
        setExpenses(Array.isArray(data) ? data : []);
      } else {
        console.error('Failed to load pending expenses - Status:', response.status);
        setExpenses([]); // Set empty array on error
        if (response.status !== 404) {
          showError(`Failed to load pending expenses: ${response.statusText}`);
        }
      }
    } catch (error) {
      console.error('Failed to load pending expenses:', error);
      setExpenses([]); // Set empty array on error
      showError('Failed to load pending expenses. Please try again.');
    } finally {
      setExpenseLoading(false);
    }
  }, [showError]);

  useEffect(() => {
    if (tabValue === 'expenses') {
      loadExpensePending();
    }
  }, [tabValue, loadExpensePending]);

  const handleApproveSingle = async (id: number) => {
    try {
      await timesheetsApi.approve(id);
      fetchManagerData();
    } catch (error) {
      console.error('Failed to approve entry:', error);
    }
  };

  const handleRejectSingle = async (id: number) => {
    setInputDialog({
      open: true,
      title: 'Reject Entry',
      message: 'Please provide a reason for rejecting this entry:',
      action: async (reason: string) => {
        try {
          await timesheetsApi.reject(id, reason);
          fetchManagerData();
          showSuccess('Entry rejected successfully');
        } catch (error) {
          console.error(error);
          showError('Failed to reject entry');
        }
        setInputDialog({ ...inputDialog, open: false });
      }
    });
  };

  const handleBulkAction = async (action: 'approve' | 'reject') => {
    if (!selectionModel.length) {
      return;
    }

    // Check if any selected entries belong to the current user
    const selectedRows = managerRows.filter(row => selectionModel.includes(row.id));
    const ownEntries = selectedRows.filter(row => row?.technician?.email === user?.email);
    
    if (ownEntries.length > 0 && action === 'approve') {
      const validCount = selectionModel.length - ownEntries.length;
      setConfirmDialog({
        open: true,
        title: 'Warning: Own Entries Detected',
        message: `${ownEntries.length} of ${selectionModel.length} selected entries are yours. You cannot approve your own timesheets.\n\nContinue with the remaining ${validCount} entries?`,
        action: async () => {
          const validIds = selectionModel.filter(id => 
            !ownEntries.some(own => own.id === id)
          );
          if (validIds.length === 0) {
            showError('Cannot approve your own timesheets.');
            setConfirmDialog({ ...confirmDialog, open: false });
            return;
          }
          setSelectionModel(validIds as GridRowSelectionModel);
          setConfirmDialog({ ...confirmDialog, open: false });
          // Continue with approval
          await executeBulkAction(action, validIds);
        }
      });
      return;
    }

    // For reject action, proceed directly
    if (action === 'reject') {
      await executeBulkAction(action, selectionModel);
    } else {
      await executeBulkAction(action, selectionModel);
    }
  };

  const executeBulkAction = async (action: 'approve' | 'reject', ids: GridRowSelectionModel) => {
    if (action === 'reject') {
      // Open input dialog for rejection reason
      setInputDialog({
        open: true,
        title: 'Reject Selected Entries',
        message: 'Please provide a reason for rejecting the selected entries:',
        action: async (rejectionReason: string) => {
          try {
            for (const id of ids) {
              await timesheetsApi.reject(Number(id), rejectionReason);
            }
            setSelectionModel([]);
            showSuccess(`Successfully rejected ${ids.length} entries`);
            fetchManagerData();
          } catch (error: any) {
            console.error(error);
            
            let errorMessage = 'Bulk rejection failed.';
            
            if (error.response?.status === 403) {
              errorMessage = 'Permission denied. You cannot approve/reject your own timesheets or timesheets from other project managers.';
            } else if (error.response?.status === 422) {
              errorMessage = 'Invalid timesheet status. Only submitted timesheets can be rejected.';
            } else if (error.response?.data?.message) {
              errorMessage = error.response.data.message;
            }
            
            showError(errorMessage);
          }
          setInputDialog({ ...inputDialog, open: false });
        }
      });
      return;
    }

    // For approve action
    try {
      for (const id of ids) {
        await timesheetsApi.approve(Number(id));
      }
      setSelectionModel([]);
      showSuccess(`Successfully approved ${ids.length} entries`);
      fetchManagerData();
    } catch (error: any) {
      console.error(error);
      
      let errorMessage = 'Bulk approval failed.';
      
      if (error.response?.status === 403) {
        errorMessage = 'Permission denied. You cannot approve/reject your own timesheets or timesheets from other project managers.';
      } else if (error.response?.status === 422) {
        errorMessage = 'Invalid timesheet status. Only submitted timesheets can be approved.';
      } else if (error.response?.data?.message) {
        errorMessage = error.response.data.message;
      }
      
      showError(errorMessage);
    }
  };

  const handleRowClick = (params: any) => {
    setSelectedRow(params.row as TimesheetManagerRow);
    setDetailsOpen(true);
  };

  const handleSaveTimesheet = async (data: any) => {
    if (!selectedRow) return;
    await timesheetsApi.update(selectedRow.id, data);
    fetchManagerData(); // Reload data
  };

  const handleDeleteTimesheet = async () => {
    if (!selectedRow) return;
    await timesheetsApi.delete(selectedRow.id);
    setDetailsOpen(false);
    fetchManagerData(); // Reload data
  };

  // ==================== EXPENSE APPROVAL FUNCTIONS ====================
  
  const handleExpenseApprove = async (expenseIds: number[]) => {
    try {
      for (const id of expenseIds) {
        const response = await fetchWithAuth(`http://localhost:8080/api/expenses/${id}/approve`, {
          method: 'PUT'
        });

        if (!response.ok) {
          throw new Error(`Failed to approve expense ${id}`);
        }
      }
      
      await loadExpensePending();
      showSuccess(`${expenseIds.length} expense(s) approved successfully`);
    } catch (error) {
      console.error('Expense approval error:', error);
      throw error;
    }
  };

  const handleExpenseReject = async (expenseIds: number[], reason: string) => {
    try {
      for (const id of expenseIds) {
        const response = await fetchWithAuth(`http://localhost:8080/api/expenses/${id}/reject`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ rejection_reason: reason })
        });

        if (!response.ok) {
          throw new Error(`Failed to reject expense ${id}`);
        }
      }
      
      await loadExpensePending();
      showSuccess(`${expenseIds.length} expense(s) rejected`);
    } catch (error) {
      console.error('Expense rejection error:', error);
      throw error;
    }
  };

  const handleExpenseMarkPaid = async (expenseIds: number[], paymentRef: string) => {
    try {
      for (const id of expenseIds) {
        const response = await fetchWithAuth(`http://localhost:8080/api/expenses/${id}/mark-paid`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ payment_reference: paymentRef })
        });

        if (!response.ok) {
          throw new Error(`Failed to mark expense ${id} as paid`);
        }
      }
      
      await loadExpensePending();
      showSuccess(`${expenseIds.length} expense(s) marked as paid`);
    } catch (error) {
      console.error('Mark paid error:', error);
      throw error;
    }
  };

  // Determine user role for expenses
  const hasFinancePermissions = user?.permissions?.some(p => 
    p === 'approve-finance-expenses' || p === 'mark-expenses-paid' || p === 'review-finance-expenses'
  );
  const isFinanceRole = user?.roles?.includes('Finance');
  
  // Check if user has finance_role: 'manager' in ANY project
  const hasFinanceRoleInProjects = user?.project_memberships?.some(
    membership => membership.finance_role === 'manager'
  );
  
  const expenseUserRole = isAdmin() 
    ? 'admin' 
    : (hasFinancePermissions || isFinanceRole || hasFinanceRoleInProjects ? 'finance' : 'manager');

  const handleApproveTimesheet = async () => {
    if (!selectedRow) return;
    await handleApproveSingle(selectedRow.id);
    setDetailsOpen(false);
  };

  const handleRejectTimesheet = async () => {
    if (!selectedRow) return;
    await handleRejectSingle(selectedRow.id);
    setDetailsOpen(false);
  };

  const statusChip = (status: string) => {
    const colors: Record<string, 'success' | 'warning' | 'default' | 'error'> = {
      draft: 'default',
      submitted: 'warning',
      approved: 'success',
      rejected: 'error',
      closed: 'default'
    };
    return <Chip label={status} color={colors[status] ?? 'default'} size="small" />;
  };

  const columns = useMemo<GridColDef<TimesheetManagerRow>[]>(() => [
    {
      field: 'status',
      headerName: 'Status',
      width: 100,
      renderCell: ({ value }) => statusChip(value as string),
      filterable: true,
    },
    {
      field: 'technician',
      headerName: 'Technician',
      flex: 1,
      minWidth: 140,
      valueGetter: (_value: any, row: any) => row?.technician?.name ?? '—',
      filterable: true,
      renderCell: ({ row }) => {
        const isOwnEntry = row?.technician?.email === user?.email;
        return (
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
            {isOwnEntry && (
              <PersonIcon 
                sx={{ 
                  fontSize: 16, 
                  color: 'warning.main',
                  opacity: 0.7
                }} 
                titleAccess="Your own entry - cannot approve"
              />
            )}
            <span style={{ fontWeight: isOwnEntry ? 600 : 400, fontSize: '0.875rem' }}>
              {row?.technician?.name ?? '—'}
            </span>
          </Box>
        );
      },
    },
    {
      field: 'technician_project_role',
      headerName: 'Project Role',
      width: 110,
      renderCell: ({ value }) => {
        if (!value) return '—';
        const roleColors: Record<string, string> = {
          'member': 'default',
          'manager': 'primary',
          'none': 'default',
        };
        const roleLabels: Record<string, string> = {
          'member': 'Member',
          'manager': 'Manager',
          'none': 'None',
        };
        return (
          <Chip
            label={roleLabels[value] || value}
            color={roleColors[value] as any || 'default'}
            size="small"
            variant={value === 'manager' ? 'filled' : 'outlined'}
            sx={{ fontSize: '0.75rem', height: 22 }}
          />
        );
      },
      filterable: true,
    },
    {
      field: 'technician_expense_role',
      headerName: 'Expense Role',
      width: 120,
      renderCell: ({ value }) => {
        if (!value) return '—';
        const roleColors: Record<string, string> = {
          'member': 'default',
          'manager': 'secondary',
          'none': 'default',
        };
        const roleLabels: Record<string, string> = {
          'member': 'Member',
          'manager': 'Manager',
          'none': 'None',
        };
        return (
          <Chip
            label={roleLabels[value] || value}
            color={roleColors[value] as any || 'default'}
            size="small"
            variant={value === 'manager' ? 'filled' : 'outlined'}
            sx={{ fontSize: '0.75rem', height: 22 }}
          />
        );
      },
      filterable: true,
    },
    {
      field: 'project',
      headerName: 'Project',
      flex: 1,
      minWidth: 130,
      valueGetter: (_value: any, row: any) => row?.project?.name ?? '—',
      filterable: true,
    },
    {
      field: 'task',
      headerName: 'Task',
      flex: 1,
      minWidth: 130,
      valueGetter: (_value: any, row: any) => row?.task?.name ?? '—',
      filterable: true,
    },
    {
      field: 'date',
      headerName: 'Date',
      width: 100,
      valueGetter: (value: any) => value ?? '',
      filterable: true,
    },
    {
      field: 'start_time',
      headerName: 'Start',
      width: 80,
      valueGetter: (value: any) => value ?? '—',
      filterable: true,
    },
    {
      field: 'end_time',
      headerName: 'End',
      width: 80,
      valueGetter: (value: any) => value ?? '—',
      filterable: true,
    },
    {
      field: 'hours_worked',
      headerName: 'Hours',
      width: 80,
      valueFormatter: (value: any) => `${value}h`,
      filterable: true,
    },
    {
      field: 'ai_score',
      headerName: 'AI Score',
      width: 90,
      renderCell: ({ row }) => (
        <Chip
          label={row.ai_score !== null && row.ai_score !== undefined ? row.ai_score.toFixed(2) : '—'}
          color={row.ai_flagged ? 'error' : 'default'}
          size="small"
          sx={{ fontWeight: 600, fontSize: '0.75rem', height: 24 }}
        />
      ),
      filterable: true,
    },
    {
      field: 'description',
      headerName: 'Description',
      flex: 1.5,
      minWidth: 180,
      valueGetter: (value: any) => value ?? '—',
      filterable: true,
    },
  ], []);

  if (!canManageTimesheets) {
    return (
      <Box sx={{ p: 3 }}>
        <Typography variant="h6" color="warning.main">
          Access denied. This page is only available to Managers or Admins.
        </Typography>
      </Box>
    );
  }

  const renderTimesheetControls = () => (
    <Card sx={{ mb: 1.5 }}>
      <CardContent sx={{ p: 1.5, '&:last-child': { pb: 1.5 } }}>
        {/* Filter Header */}
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: filtersExpanded ? 1.5 : 0 }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <Badge badgeContent={activeFiltersCount} color="primary">
              <FilterList />
            </Badge>
            <Box>
              <Typography variant="body2" fontWeight={600} sx={{ fontSize: '0.875rem' }}>
                AI Alerts: {managerSummary?.flagged_count ?? 0}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                Over 12h: {managerSummary?.over_cap_count ?? 0} · Overlaps: {managerSummary?.overlap_count ?? 0}
              </Typography>
            </Box>
            {activeFiltersCount > 0 && (
              <Chip 
                label={`${filteredRows.length} results`} 
                size="small" 
                color="primary" 
                variant="outlined"
              />
            )}
          </Box>
          <Box sx={{ display: 'flex', gap: 1 }}>
            <Button 
              variant="outlined" 
              onClick={fetchManagerData} 
              disabled={managerLoading}
              size="small"
              sx={{ textTransform: 'none' }}
            >
              Refresh
            </Button>
            {activeFiltersCount > 0 && (
              <Button
                size="small"
                startIcon={<Clear />}
                onClick={clearAllFilters}
                sx={{ textTransform: 'none' }}
              >
                Clear All
              </Button>
            )}
            <IconButton size="small" onClick={() => setFiltersExpanded(!filtersExpanded)}>
              {filtersExpanded ? <ExpandLess /> : <ExpandMore />}
            </IconButton>
          </Box>
        </Box>

        <Collapse in={filtersExpanded}>
          <Grid container spacing={1.5}>
            {/* Search */}
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                size="small"
                placeholder="Search technician, project, task, description..."
                value={searchTerm}
                onChange={(e) => setSearchTerm(e.target.value)}
                InputProps={{
                  startAdornment: (
                    <InputAdornment position="start">
                      <Search fontSize="small" />
                    </InputAdornment>
                  ),
                  endAdornment: searchTerm && (
                    <InputAdornment position="end">
                      <IconButton size="small" onClick={() => setSearchTerm('')}>
                        <Clear fontSize="small" />
                      </IconButton>
                    </InputAdornment>
                  )
                }}
              />
            </Grid>

            {/* Sort */}
            <Grid item xs={12} md={3}>
              <FormControl fullWidth size="small">
                <InputLabel>Sort By</InputLabel>
                <Select
                  value={sortBy}
                  label="Sort By"
                  onChange={(e) => setSortBy(e.target.value as any)}
                >
                  <MenuItem value="date">Date</MenuItem>
                  <MenuItem value="hours">Hours</MenuItem>
                  <MenuItem value="project">Project</MenuItem>
                  <MenuItem value="technician">Technician</MenuItem>
                </Select>
              </FormControl>
            </Grid>

            {/* Sort Order */}
            <Grid item xs={12} md={3}>
              <ToggleButtonGroup
                value={sortOrder}
                exclusive
                onChange={(_, val) => val && setSortOrder(val)}
                size="small"
                fullWidth
              >
                <ToggleButton value="asc">Ascending</ToggleButton>
                <ToggleButton value="desc">Descending</ToggleButton>
              </ToggleButtonGroup>
            </Grid>

            {/* Date Range */}
            <Grid item xs={12} sm={6} md={3}>
              <DatePicker
                label="From Date"
                value={dateFrom}
                onChange={(newValue) => newValue && setDateFrom(newValue)}
                format="DD/MM/YYYY"
                slotProps={{
                  textField: {
                    fullWidth: true,
                    size: 'small'
                  }
                }}
              />
            </Grid>
            <Grid item xs={12} sm={6} md={3}>
              <DatePicker
                label="To Date"
                value={dateTo}
                onChange={(newValue) => newValue && setDateTo(newValue)}
                format="DD/MM/YYYY"
                slotProps={{
                  textField: {
                    fullWidth: true,
                    size: 'small'
                  }
                }}
              />
            </Grid>

            {/* Hours Range */}
            <Grid item xs={12} md={3}>
              <TextField
                fullWidth
                size="small"
                type="number"
                label="Min Hours"
                value={minHours}
                onChange={(e) => setMinHours(e.target.value ? parseFloat(e.target.value) : '')}
                InputProps={{
                  startAdornment: <InputAdornment position="start"><AccessTime fontSize="small" /></InputAdornment>
                }}
              />
            </Grid>
            <Grid item xs={12} md={3}>
              <TextField
                fullWidth
                size="small"
                type="number"
                label="Max Hours"
                value={maxHours}
                onChange={(e) => setMaxHours(e.target.value ? parseFloat(e.target.value) : '')}
                InputProps={{
                  startAdornment: <InputAdornment position="start"><AccessTime fontSize="small" /></InputAdornment>
                }}
              />
            </Grid>
          </Grid>
        </Collapse>
      </CardContent>
    </Card>
  );

  // Filter rows by all criteria
  const filteredRows = useMemo(() => {
    let result = managerRows.filter(row => {
      // Search term (technician, project, task, description)
      const searchMatch = !searchTerm || 
        row.technician?.name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        row.project_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        row.task_name?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        row.description?.toLowerCase().includes(searchTerm.toLowerCase());
      
      // Hours range
      const hours = parseFloat(String(row.total_hours)) || 0;
      const minMatch = minHours === '' || hours >= minHours;
      const maxMatch = maxHours === '' || hours <= maxHours;
      
      return searchMatch && minMatch && maxMatch;
    });

    // Sorting
    result.sort((a, b) => {
      let comparison = 0;
      
      switch (sortBy) {
        case 'date':
          comparison = dayjs(a.date).unix() - dayjs(b.date).unix();
          break;
        case 'hours':
          comparison = (parseFloat(String(a.total_hours)) || 0) - (parseFloat(String(b.total_hours)) || 0);
          break;
        case 'project':
          comparison = (a.project_name || '').localeCompare(b.project_name || '');
          break;
        case 'technician':
          comparison = (a.technician?.name || '').localeCompare(b.technician?.name || '');
          break;
      }
      
      return sortOrder === 'asc' ? comparison : -comparison;
    });

    return result;
  }, [managerRows, searchTerm, minHours, maxHours, sortBy, sortOrder]);

  const renderTimesheetTable = () => {
    if (managerLoading) {
      return (
        <Paper elevation={0} sx={{ p: 4, textAlign: 'center' }}>
          <Typography color="text.secondary">Loading timesheets...</Typography>
        </Paper>
      );
    }

    if (filteredRows.length === 0) {
      return (
        <Paper elevation={0} sx={{ p: 4, textAlign: 'center', bgcolor: 'background.default' }}>
          <Check sx={{ fontSize: 64, color: 'success.light', mb: 2 }} />
          <Typography variant="h6" color="text.secondary" gutterBottom>
            No pending approvals
          </Typography>
          <Typography variant="body2" color="text.secondary">
            All timesheets have been reviewed or no submitted entries found
          </Typography>
        </Paper>
      );
    }

    return (
      <Paper elevation={0} sx={{ p: 1.5 }}>
        <Box sx={{ display: 'flex', gap: 1, mb: 1.5, alignItems: 'center', flexWrap: 'wrap' }}>
          <Button
            variant="contained"
            color="success"
            size="small"
            startIcon={<Check />}
            disabled={!selectionModel.length}
            onClick={() => handleBulkAction('approve')}
            sx={{ px: 2, fontSize: '0.875rem' }}
          >
            Approve {selectionModel.length > 0 && `(${selectionModel.length})`}
          </Button>
          <Button
            variant="contained"
            color="error"
            size="small"
            startIcon={<Close />}
            disabled={!selectionModel.length}
            onClick={() => handleBulkAction('reject')}
            sx={{ px: 2, fontSize: '0.875rem' }}
          >
            Reject {selectionModel.length > 0 && `(${selectionModel.length})`}
          </Button>
          {selectionModel.length > 0 && (
            <Typography variant="caption" color="text.secondary">
              {selectionModel.length} {selectionModel.length === 1 ? 'entry' : 'entries'} selected
            </Typography>
          )}
        </Box>
        <DataGrid
          autoHeight
          rows={filteredRows}
          columns={columns}
          checkboxSelection
          disableRowSelectionOnClick
          loading={managerLoading}
          density="compact"
          sx={{
            '& .MuiDataGrid-row': {
              minHeight: '36px !important',
              maxHeight: '36px !important',
            },
            '& .MuiDataGrid-cell': {
              py: 0.5,
              fontSize: '0.875rem',
              alignItems: 'center',
            },
            '& .MuiDataGrid-columnHeaders': {
              minHeight: '40px !important',
              fontSize: '0.875rem',
            },
          }}
          onRowClick={handleRowClick}
          onRowSelectionModelChange={(model) => setSelectionModel(model)}
          rowSelectionModel={selectionModel}
          filterMode="client"
          disableColumnFilter={false}
        />
      </Paper>
    );
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
      <PageHeader
        title="Approvals"
        badges={
          <>
            {counts.total > 0 && (
              <Chip 
                size="small" 
                label={`${counts.total} pending`} 
                color="info" 
                sx={{ ml: 0.5, height: 20, fontWeight: 600 }} 
              />
            )}
          </>
        }
      />
      
      <Box sx={{ flex: 1, overflow: 'auto', p: 1.5 }}>
        <Tabs
          value={tabValue}
          onChange={(_, value) => setTabValue(value)}
          sx={{ mb: 1.5, minHeight: '40px', '& .MuiTab-root': { minHeight: '40px', py: 1 } }}
        >
          <Tab 
            label={
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
                Timesheets
                {counts.timesheets > 0 && (
                  <Chip size="small" label={counts.timesheets} color="error" sx={{ height: 18, fontSize: '0.7rem', minWidth: 18 }} />
                )}
              </Box>
            } 
            value="timesheets" 
          />
          <Tab 
            label={
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
                Expenses
                {counts.expenses > 0 && (
                  <Chip size="small" label={counts.expenses} color="error" sx={{ height: 18, fontSize: '0.7rem', minWidth: 18 }} />
                )}
              </Box>
            } 
            value="expenses" 
          />
        </Tabs>

        {tabValue === 'timesheets' && (
          <>
            {renderTimesheetControls()}
            {renderTimesheetTable()}
          </>
        )}

        {tabValue === 'expenses' && (
          <ExpenseApprovalPanel
            expenses={expenses}
            loading={expenseLoading}
            onApprove={handleExpenseApprove}
            onReject={handleExpenseReject}
            onMarkPaid={handleExpenseMarkPaid}
            userRole={expenseUserRole}
          />
        )}
      </Box>

      {/* Reusable Timesheet Edit Dialog */}
      <TimesheetEditDialog
        open={detailsOpen}
        onClose={() => setDetailsOpen(false)}
        onSave={handleSaveTimesheet}
        onDelete={handleDeleteTimesheet}
        timesheet={selectedRow as unknown as Timesheet | null}
        projects={projects}
        tasks={tasks}
        locations={locations}
        readOnly={false}
        showApprovalButtons={selectedRow?.status === 'submitted'}
        onApprove={handleApproveTimesheet}
        onReject={handleRejectTimesheet}
      />

      {/* Confirmation Dialog */}
      <ConfirmationDialog
        open={confirmDialog.open}
        title={confirmDialog.title}
        message={confirmDialog.message}
        confirmText="Continue"
        cancelText="Cancel"
        confirmColor="warning"
        onConfirm={confirmDialog.action}
        onCancel={() => setConfirmDialog({ ...confirmDialog, open: false })}
      />

      {/* Input Dialog for Rejection Reason */}
      <InputDialog
        open={inputDialog.open}
        title={inputDialog.title}
        message={inputDialog.message}
        label="Rejection Reason"
        multiline
        rows={3}
        confirmText="Reject"
        cancelText="Cancel"
        onConfirm={inputDialog.action}
        onCancel={() => setInputDialog({ ...inputDialog, open: false })}
      />
    </Box>
  );
};

export default ApprovalManager;
