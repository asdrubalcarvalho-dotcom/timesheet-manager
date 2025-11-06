import React, { useState, useEffect } from 'react';
import {
  Box,
  Typography,
  Card,
  CardContent,
  Tabs,
  Tab,
  Button,
  Alert,
  Grid,
  Chip,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Paper,
  IconButton
} from '@mui/material';
import { Check, Close, Visibility, AttachFile } from '@mui/icons-material';
import { useAuth } from '../Auth/AuthContext';

interface Project {
  id: number;
  name: string;
}

interface Technician {
  id: number;
  name: string;
  email: string;
}

interface TimesheetEntry {
  id: number;
  project_id: number;
  date: string;
  hours_worked: number;
  description: string;
  status: 'draft' | 'submitted' | 'approved' | 'rejected' | 'closed';
  project?: Project;
  technician?: Technician;
}

interface ExpenseEntry {
  id: number;
  project_id: number;
  date: string;
  amount: number;
  description: string;
  attachment_path?: string;
  status: 'draft' | 'submitted' | 'approved' | 'rejected' | 'closed';
  project?: Project;
  technician?: Technician;
}

export const ApprovalManager: React.FC = () => {
  const [timesheets, setTimesheets] = useState<TimesheetEntry[]>([]);
  const [expenses, setExpenses] = useState<ExpenseEntry[]>([]);
  const [tabValue, setTabValue] = useState(0);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  const [detailsOpen, setDetailsOpen] = useState(false);
  const [selectedItem, setSelectedItem] = useState<TimesheetEntry | ExpenseEntry | null>(null);
  
  const { user } = useAuth();

  // Load data on mount
  useEffect(() => {
    if (user?.role === 'Manager') {
      loadPendingItems();
    }
  }, [user]);

  const getAuthHeaders = () => ({
    'Authorization': `Bearer ${localStorage.getItem('auth_token')}`,
    'Content-Type': 'application/json',
    'Accept': 'application/json'
  });

  const loadPendingItems = async () => {
    try {
      setLoading(true);
      
      // Load pending timesheets
      const timesheetResponse = await fetch('http://localhost:8080/api/timesheets/pending', {
        headers: getAuthHeaders()
      });
      
      if (timesheetResponse.ok) {
        const timesheetData = await timesheetResponse.json();
        setTimesheets(timesheetData);
      }

      // Load pending expenses
      const expenseResponse = await fetch('http://localhost:8080/api/expenses/pending', {
        headers: getAuthHeaders()
      });
      
      if (expenseResponse.ok) {
        const expenseData = await expenseResponse.json();
        setExpenses(expenseData);
      }
    } catch (error) {
      console.error('Failed to load pending items:', error);
      setError('Failed to load pending items');
    } finally {
      setLoading(false);
    }
  };

  const handleApproval = async (type: 'timesheet' | 'expense', id: number, status: 'approved' | 'rejected') => {
    try {
      const endpoint = type === 'timesheet' ? 'timesheets' : 'expenses';
      const response = await fetch(`http://localhost:8080/api/${endpoint}/${id}/approve`, {
        method: 'PUT',
        headers: getAuthHeaders(),
        body: JSON.stringify({ status })
      });

      if (response.ok) {
        loadPendingItems(); // Reload the data
        setDetailsOpen(false);
        setSelectedItem(null);
      } else {
        setError(`Failed to ${status === 'approved' ? 'approve' : 'reject'} ${type}`);
      }
    } catch (error) {
      setError(`Failed to ${status === 'approved' ? 'approve' : 'reject'} ${type}`);
    }
  };

  const handleViewDetails = (item: TimesheetEntry | ExpenseEntry) => {
    setSelectedItem(item);
    setDetailsOpen(true);
  };

  const getStatusChip = (status: string) => {
    const colors = {
      pending: 'warning',
      approved: 'success',
      rejected: 'error'
    } as const;
    
    return <Chip label={status} color={colors[status as keyof typeof colors]} size="small" />;
  };



  if (user?.role !== 'Manager') {
    return (
      <Box sx={{ p: 3 }}>
        <Alert severity="warning">
          Access denied. This page is only available to Managers.
        </Alert>
      </Box>
    );
  }

  const isTimesheet = (item: any): item is TimesheetEntry => {
    return 'hours_worked' in item;
  };

  return (
    <Box sx={{ p: 3 }}>
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Grid container spacing={2}>
            <Grid item xs={12} md={6}>
              <Typography variant="h6" color="primary">
                Submitted Timesheets: {timesheets.filter(t => t.status === 'submitted').length}
              </Typography>
            </Grid>
            <Grid item xs={12} md={6}>
              <Typography variant="h6" color="secondary">
                Submitted Expenses: {expenses.filter(e => e.status === 'submitted').length}
              </Typography>
            </Grid>
          </Grid>
        </CardContent>
      </Card>

      {error && (
        <Alert severity="error" sx={{ mb: 2 }}>
          {error}
        </Alert>
      )}

      {loading && (
        <Alert severity="info" sx={{ mb: 2 }}>
          Loading pending approvals...
        </Alert>
      )}

      <Tabs value={tabValue} onChange={(_, newValue) => setTabValue(newValue)} sx={{ mb: 3 }}>
        <Tab label="Timesheets" />
        <Tab label="Expenses" />
      </Tabs>

      {tabValue === 0 && (
        <Paper sx={{ width: '100%', overflow: 'hidden' }}>
          <TableContainer sx={{ maxHeight: 440 }}>
            <Table stickyHeader>
              <TableHead>
                <TableRow>
                  <TableCell>Date</TableCell>
                  <TableCell>Technician</TableCell>
                  <TableCell>Project</TableCell>
                  <TableCell>Hours</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {timesheets.map((timesheet) => (
                  <TableRow key={timesheet.id}>
                    <TableCell>{timesheet.date}</TableCell>
                    <TableCell>{timesheet.technician?.name || 'Unknown'}</TableCell>
                    <TableCell>{timesheet.project?.name || 'Unknown'}</TableCell>
                    <TableCell>{timesheet.hours_worked}</TableCell>
                    <TableCell>
                      <Chip
                        label={timesheet.status}
                        color={getStatusChip(timesheet.status) as any}
                        size="small"
                      />
                    </TableCell>
                    <TableCell>
                      <IconButton onClick={() => handleViewDetails(timesheet)} size="small">
                        <Visibility />
                      </IconButton>
                      <IconButton 
                        onClick={() => handleApproval('timesheet', timesheet.id, 'approved')} 
                        size="small"
                        color="success"
                      >
                        <Check />
                      </IconButton>
                      <IconButton 
                        onClick={() => handleApproval('timesheet', timesheet.id, 'rejected')} 
                        size="small"
                        color="error"
                      >
                        <Close />
                      </IconButton>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        </Paper>
      )}

      {tabValue === 1 && (
        <Paper sx={{ width: '100%', overflow: 'hidden' }}>
          <TableContainer sx={{ maxHeight: 440 }}>
            <Table stickyHeader>
              <TableHead>
                <TableRow>
                  <TableCell>Date</TableCell>
                  <TableCell>Technician</TableCell>
                  <TableCell>Project</TableCell>
                  <TableCell>Amount</TableCell>
                  <TableCell>Status</TableCell>
                  <TableCell>Actions</TableCell>
                </TableRow>
              </TableHead>
              <TableBody>
                {expenses.map((expense) => (
                  <TableRow key={expense.id}>
                    <TableCell>{expense.date}</TableCell>
                    <TableCell>{expense.technician?.name || 'Unknown'}</TableCell>
                    <TableCell>{expense.project?.name || 'Unknown'}</TableCell>
                    <TableCell>${expense.amount}</TableCell>
                    <TableCell>
                      <Chip
                        label={expense.status}
                        color={getStatusChip(expense.status) as any}
                        size="small"
                      />
                    </TableCell>
                    <TableCell>
                      <IconButton onClick={() => handleViewDetails(expense)} size="small">
                        <Visibility />
                      </IconButton>
                      {expense.attachment_path && (
                        <IconButton size="small">
                          <AttachFile />
                        </IconButton>
                      )}
                      <IconButton 
                        onClick={() => handleApproval('expense', expense.id, 'approved')} 
                        size="small"
                        color="success"
                      >
                        <Check />
                      </IconButton>
                      <IconButton 
                        onClick={() => handleApproval('expense', expense.id, 'rejected')} 
                        size="small"
                        color="error"
                      >
                        <Close />
                      </IconButton>
                    </TableCell>
                  </TableRow>
                ))}
              </TableBody>
            </Table>
          </TableContainer>
        </Paper>
      )}

      {/* Details Dialog */}
      <Dialog open={detailsOpen} onClose={() => setDetailsOpen(false)} maxWidth="md" fullWidth>
        <DialogTitle>
          {selectedItem && isTimesheet(selectedItem) ? 'Timesheet Details' : 'Expense Details'}
        </DialogTitle>
        <DialogContent>
          {selectedItem && (
            <Grid container spacing={2} sx={{ mt: 1 }}>
              <Grid item xs={12} md={6}>
                <Typography variant="subtitle2">Technician:</Typography>
                <Typography>{selectedItem.technician?.name || 'Unknown'}</Typography>
              </Grid>
              <Grid item xs={12} md={6}>
                <Typography variant="subtitle2">Project:</Typography>
                <Typography>{selectedItem.project?.name || 'Unknown'}</Typography>
              </Grid>
              <Grid item xs={12} md={6}>
                <Typography variant="subtitle2">Date:</Typography>
                <Typography>{new Date(selectedItem.date).toLocaleDateString()}</Typography>
              </Grid>
              <Grid item xs={12} md={6}>
                <Typography variant="subtitle2">
                  {isTimesheet(selectedItem) ? 'Hours:' : 'Amount:'}
                </Typography>
                <Typography>
                  {isTimesheet(selectedItem) 
                    ? `${selectedItem.hours_worked}h` 
                    : `$${(selectedItem as ExpenseEntry).amount.toFixed(2)}`}
                </Typography>
              </Grid>
              <Grid item xs={12}>
                <Typography variant="subtitle2">Description:</Typography>
                <Typography>{selectedItem.description}</Typography>
              </Grid>
              {!isTimesheet(selectedItem) && (selectedItem as ExpenseEntry).attachment_path && (
                <Grid item xs={12}>
                  <Typography variant="subtitle2">Receipt:</Typography>
                  <Button
                    startIcon={<AttachFile />}
                    onClick={() => {
                      // Open attachment in new window
                      window.open(`http://localhost:8080/storage/${(selectedItem as ExpenseEntry).attachment_path}`, '_blank');
                    }}
                  >
                    View Receipt
                  </Button>
                </Grid>
              )}
              <Grid item xs={12}>
                <Typography variant="subtitle2">Status:</Typography>
                {getStatusChip(selectedItem.status)}
              </Grid>
            </Grid>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDetailsOpen(false)}>Close</Button>
          {selectedItem?.status === 'submitted' && (
            <>
              <Button
                color="error"
                onClick={() => {
                  const type = isTimesheet(selectedItem) ? 'timesheet' : 'expense';
                  handleApproval(type, selectedItem.id, 'rejected');
                }}
              >
                Reject
              </Button>
              <Button
                variant="contained"
                onClick={() => {
                  const type = isTimesheet(selectedItem) ? 'timesheet' : 'expense';
                  handleApproval(type, selectedItem.id, 'approved');
                }}
              >
                Approve
              </Button>
            </>
          )}
        </DialogActions>
      </Dialog>
    </Box>
  );
};