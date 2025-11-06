import React, { useState, useEffect } from 'react';
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
  Grid,
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
  TablePagination
} from '@mui/material';
import { Edit, Delete, Add, AttachFile } from '@mui/icons-material';

interface Project {
  id: number;
  name: string;
}

interface ExpenseEntry {
  id?: number;
  project_id: number;
  date: string;
  amount: number | string;
  description: string;
  attachment_path?: string;
  status: 'submitted' | 'approved' | 'rejected';
  project?: Project;
}

export const ExpenseManager: React.FC = () => {
  const [expenses, setExpenses] = useState<ExpenseEntry[]>([]);
  const [projects, setProjects] = useState<Project[]>([]);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [selectedExpense, setSelectedExpense] = useState<ExpenseEntry | null>(null);
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);

  // Form states
  const [projectId, setProjectId] = useState<number>(0);
  const [date, setDate] = useState('');
  const [amount, setAmount] = useState<number>(0);
  const [description, setDescription] = useState('');
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
        setError(`Failed to load expenses: ${response.status}`);
      }
    } catch (error) {
      console.error('Failed to load expenses:', error);
      setError('Failed to load expenses: Network error');
    } finally {
      setLoading(false);
    }
  };

  const loadProjects = async () => {
    try {
      const response = await fetch('http://localhost:8080/api/projects', {
        headers: getAuthHeaders()
      });
      
      if (response.ok) {
        const data = await response.json();
        setProjects(data);
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
    setDate(expense.date);
    setAmount(Number(expense.amount));
    setDescription(expense.description);
    setAttachmentFile(null);
    setDialogOpen(true);
  };

  const resetForm = () => {
    setProjectId(0);
    setDate(new Date().toISOString().split('T')[0]);
    setAmount(0);
    setDescription('');
    setAttachmentFile(null);
    setError('');
  };

  const handleSave = async () => {
    if (!projectId || !amount || !date) {
      setError('Please fill in all required fields');
      return;
    }

    const formData = new FormData();
    formData.append('project_id', projectId.toString());
    formData.append('date', date);
    formData.append('amount', amount.toString());
    formData.append('description', description);
    
    if (attachmentFile) {
      formData.append('attachment', attachmentFile);
    }

    try {
      let response;
      if (selectedExpense?.id) {
        // Update existing expense
        response = await fetch(`http://localhost:8080/api/expenses/${selectedExpense.id}`, {
          method: 'POST', // Laravel uses POST with _method=PUT for file uploads
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
        loadExpenses();
        setDialogOpen(false);
        resetForm();
      } else {
        setError('Failed to save expense');
      }
    } catch (error) {
      setError('Failed to save expense');
    }
  };

  const handleDelete = async (expenseId: number) => {
    try {
      const response = await fetch(`http://localhost:8080/api/expenses/${expenseId}`, {
        method: 'DELETE',
        headers: getAuthHeaders()
      });

      if (response.ok) {
        loadExpenses();
      } else {
        setError('Failed to delete expense');
      }
    } catch (error) {
      setError('Failed to delete expense');
    }
  };

  const getStatusChip = (status: string) => {
    const colors = {
      submitted: 'info',
      approved: 'success',
      rejected: 'error'
    } as const;
    
    return <Chip label={status.charAt(0).toUpperCase() + status.slice(1)} color={colors[status as keyof typeof colors]} size="small" />;
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
    <Box sx={{ p: 3 }}>
      <Card sx={{ mb: 3 }}>
        <CardContent>
          <Grid container spacing={2} alignItems="center">
            <Grid item xs={12} md={8}>
              <Typography variant="body2" color="text.secondary">
                Submit your expenses with receipts for project-related costs. 
                Approved expenses cannot be edited.
              </Typography>
            </Grid>
            <Grid item xs={12} md={4} sx={{ textAlign: 'right' }}>
              <Button
                variant="contained"
                startIcon={<Add />}
                onClick={handleAddNew}
              >
                Add Expense
              </Button>
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
          Loading expenses...
        </Alert>
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
                    {getStatusChip(expense.status)}
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
        sx={{ position: 'fixed', bottom: 16, right: 16, display: { md: 'none' } }}
        onClick={handleAddNew}
      >
        <Add />
      </Fab>

      {/* Expense Entry Dialog */}
      <Dialog open={dialogOpen} onClose={() => setDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>
          {selectedExpense ? 'Edit Expense' : 'New Expense'}
        </DialogTitle>
        <DialogContent>
          {error && (
            <Alert severity="error" sx={{ mb: 2 }}>
              {error}
            </Alert>
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
            type="number"
            fullWidth
            label="Amount ($)"
            value={amount}
            onChange={(e) => setAmount(Number(e.target.value))}
            margin="normal"
            required
            inputProps={{ min: 0, step: 0.01 }}
          />

          <TextField
            fullWidth
            label="Description"
            value={description}
            onChange={(e) => setDescription(e.target.value)}
            margin="normal"
            multiline
            rows={3}
            required
          />

          <Button
            component="label"
            variant="outlined"
            startIcon={<AttachFile />}
            sx={{ mt: 2, mb: 1 }}
            fullWidth
          >
            {attachmentFile ? attachmentFile.name : 'Upload Receipt (Optional)'}
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

          {selectedExpense && (
            <TextField
              fullWidth
              label="Status"
              value={selectedExpense.status}
              margin="normal"
              disabled
            />
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setDialogOpen(false)}>Cancel</Button>
          <Button onClick={handleSave} variant="contained">
            {selectedExpense ? 'Update' : 'Save'}
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};