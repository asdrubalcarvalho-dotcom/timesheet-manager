import React, { useState, useMemo } from 'react';
import { API_URL } from '../../services/api';
import {
  Box,
  Card,
  CardContent,
  Typography,
  Chip,
  Button,
  IconButton,
  TextField,
  Grid,
  Avatar,
  Divider,
  Stack,
  Tooltip,
  LinearProgress,
  alpha,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Zoom,
  Collapse,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  InputAdornment,
  ToggleButtonGroup,
  ToggleButton,
  Badge
} from '@mui/material';
import {
  CheckCircle,
  Cancel,
  Pending,
  Receipt,
  CalendarToday,
  Person,
  Business,
  DriveEta,
  LocalGasStation,
  Restaurant,
  Hotel,
  Build,
  MoreHoriz,
  FileDownload,
  TrendingUp,
  AccessTime,
  AccountBalanceWallet,
  Visibility,
  Image as ImageIcon,
  FilterList,
  Search,
  Clear,
  ExpandMore,
  ExpandLess
} from '@mui/icons-material';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs, { Dayjs } from 'dayjs';
import isBetween from 'dayjs/plugin/isBetween';
import type { Expense } from '../../types';
import { useNotification } from '../../contexts/NotificationContext';
import { useReadOnlyGuard } from '../../hooks/useReadOnlyGuard';
import { useAuth } from '../Auth/AuthContext';
import { useTranslation } from 'react-i18next';
import {
  formatTenantDate,
  formatTenantDistanceKm,
  formatTenantMoney,
  formatTenantMoneyPerDistanceKm,
  getTenantDatePickerFormat,
} from '../../utils/tenantFormatting';

dayjs.extend(isBetween);

// Helper function to build attachment URL with auth token and tenant
const getAttachmentUrl = (expenseId: number): string => {
  const token = localStorage.getItem('auth_token');
  const tenant = localStorage.getItem('tenant_slug');
  return `${API_URL}/api/expenses/${expenseId}/attachment?token=${token}&tenant=${tenant}`;
};

interface ExpenseApprovalPanelProps {
  expenses: Expense[];
  loading: boolean;
  onApprove: (expenseIds: number[]) => Promise<void>;
  onReject: (expenseIds: number[], reason: string) => Promise<void>;
  onMarkPaid: (expenseIds: number[], paymentRef: string) => Promise<void>;
  userRole: 'manager' | 'finance' | 'admin';
}

