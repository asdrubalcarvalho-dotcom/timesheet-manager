import React, { useState, useMemo } from 'react';
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
  OutlinedInput,
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
  ExpandLess,
  AttachMoney,
  Category as CategoryIcon
} from '@mui/icons-material';
import { DatePicker } from '@mui/x-date-pickers/DatePicker';
import dayjs, { Dayjs } from 'dayjs';
import isBetween from 'dayjs/plugin/isBetween';
import type { Expense } from '../../types';
import { useNotification } from '../../contexts/NotificationContext';

dayjs.extend(isBetween);

// Helper function to build attachment URL with auth token and tenant
const getAttachmentUrl = (expenseId: number): string => {
  const token = localStorage.getItem('auth_token');
  const tenant = localStorage.getItem('tenant_slug');
  return `http://localhost:8080/api/expenses/${expenseId}/attachment?token=${token}&tenant=${tenant}`;
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
  const { showSuccess, showError } = useNotification();
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
      const categoryMatch = categoryFilter.length === 0 || categoryFilter.includes(exp.category);
      const projectMatch = projectFilter.length === 0 || projectFilter.includes(exp.project?.name || '');
      
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
    try {
      await onApprove(Array.from(selectedExpenses));
      clearSelection();
      showSuccess(`${selectedExpenses.size} expense(s) approved successfully`);
    } catch (error) {
      showError('Failed to approve expenses');
    }
  };

  // Handle reject
  const handleReject = async () => {
    if (!rejectReason.trim()) {
      showError('Please provide a rejection reason');
      return;
    }
    
    try {
      await onReject(Array.from(selectedExpenses), rejectReason);
      clearSelection();
      setRejectDialogOpen(false);
      setRejectReason('');
      showSuccess(`${selectedExpenses.size} expense(s) rejected`);
    } catch (error) {
      showError('Failed to reject expenses');
    }
  };

  // Handle mark paid
  const handleMarkPaid = async () => {
    if (!paymentReference.trim()) {
      showError('Please provide a payment reference');
      return;
    }
    
    try {
      await onMarkPaid(Array.from(selectedExpenses), paymentReference);
      clearSelection();
      setPaymentDialogOpen(false);
      setPaymentReference('');
      showSuccess(`${selectedExpenses.size} expense(s) marked as paid`);
    } catch (error) {
      showError('Failed to mark expenses as paid');
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
                  {expense.expense_type === 'mileage' ? '🚗 Mileage' : '💰 Reimbursement'}
                </Typography>
                <Typography variant="caption" color="text.secondary" fontSize="0.7rem">
                  {expense.category?.replace('_', ' ').toUpperCase()}
                </Typography>
              </Box>

              <Typography variant="subtitle1" fontWeight={700} color={getStatusColor(expense.status)} fontSize="1rem">
                €{(parseFloat(String(expense.amount)) || 0).toFixed(2)}
              </Typography>
            </Stack>

            <Divider sx={{ my: 1 }} />

            {/* Details Grid */}
            <Grid container spacing={0.75}>
              <Grid item xs={12}>
                <Stack direction="row" spacing={0.5} alignItems="center">
                  <CalendarToday sx={{ fontSize: 14, color: 'text.secondary' }} />
                  <Typography variant="caption" color="text.secondary" fontSize="0.7rem">
                    {dayjs(expense.date).format('DD MMM YYYY')}
                  </Typography>
                </Stack>
              </Grid>
              
              <Grid item xs={12}>
                <Stack direction="row" spacing={0.5} alignItems="center">
                  <Person sx={{ fontSize: 14, color: 'text.secondary' }} />
                  <Typography variant="caption" color="text.secondary" noWrap fontSize="0.7rem">
                    {expense.technician?.name || '—'}
                  </Typography>
                </Stack>
              </Grid>

              <Grid item xs={12}>
                <Stack direction="row" spacing={0.5} alignItems="center">
                  <Business sx={{ fontSize: 14, color: 'text.secondary' }} />
                  <Typography variant="caption" color="text.secondary" noWrap fontSize="0.7rem">
                    {expense.project?.name || '—'}
                  </Typography>
                </Stack>
              </Grid>

              {expense.expense_type === 'mileage' && (
                <Grid item xs={12}>
                  <Typography variant="caption" color="text.secondary" fontSize="0.7rem">
                    🛣️ {expense.distance_km} km × €{expense.rate_per_km}/km ({expense.vehicle_type})
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
                      alt="Receipt"
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
                          View Receipt
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
                label={expense.status.replace('_', ' ').toUpperCase()}
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
                  <Tooltip title="View Receipt">
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
                    label="No receipt"
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
                  {expensesInStatus.length} expense{expensesInStatus.length !== 1 ? 's' : ''}
                </Typography>
              </Box>
              <Typography variant="h6" fontWeight={700} color={color} fontSize="1.1rem">
                €{totalAmount.toFixed(2)}
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
                  ? `Deselect All (${selectableExpenses.length})` 
                  : `Select All (${selectableExpenses.length})`
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
      pt: 1
    }}>
      {/* Top Stats Bar */}
      <Card sx={{ mb: 2, background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)', flexShrink: 0 }}>
        <CardContent sx={{ py: 1.5 }}>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} md={3}>
              <Stack spacing={0.3}>
                <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                  TOTAL VALUE
                </Typography>
                <Typography variant="h5" fontWeight={700} color="white">
                  €{stats.total.toFixed(2)}
                </Typography>
                <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.7)', fontSize: '0.7rem' }}>
                  {stats.count} expenses
                </Typography>
              </Stack>
            </Grid>

            {stats.selectedCount > 0 && (
              <>
                <Grid item xs={12} md={3}>
                  <Stack spacing={0.3}>
                    <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.8)', fontSize: '0.7rem' }}>
                      SELECTED
                    </Typography>
                    <Typography variant="h6" fontWeight={600} color="white">
                      €{stats.selected.toFixed(2)}
                    </Typography>
                    <Typography variant="caption" sx={{ color: 'rgba(255,255,255,0.7)', fontSize: '0.7rem' }}>
                      {stats.selectedCount} selected
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
                      onClick={() => setRejectDialogOpen(true)}
                      sx={{ bgcolor: 'rgba(255,255,255,0.2)', '&:hover': { bgcolor: 'rgba(255,255,255,0.3)' } }}
                    >
                      Reject
                    </Button>
                    
                    {userRole !== 'finance' && (
                      <Button
                        variant="contained"
                        size="small"
                        startIcon={<CheckCircle />}
                        onClick={handleApprove}
                        sx={{ bgcolor: 'white', color: '#667eea', '&:hover': { bgcolor: 'rgba(255,255,255,0.9)' } }}
                      >
                        Approve
                      </Button>
                    )}

                    {userRole === 'finance' && (
                      <Button
                        variant="contained"
                        size="small"
                        startIcon={<AccountBalanceWallet />}
                        onClick={() => setPaymentDialogOpen(true)}
                        sx={{ bgcolor: 'white', color: '#667eea', '&:hover': { bgcolor: 'rgba(255,255,255,0.9)' } }}
                      >
                        Mark Paid
                      </Button>
                    )}

                    <Button
                      variant="outlined"
                      size="small"
                      onClick={clearSelection}
                      sx={{ borderColor: 'white', color: 'white' }}
                    >
                      Clear
                    </Button>
                  </Stack>
                </Grid>
              </>
            )}
          </Grid>
        </CardContent>
      </Card>

      {/* Advanced Filters */}
      <Card sx={{ mb: 2 }}>
        <CardContent sx={{ py: 2 }}>
          {/* Filter Header */}
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', mb: filtersExpanded ? 2 : 0 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <Badge badgeContent={activeFiltersCount} color="primary">
                <FilterList />
              </Badge>
              <Typography variant="h6" sx={{ fontWeight: 600 }}>
                Filters
              </Typography>
              {activeFiltersCount > 0 && (
                <Chip 
                  label={`${filteredExpenses.length} results`} 
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
                  Clear All
                </Button>
              )}
              <IconButton size="small" onClick={() => setFiltersExpanded(!filtersExpanded)}>
                {filtersExpanded ? <ExpandLess /> : <ExpandMore />}
              </IconButton>
            </Box>
          </Box>

          <Collapse in={filtersExpanded}>
            <Grid container spacing={2}>
              {/* Search */}
              <Grid item xs={12} md={6}>
                <TextField
                  fullWidth
                  size="small"
                  placeholder="Search description, category, project..."
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
                    <MenuItem value="amount">Amount</MenuItem>
                    <MenuItem value="project">Project</MenuItem>
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
              <Grid item xs={12} md={3}>
                <DatePicker
                  label="From Date"
                  value={dateFrom}
                  onChange={(val) => val && setDateFrom(val)}
                  slotProps={{ textField: { size: 'small', fullWidth: true } }}
                />
              </Grid>
              <Grid item xs={12} md={3}>
                <DatePicker
                  label="To Date"
                  value={dateTo}
                  onChange={(val) => val && setDateTo(val)}
                  slotProps={{ textField: { size: 'small', fullWidth: true } }}
                />
              </Grid>

              {/* Amount Range */}
              <Grid item xs={12} md={3}>
                <TextField
                  fullWidth
                  size="small"
                  type="number"
                  label="Min Amount"
                  value={minAmount}
                  onChange={(e) => setMinAmount(e.target.value ? parseFloat(e.target.value) : '')}
                  InputProps={{
                    startAdornment: <InputAdornment position="start">€</InputAdornment>
                  }}
                />
              </Grid>
              <Grid item xs={12} md={3}>
                <TextField
                  fullWidth
                  size="small"
                  type="number"
                  label="Max Amount"
                  value={maxAmount}
                  onChange={(e) => setMaxAmount(e.target.value ? parseFloat(e.target.value) : '')}
                  InputProps={{
                    startAdornment: <InputAdornment position="start">€</InputAdornment>
                  }}
                />
              </Grid>

              {/* Status Filter */}
              <Grid item xs={12} md={3}>
                <FormControl fullWidth size="small">
                  <InputLabel>Status</InputLabel>
                  <Select
                    multiple
                    value={statusFilter}
                    label="Status"
                    onChange={(e) => setStatusFilter(e.target.value as string[])}
                    renderValue={(selected) => (
                      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
                        {selected.map((value) => (
                          <Chip key={value} label={value.replace('_', ' ')} size="small" />
                        ))}
                      </Box>
                    )}
                  >
                    <MenuItem value="submitted">Submitted</MenuItem>
                    <MenuItem value="finance_review">Finance Review</MenuItem>
                    <MenuItem value="finance_approved">Finance Approved</MenuItem>
                    <MenuItem value="paid">Paid</MenuItem>
                  </Select>
                </FormControl>
              </Grid>

              {/* Expense Type Filter */}
              <Grid item xs={12} md={3}>
                <FormControl fullWidth size="small">
                  <InputLabel>Type</InputLabel>
                  <Select
                    multiple
                    value={typeFilter}
                    label="Type"
                    onChange={(e) => setTypeFilter(e.target.value as string[])}
                    renderValue={(selected) => (
                      <Box sx={{ display: 'flex', flexWrap: 'wrap', gap: 0.5 }}>
                        {selected.map((value) => (
                          <Chip key={value} label={value.replace('_', ' ')} size="small" />
                        ))}
                      </Box>
                    )}
                  >
                    <MenuItem value="reimbursement">Reimbursement</MenuItem>
                    <MenuItem value="mileage">Mileage</MenuItem>
                    <MenuItem value="company_card">Company Card</MenuItem>
                  </Select>
                </FormControl>
              </Grid>

              {/* Category Filter */}
              <Grid item xs={12} md={3}>
                <FormControl fullWidth size="small">
                  <InputLabel>Category</InputLabel>
                  <Select
                    multiple
                    value={categoryFilter}
                    label="Category"
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
                  <InputLabel>Project</InputLabel>
                  <Select
                    multiple
                    value={projectFilter}
                    label="Project"
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
          {renderStatusColumn('submitted', 'Pending Review', <Pending />, '#2196f3')}
          {renderStatusColumn('finance_review', 'Finance Review', <TrendingUp />, '#ff9800')}
          {renderStatusColumn('finance_approved', 'Approved', <CheckCircle />, '#4caf50')}
          {renderStatusColumn('paid', 'Paid', <AccountBalanceWallet />, '#9c27b0')}
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
        <DialogTitle>Reject Expenses</DialogTitle>
        <DialogContent>
          <TextField
            autoFocus
            multiline
            rows={4}
            fullWidth
            label="Rejection Reason"
            value={rejectReason}
            onChange={(e) => setRejectReason(e.target.value)}
            sx={{ mt: 2 }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setRejectDialogOpen(false)}>Cancel</Button>
          <Button onClick={handleReject} variant="contained" color="error">
            Reject
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
        <DialogTitle>Mark as Paid</DialogTitle>
        <DialogContent>
          <TextField
            autoFocus
            fullWidth
            label="Payment Reference"
            value={paymentReference}
            onChange={(e) => setPaymentReference(e.target.value)}
            placeholder="e.g., TRF-2025-001234"
            sx={{ mt: 2 }}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setPaymentDialogOpen(false)}>Cancel</Button>
          <Button onClick={handleMarkPaid} variant="contained" color="primary">
            Mark Paid
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
              <Typography variant="h6">Receipt Attachment</Typography>
              {selectedExpense && (
                <Typography variant="caption" color="text.secondary">
                  {selectedExpense.category?.replace('_', ' ').toUpperCase()} - €{(parseFloat(String(selectedExpense.amount)) || 0).toFixed(2)}
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
                  alt="Receipt"
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
                  title="PDF Receipt"
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
                    Preview not available for this file type
                  </Typography>
                  <Button
                    variant="outlined"
                    startIcon={<FileDownload />}
                    href={getAttachmentUrl(selectedExpense.id)}
                    target="_blank"
                  >
                    Download File
                  </Button>
                </Box>
              )}
              {selectedExpense.description && (
                <Box sx={{ mt: 3, p: 2, bgcolor: 'background.default', borderRadius: 1 }}>
                  <Typography variant="caption" color="text.secondary" display="block" mb={0.5}>
                    DESCRIPTION
                  </Typography>
                  <Typography variant="body2">
                    {selectedExpense.description}
                  </Typography>
                </Box>
              )}
              <Stack direction="row" spacing={2} sx={{ mt: 3, justifyContent: 'center' }}>
                <Chip icon={<CalendarToday />} label={dayjs(selectedExpense.date).format('DD MMM YYYY')} />
                <Chip icon={<Person />} label={selectedExpense.technician?.name || '—'} />
                <Chip icon={<Business />} label={selectedExpense.project?.name || '—'} />
              </Stack>
            </Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button 
            startIcon={<FileDownload />}
            onClick={() => selectedExpense?.id && window.open(getAttachmentUrl(selectedExpense.id), '_blank')}
          >
            Download
          </Button>
          <Button onClick={() => {
            setAttachmentDialogOpen(false);
            setSelectedExpense(null);
          }} variant="contained">
            Close
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default ExpenseApprovalPanel;
