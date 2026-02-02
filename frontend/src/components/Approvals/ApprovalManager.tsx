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
  IconButton,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  CircularProgress
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
  AccessTime,
  Warning as WarningIcon
} from '@mui/icons-material';
import { DataGrid } from '@mui/x-data-grid';
import type { GridColDef, GridRowSelectionModel } from '@mui/x-data-grid';
import dayjs, { Dayjs } from 'dayjs';
import type { TimesheetManagerRow, TimesheetManagerSummary, Technician, Expense, Project, Task, Location, Timesheet, TravelSegment } from '../../types';
import { useAuth } from '../Auth/AuthContext';
import { useNotification } from '../../contexts/NotificationContext';
import api, { timesheetsApi, projectsApi, tasksApi, locationsApi, fetchWithAuth, API_URL } from '../../services/api';
import { useTenantGuard } from '../../hooks/useTenantGuard';
import { formatTenantDate, formatTenantDateTime, formatTenantTime, getTenantDatePickerFormat } from '../../utils/tenantFormatting';
import TimesheetEditDialog from '../Timesheets/TimesheetEditDialog';
import PageHeader from '../Common/PageHeader';
import { useApprovalCounts } from '../../hooks/useApprovalCounts';
import ConfirmationDialog from '../Common/ConfirmationDialog';
import InputDialog from '../Common/InputDialog';
import ExpenseApprovalPanel from './ExpenseApprovalPanel';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import { useTranslation } from 'react-i18next';

type TabKey = 'timesheets' | 'expenses';

const formatDate = (value: Dayjs) => value.format('YYYY-MM-DD');