const ExpenseApprovalPanel: React.FC<ExpenseApprovalPanelProps> = ({
  expenses,
  loading,
  onApprove,
  onReject,
  onMarkPaid,
  userRole
}) => {
  const { t } = useTranslation();
  const { showSuccess, showError } = useNotification();
  const { tenantContext } = useAuth();
  const datePickerFormat = getTenantDatePickerFormat(tenantContext);
  const currencySymbol = tenantContext?.currency_symbol || '$';
  const { isReadOnly, ensureWritable } = useReadOnlyGuard('approvals-expenses');
  const [selectedExpenses, setSelectedExpenses] = useState<Set<number>>(new Set());
  const [dateFrom, setDateFrom] = useState<Dayjs>(dayjs().subtract(1, 'month'));
  const [dateTo, setDateTo] = useState<Dayjs>(dayjs().add(1, 'month'));
  const [statusFilter, setStatusFilter] = useState<string[]>(['submitted', 'finance_review', 'finance_approved']);
  const [typeFilter, setTypeFilter] = useState<string[]>([]);
  const [categoryFilter, setCategoryFilter] = useState<string[]>([]);
  const [projectFilter, setProjectFilter] = useState<string[]>([]);
  const [searchTerm, setSearchTerm] = useState('');
  const [minAmount, setMinAmount] = useState<number | ''>('');
  const [maxAmount, setMaxAmount] = useState<number | ''>('');
  const [sortBy, setSortBy] = useState<'date' | 'amount' | 'project'>('date');
  const [sortOrder, setSortOrder] = useState<'asc' | 'desc'>('desc');
  const [filtersExpanded, setFiltersExpanded] = useState(true);
  const [selectedExpense, setSelectedExpense] = useState<Expense | null>(null);
  const [attachmentDialogOpen, setAttachmentDialogOpen] = useState(false);
  const [rejectDialogOpen, setRejectDialogOpen] = useState(false);
  const [rejectReason, setRejectReason] = useState('');
  const [paymentDialogOpen, setPaymentDialogOpen] = useState(false);
  const [paymentReference, setPaymentReference] = useState('');

  // Extrair valores únicos para filtros
  const uniqueCategories = useMemo(() => {
    const categories = new Set(expenses.map(e => e.category).filter(Boolean));
    return Array.from(categories).sort();
  }, [expenses]);

  const uniqueProjects = useMemo(() => {
    const projects = new Set(expenses.map(e => e.project?.name).filter(Boolean));
    return Array.from(projects).sort();
  }, [expenses]);

  // Contar filtros ativos
  const activeFiltersCount = useMemo(() => {
    let count = 0;
    if (statusFilter.length < 4) count++;
    if (typeFilter.length > 0) count++;
    if (categoryFilter.length > 0) count++;
    if (projectFilter.length > 0) count++;
    if (searchTerm) count++;
    if (minAmount !== '') count++;
    if (maxAmount !== '') count++;
    return count;
  }, [statusFilter, typeFilter, categoryFilter, projectFilter, searchTerm, minAmount, maxAmount]);

  // Limpar todos os filtros
  const clearAllFilters = () => {
    setStatusFilter(['submitted', 'finance_review', 'finance_approved']);
    setTypeFilter([]);
    setCategoryFilter([]);
    setProjectFilter([]);
    setSearchTerm('');
    setMinAmount('');
    setMaxAmount('');
    setDateFrom(dayjs().subtract(1, 'month'));
    setDateTo(dayjs().add(1, 'month'));
  };

  // Filtrar expenses
  const filteredExpenses = useMemo(() => {
    let result = expenses.filter(exp => {
      const dateMatch = dayjs(exp.date).isBetween(dateFrom, dateTo, 'day', '[]');
      const statusMatch = statusFilter.length === 0 || statusFilter.includes(exp.status);
      const typeMatch = typeFilter.length === 0 || typeFilter.includes(exp.expense_type);
      const categoryMatch = categoryFilter.length === 0 || categoryFilter.includes(exp.category ?? '');
      const projectMatch = projectFilter.length === 0 || projectFilter.includes(exp.project?.name ?? '');
      
      // Search term (description, category, project name)
      const searchMatch = !searchTerm || 
        exp.description?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        exp.category?.toLowerCase().includes(searchTerm.toLowerCase()) ||
        exp.project?.name?.toLowerCase().includes(searchTerm.toLowerCase());
      
      // Amount range
      const amount = parseFloat(String(exp.amount)) || 0;
      const minMatch = minAmount === '' || amount >= minAmount;
      const maxMatch = maxAmount === '' || amount <= maxAmount;
      
      return dateMatch && statusMatch && typeMatch && categoryMatch && projectMatch && searchMatch && minMatch && maxMatch;
    });

    // Sorting
    result.sort((a, b) => {
      let comparison = 0;
      
      switch (sortBy) {
        case 'date':
          comparison = dayjs(a.date).unix() - dayjs(b.date).unix();
          break;
        case 'amount':
          comparison = (parseFloat(String(a.amount)) || 0) - (parseFloat(String(b.amount)) || 0);
          break;
        case 'project':
          comparison = (a.project?.name || '').localeCompare(b.project?.name || '');
          break;
      }
      
      return sortOrder === 'asc' ? comparison : -comparison;
    });

    return result;
  }, [expenses, dateFrom, dateTo, statusFilter, typeFilter, categoryFilter, projectFilter, searchTerm, minAmount, maxAmount, sortBy, sortOrder]);

  // Agrupar por status
  const expensesByStatus = useMemo(() => {
    const groups: Record<string, Expense[]> = {
      submitted: [],
      finance_review: [],
      finance_approved: [],
      paid: []
    };
    
    filteredExpenses.forEach(exp => {
      if (groups[exp.status]) {
        groups[exp.status].push(exp);
      }
    });
    
    return groups;
  }, [filteredExpenses]);

  // Estatísticas
  const stats = useMemo(() => {
    const total = filteredExpenses.reduce((sum, exp) => sum + (parseFloat(String(exp.amount)) || 0), 0);
    const selected = Array.from(selectedExpenses)
      .map(id => filteredExpenses.find(e => e.id === id))
      .filter(Boolean)
      .reduce((sum, exp) => sum + (parseFloat(String(exp!.amount)) || 0), 0);
    
    return {
      total,
      selected,
      count: filteredExpenses.length,
      selectedCount: selectedExpenses.size
    };
  }, [filteredExpenses, selectedExpenses]);

  // Toggle selection
  const toggleExpense = (id: number) => {
    const newSet = new Set(selectedExpenses);
    if (newSet.has(id)) {
      newSet.delete(id);
    } else {
      newSet.add(id);
    }
    setSelectedExpenses(newSet);
  };

  // Check if expense can be selected based on role and status
  const canSelectExpense = (expense: Expense): boolean => {
    if (userRole === 'admin') return true;
    if (userRole === 'manager') return expense.status === 'submitted';
    if (userRole === 'finance') return ['finance_review', 'finance_approved'].includes(expense.status);
    return false;
  };

  // Select all in status (only selectable ones)
  const selectAllInStatus = (status: string) => {
    const newSet = new Set(selectedExpenses);
    expensesByStatus[status]
      .filter(exp => canSelectExpense(exp))
      .forEach(exp => newSet.add(exp.id));
    setSelectedExpenses(newSet);
  };

  // Deselect all in status
  const deselectAllInStatus = (status: string) => {
    const newSet = new Set(selectedExpenses);
    expensesByStatus[status].forEach(exp => newSet.delete(exp.id));
    setSelectedExpenses(newSet);
  };

  // Check if all selectable expenses in status are selected
  const areAllSelectedInStatus = (status: string): boolean => {
    const selectableInStatus = expensesByStatus[status]?.filter(exp => canSelectExpense(exp)) || [];
    if (selectableInStatus.length === 0) return false;
    return selectableInStatus.every(exp => selectedExpenses.has(exp.id));
  };

  // Clear selection
  const clearSelection = () => setSelectedExpenses(new Set());

  // Handle approve
  const handleApprove = async () => {
    if (!ensureWritable()) {
      return;
    }
    try {
      await onApprove(Array.from(selectedExpenses));
      clearSelection();
      showSuccess(t('approvals.expenses.approveSuccess', { count: selectedExpenses.size }));
    } catch (error) {
      showError(t('approvals.expenses.approveFailed'));
    }
  };

  // Handle reject
  const handleReject = async () => {
    if (!ensureWritable()) {
      return;
    }
    if (!rejectReason.trim()) {
      showError(t('approvals.expenses.rejectionReasonRequired'));
      return;
    }
    
    try {
      await onReject(Array.from(selectedExpenses), rejectReason);
      clearSelection();
      setRejectDialogOpen(false);
      setRejectReason('');
      showSuccess(t('approvals.expenses.rejectSuccess', { count: selectedExpenses.size }));
    } catch (error) {
      showError(t('approvals.expenses.rejectFailed'));
    }
  };

  // Handle mark paid
  const handleMarkPaid = async () => {
    if (!ensureWritable()) {
      return;
    }
    if (!paymentReference.trim()) {
      showError(t('approvals.expenses.paymentReferenceRequired'));
      return;
    }
    
    try {
      await onMarkPaid(Array.from(selectedExpenses), paymentReference);
      clearSelection();
      setPaymentDialogOpen(false);
      setPaymentReference('');
      showSuccess(t('approvals.expenses.markPaidSuccess', { count: selectedExpenses.size }));
    } catch (error) {
      showError(t('approvals.expenses.markPaidFailed'));
    }
  };

  // Get status color
  const getStatusColor = (status: string) => {
    switch (status) {
      case 'submitted': return '#2196f3';
      case 'finance_review': return '#ff9800';
      case 'finance_approved': return '#4caf50';
      case 'paid': return '#9c27b0';
      default: return '#757575';
    }
  };

  // Get status icon
  const getStatusIcon = (status: string) => {
    switch (status) {
      case 'submitted': return <Pending />;
      case 'finance_review': return <AccessTime />;
      case 'finance_approved': return <CheckCircle />;
      case 'paid': return <AccountBalanceWallet />;
      default: return <MoreHoriz />;
    }
  };

  const getStatusLabel = (status: string): string => {
    switch (status) {
      case 'submitted':
        return t('approvals.expenses.status.submitted');
      case 'finance_review':
        return t('approvals.expenses.status.financeReview');
      case 'finance_approved':
        return t('approvals.expenses.status.financeApproved');
      case 'paid':
        return t('approvals.expenses.status.paid');
      default:
        return status.replace('_', ' ').toUpperCase();
    }
  };

  // Get category icon
  const getCategoryIcon = (category: string) => {
    switch (category) {
      case 'fuel': return <LocalGasStation />;
      case 'meals': return <Restaurant />;
      case 'accommodation': return <Hotel />;
      case 'materials': return <Build />;
      default: return <Receipt />;
    }
  };

  // Render expense card
  const renderExpenseCard = (expense: Expense) => {
    const isSelected = selectedExpenses.has(expense.id);
    const canSelect = canSelectExpense(expense);

    return (
      <Zoom in key={expense.id} timeout={300}>
        <Card
          onClick={() => canSelect && toggleExpense(expense.id)}
          sx={{
            position: 'relative',
            cursor: canSelect ? 'pointer' : 'default',
            transition: 'all 0.2s cubic-bezier(0.4, 0, 0.2, 1)',
            border: '2px solid',
            borderColor: isSelected ? getStatusColor(expense.status) : 'transparent',
            bgcolor: isSelected ? alpha(getStatusColor(expense.status), 0.08) : 'background.paper',
            opacity: canSelect ? 1 : 0.7,
            '&:hover': canSelect ? {
              transform: 'translateY(-2px)',
              boxShadow: 4,
              borderColor: getStatusColor(expense.status)
            } : {
              opacity: 0.8
            },
            overflow: 'visible'
          }}
        >
          {/* Badge de seleção */}
          {isSelected && (
            <Box
              sx={{
                position: 'absolute',
                top: -6,
                right: -6,
                zIndex: 10
              }}
            >
              <Avatar
                sx={{
                  width: 24,
                  height: 24,
                  bgcolor: getStatusColor(expense.status),
                  boxShadow: 2
                }}
              >
                <CheckCircle sx={{ fontSize: 16 }} />
              </Avatar>
            </Box>
          )}

          <CardContent sx={{ p: 1.5 }}>
            {/* Header */}
            <Stack direction="row" spacing={1} alignItems="center" mb={1.5}>
              <Avatar
                sx={{
                  bgcolor: alpha(getStatusColor(expense.status), 0.1),
                  color: getStatusColor(expense.status),
                  width: 36,
                  height: 36
                }}
              >
                {expense.expense_type === 'mileage' ? (
                  <DriveEta sx={{ fontSize: 18 }} />
                ) : (
                  getCategoryIcon(expense.category || '')
                )}
              </Avatar>
              
              <Box flex={1} minWidth={0}>
                <Typography variant="caption" fontWeight={600} display="block" lineHeight={1.2}>
                  {expense.expense_type === 'mileage'
                    ? t('approvals.expenses.type.mileageLabel')
                    : t('approvals.expenses.type.reimbursementLabel')}
                </Typography>
                <Typography variant="caption" color="text.secondary" fontSize="0.7rem">
                  {expense.category?.replace('_', ' ').toUpperCase()}
                </Typography>
              </Box>

              <Typography variant="subtitle1" fontWeight={700} color={getStatusColor(expense.status)} fontSize="1rem">
                {formatTenantMoney(parseFloat(String(expense.amount)) || 0, tenantContext)}
              </Typography>
            </Stack>

            <Divider sx={{ my: 1 }} />

            {/* Details Grid */}
            <Grid container spacing={0.75}>
              <Grid item xs={12}>
                <Stack direction="row" spacing={0.5} alignItems="center">
                  <CalendarToday sx={{ fontSize: 14, color: 'text.secondary' }} />
                  <Typography variant="caption" color="text.secondary" fontSize="0.7rem">
                    {formatTenantDate(expense.date, tenantContext)}
                  </Typography>
                </Stack>
              </Grid>
              
              <Grid item xs={12}>
                <Stack direction="row" spacing={0.5} alignItems="center">
                  <Person sx={{ fontSize: 14, color: 'text.secondary' }} />
                  <Typography variant="caption" color="text.secondary" noWrap fontSize="0.7rem">
                    {expense.technician?.name || t('common.notAvailable')}
                  </Typography>
                </Stack>
              </Grid>

              <Grid item xs={12}>
                <Stack direction="row" spacing={0.5} alignItems="center">
                  <Business sx={{ fontSize: 14, color: 'text.secondary' }} />
                  <Typography variant="caption" color="text.secondary" noWrap fontSize="0.7rem">
                    {expense.project?.name || t('common.notAvailable')}
                  </Typography>
                </Stack>
              </Grid>

              {expense.expense_type === 'mileage' && (
                <Grid item xs={12}>
                  <Typography variant="caption" color="text.secondary" fontSize="0.7rem">
                    🛣️ {formatTenantDistanceKm(Number(expense.distance_km ?? 0), tenantContext, 2)} × {formatTenantMoneyPerDistanceKm(Number(expense.rate_per_km ?? 0), tenantContext)} ({expense.vehicle_type})
                  </Typography>
                </Grid>
              )}

              {expense.description && (
                <Grid item xs={12}>
                  <Typography variant="caption" color="text.primary" sx={{ mt: 0.5 }} fontSize="0.75rem" lineHeight={1.3}>
                    {expense.description.length > 60 ? expense.description.substring(0, 60) + '...' : expense.description}
                  </Typography>
                </Grid>
              )}

              {/* Attachment preview (only for non-mileage expenses) */}
              {expense.expense_type !== 'mileage' && expense.attachment_path && (
                <Grid item xs={12}>
                  <Box 
                    sx={{ 
                      mt: 1,
                      border: '1px dashed',
                      borderColor: 'divider',
                      borderRadius: 1,
                      overflow: 'hidden',
                      cursor: 'pointer',
                      position: 'relative',
                      '&:hover': {
                        borderColor: 'primary.main',
                        '& .overlay': {
                          opacity: 1
                        }
                      }
                    }}
                    onClick={(e) => {
                      e.stopPropagation();
                      setSelectedExpense(expense);
                      setAttachmentDialogOpen(true);
                    }}
                  >
                    <Box
                      component="img"
                      src={getAttachmentUrl(expense.id)}
                      alt={t('approvals.expenses.attachment.receiptAlt')}
                      sx={{
                        width: '100%',
                        height: 80,
                        objectFit: 'cover',
                        display: 'block'
                      }}
                    onError={(e) => {
                      // Hide if image fails to load
                      (e.target as HTMLImageElement).style.display = 'none';
                    }}
                    />
                    <Box
                      className="overlay"
                      sx={{
                        position: 'absolute',
                        top: 0,
                        left: 0,
                        right: 0,
                        bottom: 0,
                        bgcolor: 'rgba(0,0,0,0.5)',
                        display: 'flex',
                        alignItems: 'center',
                        justifyContent: 'center',
                        opacity: 0,
                        transition: 'opacity 0.2s'
                      }}
                    >
                      <Stack direction="row" spacing={1} alignItems="center">
                        <Visibility sx={{ color: 'white', fontSize: 18 }} />
                        <Typography variant="caption" sx={{ color: 'white', fontWeight: 600 }}>
                          {t('approvals.expenses.attachment.viewReceipt')}
                        </Typography>
                      </Stack>
                    </Box>
                  </Box>
                </Grid>
              )}
            </Grid>

            {/* Footer */}
            <Box mt={1.5} display="flex" justifyContent="space-between" alignItems="center">
              <Chip
                icon={getStatusIcon(expense.status)}
                label={getStatusLabel(expense.status)}
                size="small"
                sx={{
                  bgcolor: alpha(getStatusColor(expense.status), 0.1),
                  color: getStatusColor(expense.status),
                  fontWeight: 600,
                  fontSize: '0.65rem',
                  height: 20
                }}
              />

              <Stack direction="row" spacing={0.5}>
                {expense.attachment_path && (
                  <Tooltip title={t('approvals.expenses.attachment.viewReceipt')}>
                    <IconButton 
                      size="small" 
                      onClick={(e) => {
                        e.stopPropagation();
                        setSelectedExpense(expense);
                        setAttachmentDialogOpen(true);
                      }} 
                      sx={{ p: 0.5 }}
                    >
                      <Visibility fontSize="small" />
                    </IconButton>
                  </Tooltip>
                )}
                {expense.expense_type !== 'mileage' && !expense.attachment_path && (
                  <Chip 
                    icon={<ImageIcon sx={{ fontSize: 14 }} />}
                    label={t('approvals.expenses.attachment.noReceipt')}
                    size="small"
                    color="warning"
                    sx={{ 
                      height: 20, 
                      fontSize: '0.65rem',
                      '& .MuiChip-icon': { fontSize: 14 }
                    }}
                  />
                )}
              </Stack>
            </Box>
          </CardContent>
        </Card>
      </Zoom>
    );
  };

  // Render status column
  const renderStatusColumn = (status: string, title: string, icon: React.ReactNode, color: string) => {
    const expensesInStatus = expensesByStatus[status] || [];
    const selectableExpenses = expensesInStatus.filter(exp => canSelectExpense(exp));
    const totalAmount = expensesInStatus.reduce((sum, exp) => sum + (parseFloat(String(exp.amount)) || 0), 0);

    return (
      <Box sx={{ minWidth: 280, maxWidth: 350, flex: 1 }}>
        {/* Column Header */}
        <Card
          sx={{
            mb: 2,
            background: `linear-gradient(135deg, ${alpha(color, 0.1)} 0%, ${alpha(color, 0.05)} 100%)`,
            border: `1px solid ${alpha(color, 0.2)}`
          }}
        >
          <CardContent sx={{ p: 1.5 }}>
            <Stack direction="row" spacing={1.5} alignItems="center" mb={1}>
              <Avatar sx={{ bgcolor: color, width: 32, height: 32 }}>
                {icon}
              </Avatar>
              <Box flex={1}>
                <Typography variant="subtitle2" fontWeight={600}>
                  {title}
                </Typography>
                <Typography variant="caption" color="text.secondary">
                  {t('approvals.expenses.countLabel', {
                    count: expensesInStatus.length,
                    label: expensesInStatus.length === 1
                      ? t('approvals.expenses.item')
                      : t('approvals.expenses.items'),
                  })}
                </Typography>
              </Box>
              <Typography variant="h6" fontWeight={700} color={color} fontSize="1.1rem">
                {formatTenantMoney(totalAmount, tenantContext)}
              </Typography>
            </Stack>

            {selectableExpenses.length > 0 && (
              <Button
                size="small"
                variant="outlined"
                fullWidth
                onClick={() => areAllSelectedInStatus(status) ? deselectAllInStatus(status) : selectAllInStatus(status)}
                sx={{ mt: 1, borderColor: color, color, py: 0.5 }}
                startIcon={areAllSelectedInStatus(status) ? <Clear /> : <CheckCircle />}
              >
                {areAllSelectedInStatus(status)
                  ? t('approvals.expenses.deselectAll', { count: selectableExpenses.length })
                  : t('approvals.expenses.selectAll', { count: selectableExpenses.length })
                }
              </Button>
            )}
          </CardContent>
        </Card>

        {/* Expense Cards */}
        <Stack spacing={1.5} sx={{ maxHeight: 'calc(100vh - 420px)', overflow: 'auto', pr: 0.5 }}>
          {expensesInStatus.map(renderExpenseCard)}
        </Stack>
      </Box>
    );
  };

  return (
    <Box sx={{ 
      height: '100vh', 
      display: 'flex', 
      flexDirection: 'column',
      overflow: 'hidden',
      p: 2,
      pt: 0.5
    }}>
      {/* Top Stats Bar */}
      <Card sx={{ mb: 1, background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', flexShrink: 0 }}>
        <CardContent sx={{ py: 1.25 }}>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} md={3}>
              <Stack spacing={0.3}>
                <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                  {t('approvals.expenses.stats.totalValue')}
                </Typography>
                <Typography variant="h5" fontWeight={700} color="white">
                  {formatTenantMoney(stats.total, tenantContext)}
                </Typography>
                <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.7)', fontSize: '0.7rem' }}>
                  {t('approvals.expenses.stats.totalCount', { count: stats.count })}
                </Typography>
              </Stack>
            </Grid>

            {stats.selectedCount > 0 && (
              <>
                <Grid item xs={12} md={3}>
                  <Stack spacing={0.3}>
                    <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                      {t('approvals.expenses.stats.selected')}
                    </Typography>
                    <Typography variant="h6" fontWeight={600} color="white">
                      {formatTenantMoney(stats.selected, tenantContext)}
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.7)', fontSize: '0.7rem' }}>
                      {t('approvals.expenses.stats.selectedCount', { count: stats.selectedCount })}
                    </Typography>
                  </Stack>
                </Grid>

                <Grid item xs={12} md={6}>
                  <Stack direction="row" spacing={1} justifyContent="flex-end">
                    <Button
                      variant="contained"
                      color="error"
                      size="small"
                      startIcon={<Cancel />}
                      onClick={() => {
                        if (!ensureWritable()) {
                          return;
                        }
                        setRejectDialogOpen(true);
                      }}
                      disabled={isReadOnly}
                      sx={{ bgcolor: 'rgba(255,255,255,0.2)', '&:hover': { bgcolor: 'rgba(255,255,255,0.3)' } }}
                    >
                      {t('common.reject')}
                    </Button>
                    
                    {userRole !== 'finance' && (
                      <Button
                        variant="contained"
                        size="small"
                        startIcon={<CheckCircle />}
                        onClick={handleApprove}
                        disabled={isReadOnly}
                        sx={{ bgcolor: 'white', color: '#667eea', '&:hover': { bgcolor: 'rgba(255,255,255,0.9)' } }}
                      >
                        {t('common.approve')}
                      </Button>
                    )}

                    {userRole === 'finance' && (
                      <Button
                        variant="contained"
                        size="small"
                        startIcon={<AccountBalanceWallet />}
                        onClick={() => {
                          if (!ensureWritable()) {
                            return;
                          }
                          setPaymentDialogOpen(true);
                        }}
                        disabled={isReadOnly}
                        sx={{ bgcolor: 'white', color: '#667eea', '&:hover': { bgcolor: 'rgba(255,255,255,0.9)' } }}
                      >
                        {t('approvals.expenses.markPaid')}
                      </Button>
                    )}

                    <Button
                      variant="outlined"
                      size="small"
                      onClick={clearSelection}
                      sx={{ borderColor: 'white', color: 'white' }}
                    >
                      {t('common.clear')}
                    </Button>
                  </Stack>
                </Grid>
              </>
            )}
          </Grid>
        </CardContent>
      </Card>

      {/* Advanced Filters */}
      <Card sx={{ mb: 1 }}>
        <CardContent sx={{ py: 1.25 }}>
          {/* Filter Header */}
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: filtersExpanded ? 1 : 0 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <Badge badgeContent={activeFiltersCount} color="primary">
                <FilterList />
              </Badge>
              <Typography variant="h6" sx={{ fontWeight: 600 }}>
                {t('common.filters')}
              </Typography>
              {activeFiltersCount > 0 && (
                <Chip 
                  label={t('approvals.filters.results', { count: filteredExpenses.length })} 
                  size="small" 
                  color="primary" 
                  variant="outlined"
                />
              )}
            </Box>
            <Box sx={{ display: 'flex', gap: 1 }}>
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
                  placeholder={t('approvals.expenses.searchPlaceholder')}
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
                    <MenuItem value="amount">{t('approvals.filters.sort.amount')}</MenuItem>
                    <MenuItem value="project">{t('approvals.filters.sort.project')}</MenuItem>
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
              <Grid item xs={12} md={3}>
                <DatePicker
                  label={t('approvals.filters.fromDate')}
                  value={dateFrom}
                  onChange={(val) => val && setDateFrom(val)}
                  format={datePickerFormat}
                  slotProps={{ textField: { size: 'small', fullWidth: true } }}
                />
              </Grid>
              <Grid item xs={12} md={3}>
                <DatePicker
                  label={t('approvals.filters.toDate')}
                  value={dateTo}
                  onChange={(val) => val && setDateTo(val)}
                  format={datePickerFormat}
                  slotProps={{ textField: { size: 'small', fullWidth: true } }}
                />
              </Grid>

              {/* Amount Range */}
              <Grid item xs={12} md={3}>
                <TextField
                  fullWidth
                  size="small"
                  type="number"
                  label={t('approvals.expenses.minAmount')}
                  value={minAmount}
                  onChange={(e) => setMinAmount(e.target.value ? parseFloat(e.target.value) : '')}
                  InputProps={{
                    startAdornment: <InputAdornment position="start">{currencySymbol}</InputAdornment>
                  }}
                />
              </Grid>
              <Grid item xs={12} md={3}>
                <TextField
                  fullWidth
                  size="small"
                  type="number"
                  label={t('approvals.expenses.maxAmount')}
                  value={maxAmount}
                  onChange={(e) => setMaxAmount(e.target.value ? parseFloat(e.target.value) : '')}
                  InputProps={{
                    startAdornment: <InputAdornment position="start">{currencySymbol}</InputAdornment>
                  }}
                />
              </Grid>

              {/* Status Filter */}
              <Grid item xs={12} md={3}>
                <FormControl fullWidth size="small">
                  <InputLabel>{t('approvals.expenses.statusLabel')}</InputLabel>
                  <Select
                    multiple
                    value={statusFilter}
                    label={t('approvals.expenses.statusLabel')}
                    onChange={(e) => setStatusFilter(e.target.value as string[])}
                    renderValue={(selected) => (
                      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
                        {selected.map((value) => (
                          <Chip key={value} label={getStatusLabel(value)} size="small" />
                        ))}
                      </Box>
                    )}
                  >
                    <MenuItem value="submitted">{t('approvals.expenses.status.submitted')}</MenuItem>
                    <MenuItem value="finance_review">{t('approvals.expenses.status.financeReview')}</MenuItem>
                    <MenuItem value="finance_approved">{t('approvals.expenses.status.financeApproved')}</MenuItem>
                    <MenuItem value="paid">{t('approvals.expenses.status.paid')}</MenuItem>
                  </Select>
                </FormControl>
              </Grid>

              {/* Expense Type Filter */}
              <Grid item xs={12} md={3}>
                <FormControl fullWidth size="small">
                  <InputLabel>{t('approvals.expenses.typeLabel')}</InputLabel>
                  <Select
                    multiple
                    value={typeFilter}
                    label={t('approvals.expenses.typeLabel')}
                    onChange={(e) => setTypeFilter(e.target.value as string[])}
                    renderValue={(selected) => (
                      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
                        {selected.map((value) => (
                          <Chip key={value} label={t(`approvals.expenses.type.${value}`)} size="small" />
                        ))}
                      </Box>
                    )}
                  >
                    <MenuItem value="reimbursement">{t('approvals.expenses.type.reimbursement')}</MenuItem>
                    <MenuItem value="mileage">{t('approvals.expenses.type.mileage')}</MenuItem>
                    <MenuItem value="company_card">{t('approvals.expenses.type.companyCard')}</MenuItem>
                  </Select>
                </FormControl>
              </Grid>

              {/* Category Filter */}
              <Grid item xs={12} md={3}>
                <FormControl fullWidth size="small">
                  <InputLabel>{t('approvals.expenses.categoryLabel')}</InputLabel>
                  <Select
                    multiple
                    value={categoryFilter}
                    label={t('approvals.expenses.categoryLabel')}
                    onChange={(e) => setCategoryFilter(e.target.value as string[])}
                    renderValue={(selected) => (
                      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
                        {selected.map((value) => (
                          <Chip key={value} label={value} size="small" />
                        ))}
                      </Box>
                    )}
                  >
                    {uniqueCategories.map((cat) => (
                      <MenuItem key={cat} value={cat}>{cat}</MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>

              {/* Project Filter */}
              <Grid item xs={12} md={3}>
                <FormControl fullWidth size="small">
                  <InputLabel>{t('approvals.expenses.projectLabel')}</InputLabel>
                  <Select
                    multiple
                    value={projectFilter}
                    label={t('approvals.expenses.projectLabel')}
                    onChange={(e) => setProjectFilter(e.target.value as string[])}
                    renderValue={(selected) => (
                      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
                        {selected.map((value) => (
                          <Chip key={value} label={value} size="small" />
                        ))}
                      </Box>
                    )}
                  >
                    {uniqueProjects.map((proj) => (
                      <MenuItem key={proj} value={proj}>{proj}</MenuItem>
                    ))}
                  </Select>
                </FormControl>
              </Grid>
            </Grid>
          </Collapse>
        </CardContent>
      </Card>

      {/* Progress */}
      {loading && <LinearProgress sx={{ mb: 2 }} />}

      {/* Kanban Board */}
      <Box sx={{ flex: 1, overflow: 'auto' }}>
        <Stack direction="row" spacing={2} sx={{ minHeight: '100%', pb: 2 }}>
          {renderStatusColumn('submitted', t('approvals.expenses.columns.pendingReview'), <Pending />, '#2196f3')}
          {renderStatusColumn('finance_review', t('approvals.expenses.columns.financeReview'), <TrendingUp />, '#ff9800')}
          {renderStatusColumn('finance_approved', t('approvals.expenses.columns.approved'), <CheckCircle />, '#4caf50')}
          {renderStatusColumn('paid', t('approvals.expenses.columns.paid'), <AccountBalanceWallet />, '#9c27b0')}
        </Stack>
      </Box>

      {/* Reject Dialog */}
      <Dialog 
        open={rejectDialogOpen} 
        onClose={() => setRejectDialogOpen(false)} 
        maxWidth="sm" 
        fullWidth
        disableRestoreFocus
      >
        <DialogTitle>{t('approvals.expenses.rejectDialogTitle')}</DialogTitle>
        <DialogContent>
          <TextField
            autoFocus
            multiline
            rows={4}
            fullWidth
            label={t('common.rejectionReason')}
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
            disabled={isReadOnly}
            sx={{ mt: 2 }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRejectDialogOpen(false)}>{t('common.cancel')}</Button>
          <Button onClick={handleReject} variant="contained" color="error" disabled={isReadOnly}>
            {t('common.reject')}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Payment Dialog */}
      <Dialog 
        open={paymentDialogOpen} 
        onClose={() => setPaymentDialogOpen(false)} 
        maxWidth="sm" 
        fullWidth
        disableRestoreFocus
      >
        <DialogTitle>{t('approvals.expenses.markPaidTitle')}</DialogTitle>
        <DialogContent>
          <TextField
            autoFocus
            fullWidth
            label={t('approvals.expenses.paymentReference')}
            value={paymentReference}
            onChange={(e) => setPaymentReference(e.target.value)}
            placeholder={t('approvals.expenses.paymentReferencePlaceholder')}
            disabled={isReadOnly}
            sx={{ mt: 2 }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setPaymentDialogOpen(false)}>{t('common.cancel')}</Button>
          <Button onClick={handleMarkPaid} variant="contained" color="primary" disabled={isReadOnly}>
            {t('approvals.expenses.markPaid')}
          </Button>
        </DialogActions>
      </Dialog>

      {/* Attachment Viewer Dialog */}
      <Dialog 
        open={attachmentDialogOpen} 
        onClose={() => {
          setAttachmentDialogOpen(false);
          setSelectedExpense(null);
        }} 
        maxWidth="md" 
        fullWidth
      >
        <DialogTitle>
          <Stack direction="row" spacing={2} alignItems="center">
            <Receipt color="primary" />
            <Box flex={1}>
              <Typography variant="h6">{t('approvals.expenses.attachment.title')}</Typography>
              {selectedExpense && (
                <Typography variant="caption" color="text.secondary">
                  {selectedExpense.category?.replace('_', ' ').toUpperCase()} - {formatTenantMoney(parseFloat(String(selectedExpense.amount)) || 0, tenantContext)}
                </Typography>
              )}
            </Box>
          </Stack>
        </DialogTitle>
        <DialogContent>
          {selectedExpense?.attachment_path && (
            <Box sx={{ textAlign: 'center', py: 2 }}>
              {selectedExpense.attachment_path.match(/\.(jpg|jpeg|png|gif|webp)$/i) ? (
                <Box
                  component="img"
                  src={getAttachmentUrl(selectedExpense.id)}
                  alt={t('approvals.expenses.attachment.receiptAlt')}
                  sx={{
                    maxWidth: '100%',
                    maxHeight: '70vh',
                    objectFit: 'contain',
                    borderRadius: 1,
                    boxShadow: 3
                  }}
                />
              ) : selectedExpense.attachment_path.endsWith('.pdf') ? (
                <iframe
                  src={getAttachmentUrl(selectedExpense.id)}
                  title={t('approvals.expenses.attachment.pdfTitle')}
                  allow="fullscreen"
                  style={{ 
                    width: '100%', 
                    height: '70vh', 
                    border: 'none',
                    borderRadius: '8px',
                    boxShadow: '0 4px 6px rgba(0,0,0,0.1)'
                  }}
                />
              ) : (
                <Box sx={{ 
                  p: 8, 
                  bgcolor: 'background.default', 
                  borderRadius: 2
                }}>
                  <Typography variant="h1" sx={{ fontSize: 64, mb: 2 }}>📄</Typography>
                  <Typography variant="body1" color="text.secondary" mb={2}>
                    {t('approvals.expenses.attachment.previewUnavailable')}
                  </Typography>
                  <Button
                    variant="outlined"
                    startIcon={<FileDownload />}
                    href={getAttachmentUrl(selectedExpense.id)}
                    target="_blank"
                  >
                    {t('approvals.expenses.attachment.downloadFile')}
                  </Button>
                </Box>
              )}
              {selectedExpense.description && (
                <Box sx={{ mt: 3, p: 2, bgcolor: 'background.default', borderRadius: 1 }}>
                  <Typography variant="caption" color="text.secondary" display="block" mb={0.5}>
                    {t('approvals.expenses.attachment.descriptionLabel')}
                  </Typography>
                  <Typography variant="body2">
                    {selectedExpense.description}
                  </Typography>
                </Box>
              )}
              <Stack direction="row" spacing={2} sx={{ mt: 3, justifyContent: 'center' }}>
                <Chip icon={<CalendarToday />} label={formatTenantDate(selectedExpense.date, tenantContext)} />
                <Chip icon={<Person />} label={selectedExpense.technician?.name || t('common.notAvailable')} />
                <Chip icon={<Business />} label={selectedExpense.project?.name || t('common.notAvailable')} />
              </Stack>
            </Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button 
            startIcon={<FileDownload />}
            onClick={() => selectedExpense?.id && window.open(getAttachmentUrl(selectedExpense.id), '_blank')}
          >
            {t('approvals.expenses.attachment.download')}
          </Button>
          <Button onClick={() => {
            setAttachmentDialogOpen(false);
            setSelectedExpense(null);
          }} variant="contained">
            {t('common.close')}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default ExpenseApprovalPanel;
