import React, { useState, useEffect } from 'react';
import {
  Box,
  Button,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  TextField,
  MenuItem,
  Chip,
  IconButton,
  Fab,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
  TablePagination,
  Typography
} from '@mui/material';
import { Edit, Delete, Add, AttachFile, ErrorOutline } from '@mui/icons-material';
import PageHeader from '../Common/PageHeader';
import InputDialog from '../Common/InputDialog';
import { useNotification } from '../../contexts/NotificationContext';

interface Project {
  id: number;
  name: string;
}

interface ExpenseEntry {
  id?: number;
  project_id: number;
  date: string;
  amount: number | string;
  category: string;
  description: string;
  attachment_path?: string;
  status: 'draft' | 'submitted' | 'approved' | 'rejected' | 'finance_review' | 'finance_approved' | 'paid';
  expense_type: 'reimbursement' | 'mileage' | 'company_card';
  distance_km?: number;
  rate_per_km?: number;
  vehicle_type?: string;
  rejection_reason?: string;
  project?: Project;
}

export const ExpenseManager: React.FC = () => {
  const { showSuccess, showError, showWarning } = useNotification();
  const [expenses, setExpenses] = useState<ExpenseEntry[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [selectedExpense, setSelectedExpense] = useState<ExpenseEntry | null>(null);
  const [loading, setLoading] = useState(false);

  // Input dialog state (for rejection reason)
  const [inputDialog, setInputDialog] = useState({
    open: false,
    title: '',
    message: '',
    action: ((_value: string) => {}) as (value: string) => void | Promise<void>
  });

  // Form states
  const [projectId, setProjectId] = useState<number>(0);
  const [date, setDate] = useState('');
  const [amount, setAmount] = useState<number>(0);
  const [category, setCategory] = useState('General');
  const [description, setDescription] = useState('');
  const [expenseType, setExpenseType] = useState<'reimbursement' | 'mileage' | 'company_card'>('reimbursement');
  const [distanceKm, setDistanceKm] = useState<number>(0);
  const [ratePerKm, setRatePerKm] = useState<number>(0.36); // Default rate
  const [vehicleType, setVehicleType] = useState('car');
  const [attachmentFile, setAttachmentFile] = useState<File | null>(null);

  // Load data on mount
  useEffect(() => {
    loadExpenses();
    loadProjects();
  }, []);

  const getAuthHeaders = () => ({
    'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  });

  const loadExpenses = async () => {
    try {
      setLoading(true);
      const response = await fetch('http://localhost:8080/api/expenses', {
        headers: getAuthHeaders()
      });
      
      if (response.ok) {
        const data = await response.json();
        setExpenses(data);
      } else {
        const errorText = await response.text();
        console.error('Failed to load expenses - Status:', response.status, 'Error:', errorText);
      }
    } catch (error) {
      console.error('Failed to load expenses:', error);
    } finally {
      setLoading(false);
    }
  };

  const loadProjects = async () => {
    try {
      // Get only projects where user is member or manager (same as timesheets)
      const url = 'http://localhost:8080/api/projects?my_projects=true';
      console.log('Loading projects from:', url);
      const response = await fetch(url, {
        headers: getAuthHeaders()
      });
      
      if (response.ok) {
        const result = await response.json();
        console.log('Projects response:', result);
        console.log('Projects count:', result.length);
        // API returns array directly
        setProjects(result);
      }
    } catch (error) {
      console.error('Failed to load projects:', error);
    }
  };

  const handleAddNew = () => {
    setSelectedExpense(null);
    resetForm();
    setDialogOpen(true);
  };

  const handleEdit = (expense: ExpenseEntry) => {
    setSelectedExpense(expense);
    setProjectId(expense.project_id);
    // Convert ISO date to YYYY-MM-DD format for input type="date"
    const dateOnly = expense.date.split('T')[0];
    setDate(dateOnly);
    setAmount(Number(expense.amount));
    setCategory(expense.category || 'General');
    setDescription(expense.description || '');
    setExpenseType(expense.expense_type || 'reimbursement');
    setDistanceKm(expense.distance_km || 0);
    setRatePerKm(expense.rate_per_km || 0.36);
    setVehicleType(expense.vehicle_type || 'car');
    setAttachmentFile(null);
    setDialogOpen(true);
  };

  const resetForm = () => {
    setProjectId(0);
    setDate(new Date().toISOString().split('T')[0]);
    setAmount(0);
    setCategory('General');
    setDescription('');
    setExpenseType('reimbursement');
    setDistanceKm(0);
    setRatePerKm(0.36);
    setVehicleType('car');
    setAttachmentFile(null);
  };

  const handleSave = async () => {
    if (!projectId || !date) {
      showWarning('Please fill in all required fields');
      return;
    }

    const formData = new FormData();
    formData.append('project_id', projectId.toString());
    formData.append('date', date);
    formData.append('category', category);
    formData.append('description', description);
    formData.append('expense_type', expenseType);
    
    if (expenseType === 'mileage') {
      formData.append('distance_km', distanceKm.toString());
      formData.append('rate_per_km', ratePerKm.toString());
      formData.append('vehicle_type', vehicleType);
    } else {
      formData.append('amount', amount.toString());
    }
    
    if (attachmentFile) {
      formData.append('attachment', attachmentFile);
    }

    try {
      let response;
      if (selectedExpense?.id) {
        // Update existing expense - Laravel requires _method field for file uploads
        formData.append('_method', 'PUT');
        response = await fetch(`http://localhost:8080/api/expenses/${selectedExpense.id}`, {
          method: 'POST', // POST with _method=PUT for FormData file uploads
          headers: {
            'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
            'Accept': 'application/json'
          },
          body: formData
        });
      } else {
        // Create new expense
        response = await fetch('http://localhost:8080/api/expenses', {
          method: 'POST',
          headers: {
            'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
            'Accept': 'application/json'
          },
          body: formData
        });
      }

      if (response.ok) {
        showSuccess(selectedExpense ? 'Expense updated successfully' : 'Expense created successfully');
        loadExpenses();
        setDialogOpen(false);
        resetForm();
      } else {
        const errorData = await response.json();
        console.error('Validation errors:', errorData);
        
        // Extract validation errors
        if (errorData.errors) {
          const errorMessages = Object.entries(errorData.errors)
            .map(([field, messages]: [string, any]) => `${field}: ${messages.join(', ')}`)
            .join('\n');
          showError(`Validation failed:\n${errorMessages}`);
        } else if (errorData.message) {
          showError(errorData.message);
        } else {
          showError('Failed to save expense');
        }
      }
    } catch (error) {
      console.error('Save error:', error);
      showError('Network error: Failed to save expense');
    }
  };

  const handleDelete = async (expenseId: number) => {
    try {
      const response = await fetch(`http://localhost:8080/api/expenses/${expenseId}`, {
        method: 'DELETE',
        headers: getAuthHeaders()
      });

      if (response.ok) {
        showSuccess('Expense deleted successfully');
        loadExpenses();
      } else {
        const errorData = await response.json();
        showError(errorData.message || 'Failed to delete expense');
      }
    } catch (error) {
      console.error('Failed to delete expense:', error);
      showError('Network error: Failed to delete expense');
    }
  };

  const handleSubmit = async () => {
    if (!selectedExpense?.id) return;
    
    try {
      const response = await fetch(`http://localhost:8080/api/expenses/${selectedExpense.id}/submit`, {
        method: 'PUT',
        headers: getAuthHeaders()
      });

      if (response.ok) {
        loadExpenses();
        setDialogOpen(false);
      } else {
        console.error('Failed to submit expense');
      }
    } catch (error) {
      console.error('Failed to submit expense:', error);
    }
  };

  const handleApprove = async () => {
    if (!selectedExpense?.id) return;
    
    try {
      const response = await fetch(`http://localhost:8080/api/expenses/${selectedExpense.id}/approve`, {
        method: 'PUT',
        headers: getAuthHeaders()
      });

      if (response.ok) {
        loadExpenses();
        setDialogOpen(false);
      } else {
        console.error('Failed to approve expense');
      }
    } catch (error) {
      console.error('Failed to approve expense:', error);
    }
  };

  const handleReject = async () => {
    if (!selectedExpense?.id) return;
    
    setInputDialog({
      open: true,
      title: 'Reject Expense',
      message: 'Please provide a reason for rejecting this expense:',
      action: async (reason: string) => {
        try {
          const response = await fetch(`http://localhost:8080/api/expenses/${selectedExpense.id}/reject`, {
            method: 'PUT',
            headers: {
              ...getAuthHeaders(),
              'Content-Type': 'application/json'
            },
            body: JSON.stringify({ reason })
          });

          if (response.ok) {
            loadExpenses();
            setDialogOpen(false);
          } else {
            console.error('Failed to reject expense');
          }
        } catch (error) {
          console.error('Failed to reject expense:', error);
        }
        setInputDialog({ ...inputDialog, open: false });
      }
    });
  };

  const getStatusChip = (status: string) => {
    const statusConfig = {
      draft: { color: 'default' as const, label: 'Draft' },
      submitted: { color: 'info' as const, label: 'Submitted' },
      approved: { color: 'success' as const, label: 'Approved (Legacy)' },
      rejected: { color: 'error' as const, label: 'Rejected' },
      finance_review: { color: 'warning' as const, label: 'Finance Review' },
      finance_approved: { color: 'success' as const, label: 'Finance Approved' },
      paid: { color: 'success' as const, label: 'Paid' },
    };
    
    const config = statusConfig[status as keyof typeof statusConfig] || { color: 'default' as const, label: status };
    return <Chip label={config.label} color={config.color} size="small" />;
  };

  const [page, setPage] = useState(0);
  const [rowsPerPage, setRowsPerPage] = useState(10);

  const handleChangePage = (_event: unknown, newPage: number) => {
    setPage(newPage);
  };

  const handleChangeRowsPerPage = (event: React.ChangeEvent<HTMLInputElement>) => {
    setRowsPerPage(parseInt(event.target.value, 10));
    setPage(0);
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
        title="Expenses"
        subtitle="Submit expenses with receipts for project-related costs"
        actions={
          <Button
            variant="contained"
            startIcon={<Add />}
            onClick={handleAddNew}
            sx={{
              bgcolor: 'rgba(255,255,255,0.2)',
              '&:hover': {
                bgcolor: 'rgba(255,255,255,0.3)'
              }
            }}
          >
            Add Expense
          </Button>
        }
      />

      <Box sx={{ flex: 1, overflow: 'auto', p: 2 }}>
        {loading && (
          <Typography sx={{ mb: 2, color: 'info.main' }}>
            Loading expenses...
          </Typography>
        )}

      <TableContainer component={Paper}>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>ID</TableCell>
              <TableCell>Date</TableCell>
              <TableCell>Project</TableCell>
              <TableCell>Amount</TableCell>
              <TableCell>Description</TableCell>
              <TableCell>Attachment</TableCell>
              <TableCell>Status</TableCell>
              <TableCell>Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {expenses
              .slice(page * rowsPerPage, page * rowsPerPage + rowsPerPage)
              .map((expense) => (
                <TableRow key={expense.id}>
                  <TableCell>{expense.id}</TableCell>
                  <TableCell>
                    {new Date(expense.date).toLocaleDateString()}
                  </TableCell>
                  <TableCell>
                    {expense.project?.name || 'Unknown'}
                  </TableCell>
                  <TableCell>
                    ${Number(expense.amount).toFixed(2)}
                  </TableCell>
                  <TableCell>{expense.description}</TableCell>
                  <TableCell>
                    {expense.attachment_path && <AttachFile color="primary" />}
                  </TableCell>
                  <TableCell>
                    <Box>
                      {getStatusChip(expense.status)}
                      {expense.status === 'rejected' && expense.rejection_reason && (
                        <Box sx={{ mt: 1 }}>
                          <Typography variant="caption" color="error" display="block" sx={{ fontStyle: 'italic' }}>
                            Reason: {expense.rejection_reason}
                          </Typography>
                        </Box>
                      )}
                    </Box>
                  </TableCell>
                  <TableCell>
                    <IconButton
                      onClick={() => handleEdit(expense)}
                      disabled={expense.status === 'approved'}
                      size="small"
                    >
                      <Edit />
                    </IconButton>
                    <IconButton
                      onClick={() => handleDelete(expense.id!)}
                      disabled={expense.status === 'approved'}
                      size="small"
                      color="error"
                    >
                      <Delete />
                    </IconButton>
                  </TableCell>
                </TableRow>
              ))}
          </TableBody>
        </Table>
        <TablePagination
          rowsPerPageOptions={[5, 10, 25]}
          component="div"
          count={expenses.length}
          rowsPerPage={rowsPerPage}
          page={page}
          onPageChange={handleChangePage}
          onRowsPerPageChange={handleChangeRowsPerPage}
        />
      </TableContainer>

      {/* Floating Action Button for mobile */}
      <Fab
        color="primary"
        aria-label="add expense"
        sx={{ position: 'fixed', bottom: 80, right: 16, display: { md: 'none' } }}
        onClick={handleAddNew}
      >
        <Add />
      </Fab>

      {/* Expense Entry Dialog */}
      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>
          <Box>
            <Typography variant="h6" component="span">
              {selectedExpense ? 'Edit Expense' : 'New Expense'}
            </Typography>
            {selectedExpense && (
              <Box sx={{ mt: 1, display: 'flex', alignItems: 'center', gap: 1 }}>
                <Typography variant="body2" color="text.secondary">
                  Current Status:
                </Typography>
                {getStatusChip(selectedExpense.status)}
              </Box>
            )}
          </Box>
        </DialogTitle>
        <DialogContent>
          {selectedExpense?.status === 'rejected' && selectedExpense.rejection_reason && (
            <Box 
              sx={{ 
                mt: 2,
                mb: 3,
                p: 2,
                borderRadius: 2,
                bgcolor: 'error.light',
                border: '1px solid',
                borderColor: 'error.main',
                display: 'flex',
                gap: 1.5,
                alignItems: 'flex-start',
                background: 'linear-gradient(135deg, rgba(211, 47, 47, 0.08) 0%, rgba(211, 47, 47, 0.12) 100%)',
              }}
            >
              <ErrorOutline 
                sx={{ 
                  color: 'error.main', 
                  fontSize: 24,
                  mt: 0.25,
                  flexShrink: 0
                }} 
              />
              <Box sx={{ flex: 1 }}>
                <Typography 
                  variant="subtitle2" 
                  sx={{ 
                    color: 'error.main', 
                    fontWeight: 600,
                    mb: 0.5,
                    letterSpacing: 0.5
                  }}
                >
                  REJECTION REASON
                </Typography>
                <Typography 
                  variant="body2" 
                  sx={{ 
                    color: 'error.dark',
                    lineHeight: 1.6,
                    fontStyle: 'italic'
                  }}
                >
                  "{selectedExpense.rejection_reason}"
                </Typography>
              </Box>
            </Box>
          )}
          
          <TextField
            select
            fullWidth
            label="Project"
            value={projectId}
            onChange={(e) => setProjectId(Number(e.target.value))}
            margin="normal"
            required
          >
            <MenuItem value={0}>Select a project</MenuItem>
            {projects.map((project) => (
              <MenuItem key={project.id} value={project.id}>
                {project.name}
              </MenuItem>
            ))}
          </TextField>

          <TextField
            type="date"
            fullWidth
            label="Date"
            value={date}
            onChange={(e) => setDate(e.target.value)}
            margin="normal"
            required
            InputLabelProps={{ shrink: true }}
          />

          <TextField
            select
            fullWidth
            label="Expense Type"
            value={expenseType}
            onChange={(e) => setExpenseType(e.target.value as any)}
            margin="normal"
            required
          >
            <MenuItem value="reimbursement">Reimbursement (Receipt Required)</MenuItem>
            <MenuItem value="mileage">Mileage/Kilometers</MenuItem>
            <MenuItem value="company_card">Company Card</MenuItem>
          </TextField>

          <TextField
            fullWidth
            label="Category"
            value={category}
            onChange={(e) => setCategory(e.target.value)}
            margin="normal"
            required
          />

          {/* Conditional fields based on expense_type */}
          {expenseType === 'mileage' ? (
            <>
              <TextField
                type="number"
                fullWidth
                label="Distance (km)"
                value={distanceKm}
                onChange={(e) => setDistanceKm(Number(e.target.value))}
                margin="normal"
                required
                inputProps={{ min: 0, step: 0.01 }}
              />
              <TextField
                type="number"
                fullWidth
                label="Rate per km (€)"
                value={ratePerKm}
                onChange={(e) => setRatePerKm(Number(e.target.value))}
                margin="normal"
                required
                inputProps={{ min: 0, step: 0.01 }}
              />
              <TextField
                select
                fullWidth
                label="Vehicle Type"
                value={vehicleType}
                onChange={(e) => setVehicleType(e.target.value)}
                margin="normal"
                required
              >
                <MenuItem value="car">Car</MenuItem>
                <MenuItem value="motorcycle">Motorcycle</MenuItem>
                <MenuItem value="bicycle">Bicycle</MenuItem>
              </TextField>
              <Typography color="info.main" sx={{ mt: 2 }}>
                Calculated amount: €{(distanceKm * ratePerKm).toFixed(2)}
              </Typography>
            </>
          ) : (
            <TextField
              type="number"
              fullWidth
              label="Amount (€)"
              value={amount}
              onChange={(e) => setAmount(Number(e.target.value))}
              margin="normal"
              required
              inputProps={{ min: 0, step: 0.01 }}
            />
          )}

          <TextField
            fullWidth
            label="Description"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            margin="normal"
            multiline
            rows={3}
          />

          <Button
            component="label"
            variant="outlined"
            startIcon={<AttachFile />}
            sx={{ mt: 2, mb: 1 }}
            fullWidth
          >
            {attachmentFile ? attachmentFile.name : `Upload Receipt ${expenseType === 'reimbursement' ? '(Recommended)' : '(Optional)'}`}
            <input
              type="file"
              hidden
              accept="image/*,.pdf"
              onChange={(e) => {
                const file = e.target.files?.[0];
                if (file) setAttachmentFile(file);
              }}
            />
          </Button>
        </DialogContent>
        <DialogActions sx={{ flexWrap: 'wrap', gap: 1, p: 2 }}>
          <Button onClick={() => setDialogOpen(false)}>Cancel</Button>
          
          {/* Save/Update button (only for draft/rejected or new) */}
          {(!selectedExpense || selectedExpense.status === 'draft' || selectedExpense.status === 'rejected') && (
            <Button onClick={handleSave} variant="contained">
              {selectedExpense ? 'Update' : 'Save as Draft'}
            </Button>
          )}
          
          {/* Submit button (only for draft/rejected) */}
          {selectedExpense && (selectedExpense.status === 'draft' || selectedExpense.status === 'rejected') && (
            <Button onClick={handleSubmit} variant="contained" color="primary">
              Submit for Approval
            </Button>
          )}
          
          {/* Approve button (only for submitted - Manager) */}
          {selectedExpense && selectedExpense.status === 'submitted' && (
            <Button onClick={handleApprove} variant="contained" color="success">
              Approve (Send to Finance)
            </Button>
          )}
          
          {/* Reject button (only for submitted or finance_review) */}
          {selectedExpense && (selectedExpense.status === 'submitted' || selectedExpense.status === 'finance_review') && (
            <Button onClick={handleReject} variant="contained" color="error">
              Reject
            </Button>
          )}
        </DialogActions>
      </Dialog>

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
    </Box>
  );
};

export default ExpenseManager;