const ApprovalManager: React.FC = () => {
  const { t } = useTranslation();
  const emptyValue = t('rightPanel.insights.emptyValue');
  const { isManager, isAdmin, user, tenantContext } = useAuth();
  const { counts } = useApprovalCounts(); // Hook para counts
  useTenantGuard(); // Ensure tenant_slug exists

  const datePickerFormat = useMemo(() => getTenantDatePickerFormat(tenantContext), [tenantContext]);
  // Use permission first (owners may not be in Manager/Admin role but still can approve).
  const canManageTimesheets =
    isManager() ||
    isAdmin() ||
    (Array.isArray((user as any)?.permissions) && (user as any).permissions.includes('approve-timesheets'));
  const { showSuccess, showError } = useNotification();
  const { isReadOnly, ensureWritable } = useReadOnlyGuard('approvals');

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
  const [travelDetailsOpen, setTravelDetailsOpen] = useState(false);
  const [selectedTravels, setSelectedTravels] = useState<TravelSegment[]>([]);
  const [loadingTravels, setLoadingTravels] = useState(false);

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
      const response = await fetchWithAuth(`${API_URL}/api/expenses/pending`);

      if (response.ok) {
        const data = await response.json();
        setExpenses(Array.isArray(data) ? data : []);
      } else {
        console.error('Failed to load pending expenses - Status:', response.status);
        setExpenses([]); // Set empty array on error
        if (response.status !== 404) {
          showError(t('approvals.expenses.loadFailedStatus', { status: response.statusText }));
        }
      }
    } catch (error) {
      console.error('Failed to load pending expenses:', error);
      setExpenses([]); // Set empty array on error
      showError(t('approvals.expenses.loadFailed'));
    } finally {
      setExpenseLoading(false);
    }
  }, [showError, t]);

  useEffect(() => {
    if (tabValue === 'expenses') {
      loadExpensePending();
    }
  }, [tabValue, loadExpensePending]);

  const handleApproveSingle = async (id: number) => {
    if (!ensureWritable()) return;
    try {
      await timesheetsApi.approve(id);
      fetchManagerData();
    } catch (error) {
      console.error('Failed to approve entry:', error);
    }
  };

  const handleRejectSingle = async (id: number) => {
    if (!ensureWritable()) return;
    setInputDialog({
      open: true,
      title: t('approvals.timesheets.rejectEntryTitle'),
      message: t('approvals.timesheets.rejectEntryMessage'),
      action: async (reason: string) => {
        try {
          if (!ensureWritable()) return;
          await timesheetsApi.reject(id, reason);
          fetchManagerData();
          showSuccess(t('approvals.timesheets.rejectSuccessSingle'));
        } catch (error) {
          console.error(error);
          showError(t('approvals.timesheets.rejectFailedSingle'));
        }
        setInputDialog({ ...inputDialog, open: false });
      }
    });
  };

  const handleBulkAction = async (action: 'approve' | 'reject') => {
    if (!ensureWritable()) return;
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
        title: t('approvals.timesheets.ownEntriesWarningTitle'),
        message: t('approvals.timesheets.ownEntriesWarningMessage', {
          ownCount: ownEntries.length,
          totalCount: selectionModel.length,
          validCount,
        }),
        action: async () => {
          const validIds = selectionModel.filter(id => 
            !ownEntries.some(own => own.id === id)
          );
          if (validIds.length === 0) {
            showError(t('approvals.timesheets.ownEntriesCannotApprove'));
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
    if (!ensureWritable()) return;
    if (action === 'reject') {
      // Open input dialog for rejection reason
      setInputDialog({
        open: true,
        title: t('approvals.timesheets.rejectSelectedTitle'),
        message: t('approvals.timesheets.rejectSelectedMessage'),
        action: async (rejectionReason: string) => {
          try {
            if (!ensureWritable()) return;
            for (const id of ids) {
              await timesheetsApi.reject(Number(id), rejectionReason);
            }
            setSelectionModel([]);
            showSuccess(t('approvals.timesheets.rejectSuccess', { count: ids.length }));
            fetchManagerData();
          } catch (error: any) {
            console.error(error);
            
            let errorMessage = t('approvals.timesheets.rejectFailed');
            
            if (error.response?.data?.message) {
              errorMessage = error.response.data.message;
            } else if (error.response?.status === 422) {
              errorMessage = t('approvals.timesheets.rejectInvalidStatus');
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
      showSuccess(t('approvals.timesheets.approveSuccess', { count: ids.length }));
      fetchManagerData();
    } catch (error: any) {
      console.error(error);
      
      let errorMessage = t('approvals.timesheets.approveFailed');
      
      if (error.response?.data?.message) {
        errorMessage = error.response.data.message;
      } else if (error.response?.status === 422) {
        errorMessage = t('approvals.timesheets.approveInvalidStatus');
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
        const response = await fetchWithAuth(`${API_URL}/api/expenses/${id}/approve`, {
          method: 'PUT'
        });

        if (!response.ok) {
          throw new Error(`Failed to approve expense ${id}`);
        }
      }
      
      await loadExpensePending();
      showSuccess(t('approvals.expenses.approveSuccess', { count: expenseIds.length }));
    } catch (error) {
      console.error('Expense approval error:', error);
      throw error;
    }
  };

  const handleExpenseReject = async (expenseIds: number[], reason: string) => {
    try {
      for (const id of expenseIds) {
        const response = await fetchWithAuth(`${API_URL}/api/expenses/${id}/reject`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ rejection_reason: reason })
        });

        if (!response.ok) {
          throw new Error(`Failed to reject expense ${id}`);
        }
      }
      
      await loadExpensePending();
      showSuccess(t('approvals.expenses.rejectSuccess', { count: expenseIds.length }));
    } catch (error) {
      console.error('Expense rejection error:', error);
      throw error;
    }
  };

  const handleExpenseMarkPaid = async (expenseIds: number[], paymentRef: string) => {
    try {
      for (const id of expenseIds) {
        const response = await fetchWithAuth(`${API_URL}/api/expenses/${id}/mark-paid`, {
          method: 'PUT',
          headers: { 'Content-Type': 'application/json' },
          body: JSON.stringify({ payment_reference: paymentRef })
        });

        if (!response.ok) {
          throw new Error(`Failed to mark expense ${id} as paid`);
        }
      }
      
      await loadExpensePending();
      showSuccess(t('approvals.expenses.markPaidSuccess', { count: expenseIds.length }));
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

  // Section 14.2 - Travel cell click handler
  const handleTravelCellClick = async (e: React.MouseEvent, row: TimesheetManagerRow) => {
    e.stopPropagation(); // Prevent row click event from firing
    
    if (!row.travels || row.travels.count === 0) return;
    
    setLoadingTravels(true);
    setTravelDetailsOpen(true);
    
    try {
      let sawUpgradeRequired = false;
      let sawNonUpgradeError = false;

      // Fetch full travel segment details using the segment_ids
      const travelPromises = row.travels.segment_ids.map(async (id) => {
        try {
          const response = await api.get(`/api/travels/${id}`);
          return response.data;
        } catch (error: any) {
          const status = error?.response?.status;
          const data = error?.response?.data;

          if (status === 403 && data?.upgrade_required === true) {
            sawUpgradeRequired = true;
            return null;
          }

          sawNonUpgradeError = true;
          return null;
        }
      });
      
      const travels = await Promise.all(travelPromises);
      setSelectedTravels(travels.filter(Boolean));

      if (sawNonUpgradeError && !sawUpgradeRequired) {
        showError(t('approvals.travels.loadFailed'));
      }
    } catch (error) {
      console.error('Error loading travel details:', error);
      const status = (error as any)?.response?.status;
      const data = (error as any)?.response?.data;
      const isUpgradeRequired = status === 403 && data?.upgrade_required === true;
      if (!isUpgradeRequired) {
        showError(t('approvals.travels.loadFailed'));
      }
    } finally {
      setLoadingTravels(false);
    }
  };

  const getTravelDirectionLabel = (direction?: string | null): string => {
    if (!direction) return t('common.notAvailable');
    return t(`approvals.travels.directions.${direction}`, {
      defaultValue: direction.replace('_', ' ').toUpperCase(),
    });
  };

  const getTravelStatusLabel = (status?: string | null): string => {
    if (!status) return t('common.notAvailable');
    return t(`approvals.travels.statusValues.${status}`, {
      defaultValue: status.replace('_', ' ').toUpperCase(),
    });
  };

  // Section 14.3 - Flag translation helper
  const getFlagLabel = (flag: string): string => {
    const labels: Record<string, string> = {
      'travels_without_work': t('approvals.flags.travelWithoutWork'),
      'excessive_travel_time': t('approvals.flags.excessiveTravelTime'),
      'expenses_without_work': t('approvals.flags.expensesWithoutWork'),
    };
    return labels[flag] || flag;
  };

  const statusChip = (status: string) => {
    const statusLabel = (() => {
      switch (status) {
        case 'draft':
        case 'submitted':
        case 'approved':
        case 'rejected':
        case 'closed':
          return t(`timesheets.status.${status}`);
        default:
          return status;
      }
    })();
    const colors: Record<string, 'success' | 'warning' | 'default' | 'error'> = {
      draft: 'default',
      submitted: 'warning',
      approved: 'success',
      rejected: 'error',
      closed: 'default'
    };
    return <Chip label={statusLabel} color={colors[status] ?? 'default'} size="small" />;
  };

  const columns = useMemo<GridColDef<TimesheetManagerRow>[]>(() => [
    {
      field: 'status',
      headerName: t('approvals.table.status'),
      width: 100,
      renderCell: ({ value }) => statusChip(value as string),
      filterable: true,
    },
    {
      field: 'technician',
      headerName: t('approvals.table.technician'),
      flex: 1,
      minWidth: 140,
      valueGetter: (_value: any, row: any) => row?.technician?.name ?? emptyValue,
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
                titleAccess={t('approvals.timesheets.ownEntryTooltip')}
              />
            )}
            <span style={{ fontWeight: isOwnEntry ? 600 : 400, fontSize: '0.875rem' }}>
              {row?.technician?.name ?? emptyValue}
            </span>
          </Box>
        );
      },
    },
    {
      field: 'technician_project_role',
      headerName: t('approvals.table.projectRole'),
      width: 110,
      renderCell: ({ value }) => {
        if (!value) return emptyValue;
        const roleColors: Record<string, string> = {
          'member': 'default',
          'manager': 'primary',
          'none': 'default',
        };
        const roleLabels: Record<string, string> = {
          'member': t('approvals.roles.member'),
          'manager': t('approvals.roles.manager'),
          'none': t('approvals.roles.none'),
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
      headerName: t('approvals.table.expenseRole'),
      width: 120,
      renderCell: ({ value }) => {
        if (!value) return emptyValue;
        const roleColors: Record<string, string> = {
          'member': 'default',
          'manager': 'secondary',
          'none': 'default',
        };
        const roleLabels: Record<string, string> = {
          'member': t('approvals.roles.member'),
          'manager': t('approvals.roles.manager'),
          'none': t('approvals.roles.none'),
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
      headerName: t('approvals.table.project'),
      flex: 1,
      minWidth: 130,
      valueGetter: (_value: any, row: any) => row?.project?.name ?? emptyValue,
      filterable: true,
    },
    {
      field: 'task',
      headerName: t('approvals.table.task'),
      flex: 1,
      minWidth: 130,
      valueGetter: (_value: any, row: any) => row?.task?.name ?? emptyValue,
      filterable: true,
    },
    {
      field: 'date',
      headerName: t('approvals.table.date'),
      width: 100,
      valueGetter: (value: any) => value ?? '',
      renderCell: ({ value }) => {
        const ymd = typeof value === 'string' ? value.slice(0, 10) : '';
        const label = ymd ? formatTenantDate(ymd, tenantContext) : emptyValue;
        return <Typography variant="body2">{label}</Typography>;
      },
      filterable: true,
    },
    {
      field: 'start_time',
      headerName: t('approvals.table.start'),
      width: 80,
      valueGetter: (value: any) => value ?? null,
      renderCell: ({ value }) => {
        if (!value) return <Typography variant="body2">{emptyValue}</Typography>;
        const formatted = formatTenantTime(value, tenantContext);
        return <Typography variant="body2">{formatted === '-' ? emptyValue : formatted}</Typography>;
      },
      filterable: true,
    },
    {
      field: 'end_time',
      headerName: t('approvals.table.end'),
      width: 80,
      valueGetter: (value: any) => value ?? null,
      renderCell: ({ value }) => {
        if (!value) return <Typography variant="body2">{emptyValue}</Typography>;
        const formatted = formatTenantTime(value, tenantContext);
        return <Typography variant="body2">{formatted === '-' ? emptyValue : formatted}</Typography>;
      },
      filterable: true,
    },
    {
      field: 'hours_worked',
      headerName: t('approvals.table.hours'),
      width: 80,
      valueFormatter: (value: any) => t('common.hoursShort', { value }),
      filterable: true,
    },
    {
      field: 'travels',
      headerName: t('approvals.table.travels'),
      width: 90,
      renderCell: ({ row }) => {
        if (!row.travels || row.travels.count === 0) {
          return <Typography variant="body2" color="text.secondary">{emptyValue}</Typography>;
        }
        return (
          <Box
            sx={{
              display: 'flex',
              alignItems: 'center',
              gap: 0.5,
              cursor: 'pointer',
              '&:hover': { opacity: 0.7 },
            }}
            onClick={(e) => handleTravelCellClick(e, row)}
          >
            <Typography variant="body2" color="primary" fontWeight={600}>
              ✈ {row.travels.count}
            </Typography>
          </Box>
        );
      },
      filterable: false,
    },
    {
      field: 'travel_time',
      headerName: t('approvals.table.travelTime'),
      width: 110,
      valueGetter: (_value: any, row: any) => row?.travels?.duration_formatted ?? emptyValue,
      renderCell: ({ row }) => {
        if (!row.travels || row.travels.count === 0) {
          return <Typography variant="body2" color="text.secondary">{emptyValue}</Typography>;
        }
        return (
          <Typography variant="body2" fontWeight={500}>
            {row.travels.duration_formatted}
          </Typography>
        );
      },
      filterable: false,
    },
    {
      field: 'consistency_flags',
      headerName: t('approvals.table.flags'),
      width: 90,
      renderCell: ({ row }) => {
        const flags = row.consistency_flags || [];
        if (flags.length === 0) {
          return <Chip label={t('approvals.flags.ok')} size="small" color="success" sx={{ fontSize: '0.7rem', height: 20 }} />;
        }
        const hasWarning = flags.some((f: string) => f.includes('travel') || f.includes('expense'));
        const tooltipText = flags.map((f: string) => getFlagLabel(f)).join('\n');
        return (
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
            <WarningIcon 
              sx={{ 
                fontSize: 16, 
                color: hasWarning ? 'warning.main' : 'error.main' 
              }} 
            />
            <Chip
              label={flags.length}
              size="small"
              color={hasWarning ? 'warning' : 'error'}
              sx={{ fontSize: '0.7rem', height: 20, fontWeight: 600 }}
              title={tooltipText}
            />
          </Box>
        );
      },
      filterable: false,
    },
    {
      field: 'ai_score',
      headerName: t('approvals.table.aiScore'),
      width: 90,
      renderCell: ({ row }) => (
        <Chip
          label={row.ai_score !== null && row.ai_score !== undefined ? row.ai_score.toFixed(2) : emptyValue}
          color={row.ai_flagged ? 'error' : 'default'}
          size="small"
          sx={{ fontWeight: 600, fontSize: '0.75rem', height: 24 }}
        />
      ),
      filterable: true,
    },
    {
      field: 'description',
      headerName: t('approvals.table.description'),
      flex: 1.5,
      minWidth: 180,
      valueGetter: (value: any) => value ?? emptyValue,
      filterable: true,
    },
  ], [t, user, tenantContext, handleTravelCellClick, emptyValue]);

  if (!canManageTimesheets) {
    return (
      <Box sx={{ p: 3 }}>
        <Typography variant="h6" color="warning.main">
          {t('approvals.accessDenied')}
        </Typography>
      </Box>
    );
  }

  const renderTimesheetControls = () => (
    <Card sx={{ mb: 1 }}>
      <CardContent sx={{ p: 1.25, '&:last-child': { pb: 1.25 } }}>
        {/* Filter Header */}
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: filtersExpanded ? 1 : 0 }}>
          <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
            <Badge badgeContent={activeFiltersCount} color="primary">
              <FilterList />
            </Badge>
            <Box>
              <Typography variant="body2" fontWeight={600} sx={{ fontSize: '0.875rem' }}>
                {t('approvals.filters.aiAlerts', { count: managerSummary?.flagged_count ?? 0 })}
              </Typography>
              <Typography variant="caption" color="text.secondary">
                {t('approvals.filters.overCapSummary', {
                  overCap: managerSummary?.over_cap_count ?? 0,
                  overlaps: managerSummary?.overlap_count ?? 0,
                })}
              </Typography>
            </Box>
            {activeFiltersCount > 0 && (
              <Chip 
                label={t('approvals.filters.results', { count: filteredRows.length })} 
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
              {t('common.refresh')}
            </Button>
            {activeFiltersCount > 0 && (
              <Button
                size="small"
                startIcon={<Clear />}
                onClick={clearAllFilters}
                sx={{ textTransform: 'none' }}
              >
                {t('common.clearAll')}
              </Button>
            )}
            <IconButton size="small" onClick={() => setFiltersExpanded(!filtersExpanded)}>
              {filtersExpanded ? <ExpandLess /> : <ExpandMore />}
            </IconButton>
          </Box>
        </Box>

        <Collapse in={filtersExpanded}>
          <Grid container spacing={1}>
            {/* Search */}
            <Grid item xs={12} md={6}>
              <TextField
                fullWidth
                size="small"
                placeholder={t('approvals.filters.searchPlaceholder')}
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
                <InputLabel>{t('approvals.filters.sortBy')}</InputLabel>
                <Select
                  value={sortBy}
                  label={t('approvals.filters.sortBy')}
                  onChange={(e) => setSortBy(e.target.value as any)}
                >
                  <MenuItem value="date">{t('approvals.filters.sort.date')}</MenuItem>
                  <MenuItem value="hours">{t('approvals.filters.sort.hours')}</MenuItem>
                  <MenuItem value="project">{t('approvals.filters.sort.project')}</MenuItem>
                  <MenuItem value="technician">{t('approvals.filters.sort.technician')}</MenuItem>
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
                <ToggleButton value="asc">{t('approvals.filters.sortOrder.ascending')}</ToggleButton>
                <ToggleButton value="desc">{t('approvals.filters.sortOrder.descending')}</ToggleButton>
              </ToggleButtonGroup>
            </Grid>

            {/* Date Range */}
            <Grid item xs={12} sm={6} md={3}>
              <DatePicker
                label={t('approvals.filters.fromDate')}
                value={dateFrom}
                onChange={(newValue) => newValue && setDateFrom(newValue)}
                format={datePickerFormat}
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
                label={t('approvals.filters.toDate')}
                value={dateTo}
                onChange={(newValue) => newValue && setDateTo(newValue)}
                format={datePickerFormat}
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
                label={t('approvals.filters.minHours')}
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
                label={t('approvals.filters.maxHours')}
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
          <Typography color="text.secondary">{t('approvals.timesheets.loading')}</Typography>
        </Paper>
      );
    }

    if (filteredRows.length === 0) {
      return (
        <Paper elevation={0} sx={{ p: 4, textAlign: 'center', bgcolor: 'background.default' }}>
          <Check sx={{ fontSize: 64, color: 'success.light', mb: 2 }} />
          <Typography variant="h6" color="text.secondary" gutterBottom>
            {t('approvals.timesheets.emptyTitle')}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {t('approvals.timesheets.emptySubtitle')}
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
            disabled={isReadOnly || !selectionModel.length}
            onClick={() => handleBulkAction('approve')}
            sx={{ px: 2, fontSize: '0.875rem' }}
          >
            {selectionModel.length > 0
              ? t('approvals.timesheets.approveSelected', { count: selectionModel.length })
              : t('common.approve')}
          </Button>
          <Button
            variant="contained"
            color="error"
            size="small"
            startIcon={<Close />}
            disabled={isReadOnly || !selectionModel.length}
            onClick={() => handleBulkAction('reject')}
            sx={{ px: 2, fontSize: '0.875rem' }}
          >
            {selectionModel.length > 0
              ? t('approvals.timesheets.rejectSelected', { count: selectionModel.length })
              : t('common.reject')}
          </Button>
          {selectionModel.length > 0 && (
            <Typography variant="caption" color="text.secondary">
              {t('approvals.timesheets.selectedCount', {
                count: selectionModel.length,
                label: selectionModel.length === 1 ? t('approvals.timesheets.entry') : t('approvals.timesheets.entries'),
              })}
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
        title={t('approvals.title')}
        badges={
          <>
            {counts.total > 0 && (
              <Chip 
                size="small" 
                label={t('approvals.badges.pending', { count: counts.total })} 
                color="info" 
                sx={{ ml: 0.5, height: 20, fontWeight: 600 }} 
              />
            )}
          </>
        }
      />
      
      <Box sx={{ flex: 1, overflow: 'auto', px: 2, py: 1 }}>
        <Tabs
          value={tabValue}
          onChange={(_, value) => setTabValue(value)}
          sx={{ mb: 1, minHeight: '36px', '& .MuiTab-root': { minHeight: '36px', py: 0.5, fontSize: '0.875rem' } }}
        >
          <Tab 
            label={
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5 }}>
                {t('approvals.tabs.timesheets')}
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
                {t('approvals.tabs.expenses')}
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
        readOnly={isReadOnly}
        showApprovalButtons={selectedRow?.status === 'submitted'}
        onApprove={handleApproveTimesheet}
        onReject={handleRejectTimesheet}
      />

      {/* Confirmation Dialog */}
      <ConfirmationDialog
        open={confirmDialog.open}
        title={confirmDialog.title}
        message={confirmDialog.message}
        confirmText={t('common.continue')}
        cancelText={t('common.cancel')}
        confirmColor="warning"
        onConfirm={confirmDialog.action}
        onCancel={() => setConfirmDialog({ ...confirmDialog, open: false })}
      />

      {/* Input Dialog for Rejection Reason */}
      <InputDialog
        open={inputDialog.open}
        title={inputDialog.title}
        message={inputDialog.message}
        label={t('common.rejectionReason')}
        multiline
        rows={3}
        confirmText={t('common.reject')}
        cancelText={t('common.cancel')}
        onConfirm={inputDialog.action}
        onCancel={() => setInputDialog({ ...inputDialog, open: false })}
      />

      {/* Section 14.2 - Travel Details Dialog */}
      <Dialog
        open={travelDetailsOpen}
        onClose={() => setTravelDetailsOpen(false)}
        maxWidth="md"
        fullWidth
      >
        <DialogTitle>{t('approvals.travels.title')}</DialogTitle>
        <DialogContent>
          {loadingTravels ? (
            <Box sx={{ display: 'flex', justifyContent: 'center', p: 3 }}>
              <CircularProgress />
            </Box>
          ) : selectedTravels.length === 0 ? (
            <Typography>{t('approvals.travels.empty')}</Typography>
          ) : (
            <Box sx={{ mt: 2 }}>
              {selectedTravels.map((travel) => (
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
                        {t('approvals.travels.technician')}
                      </Typography>
                      <Typography variant="body1">
                        {travel.technician?.name || t('common.unknown')}
                      </Typography>
                    </Grid>
                    <Grid item xs={12} sm={6}>
                      <Typography variant="subtitle2" color="text.secondary">
                        {t('approvals.travels.project')}
                      </Typography>
                      <Typography variant="body1">
                        {travel.project?.name || t('common.unknown')}
                      </Typography>
                    </Grid>
                    <Grid item xs={12} sm={6}>
                      <Typography variant="subtitle2" color="text.secondary">
                        {t('approvals.travels.departure')}
                      </Typography>
                      <Typography variant="body1">
                        {travel.start_at 
                          ? formatTenantDateTime(travel.start_at, tenantContext)
                          : t('common.notAvailable')}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {travel.origin_city}, {travel.origin_country}
                      </Typography>
                    </Grid>
                    <Grid item xs={12} sm={6}>
                      <Typography variant="subtitle2" color="text.secondary">
                        {t('approvals.travels.arrival')}
                      </Typography>
                      <Typography variant="body1">
                        {travel.end_at 
                          ? formatTenantDateTime(travel.end_at, tenantContext)
                          : t('common.notAvailable')}
                      </Typography>
                      <Typography variant="caption" color="text.secondary">
                        {travel.destination_city}, {travel.destination_country}
                      </Typography>
                    </Grid>
                    <Grid item xs={12} sm={4}>
                      <Typography variant="subtitle2" color="text.secondary">
                        {t('approvals.travels.direction')}
                      </Typography>
                      <Typography variant="body1">
                        {getTravelDirectionLabel(travel.direction)}
                      </Typography>
                    </Grid>
                    <Grid item xs={12} sm={4}>
                      <Typography variant="subtitle2" color="text.secondary">
                        {t('approvals.travels.duration')}
                      </Typography>
                      <Typography variant="body1" fontWeight={600}>
                        {travel.duration_minutes 
                          ? t('approvals.travels.durationValue', {
                              hours: Math.floor(travel.duration_minutes / 60),
                              minutes: travel.duration_minutes % 60,
                            })
                          : t('common.notAvailable')}
                      </Typography>
                    </Grid>
                    <Grid item xs={12} sm={4}>
                      <Typography variant="subtitle2" color="text.secondary">
                        {t('approvals.travels.status')}
                      </Typography>
                      <Chip
                        label={getTravelStatusLabel(travel.status)}
                        size="small"
                        color={travel.status === 'completed' ? 'success' : 'default'}
                      />
                    </Grid>
                    {travel.classification_reason && (
                      <Grid item xs={12}>
                        <Typography variant="subtitle2" color="text.secondary">
                          {t('approvals.travels.classificationReason')}
                        </Typography>
                        <Typography variant="body2">
                          {travel.classification_reason}
                        </Typography>
                      </Grid>
                    )}
                  </Grid>
                </Box>
              ))}
            </Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setTravelDetailsOpen(false)} variant="outlined">
            {t('common.close')}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default ApprovalManager;
