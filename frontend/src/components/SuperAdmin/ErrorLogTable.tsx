/**
 * ErrorLogTable Component
 * PART B — ERROR LOG ANALYZER
 * 
 * Displays error logs from /api/superadmin/telemetry/errors
 * - Groups errors by date
 * - Click to open modal with full details
 * - Empty state if no errors
 */
import React, { useState } from 'react';
import {
  Box,
  Paper,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Typography,
  Chip,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  IconButton
} from '@mui/material';
import { Visibility as VisibilityIcon } from '@mui/icons-material';

interface ErrorLogEntry {
  timestamp: string;
  level: string;
  message: string;
}

interface ErrorLogTableProps {
  errors: ErrorLogEntry[];
}

const ErrorLogTable: React.FC<ErrorLogTableProps> = ({ errors }) => {
  const [selectedError, setSelectedError] = useState<ErrorLogEntry | null>(null);

  // Group errors by date
  const groupedErrors: Record<string, ErrorLogEntry[]> = {};
  errors.forEach((err) => {
    const date = err.timestamp.split(' ')[0]; // Extract date part
    if (!groupedErrors[date]) {
      groupedErrors[date] = [];
    }
    groupedErrors[date].push(err);
  });

  const handleOpenDetails = (error: ErrorLogEntry) => {
    setSelectedError(error);
  };

  const handleCloseDetails = () => {
    setSelectedError(null);
  };

  // Empty state
  if (errors.length === 0) {
    return (
      <Box display="flex" justifyContent="center" alignItems="center" minHeight={200}>
        <Paper sx={{ p: 4, textAlign: 'center' }}>
          <Typography variant="h6" color="text.secondary">
            No errors logged
          </Typography>
        </Paper>
      </Box>
    );
  }

  return (
    <Box>
      <TableContainer component={Paper}>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>Date</TableCell>
              <TableCell>Time</TableCell>
              <TableCell>Level</TableCell>
              <TableCell>Message</TableCell>
              <TableCell align="right">Actions</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {Object.keys(groupedErrors).map((date) => (
              <React.Fragment key={date}>
                <TableRow sx={{ backgroundColor: 'grey.100' }}>
                  <TableCell colSpan={5}>
                    <Typography variant="subtitle2" fontWeight="bold">
                      {date}
                    </Typography>
                  </TableCell>
                </TableRow>
                {groupedErrors[date].map((error, idx) => {
                  const [datePart, timePart] = error.timestamp.split(' ');
                  return (
                    <TableRow key={`${date}-${idx}`} hover>
                      <TableCell>{datePart}</TableCell>
                      <TableCell>{timePart}</TableCell>
                      <TableCell>
                        <Chip
                          label={error.level}
                          color={error.level === 'ERROR' ? 'error' : 'warning'}
                          size="small"
                        />
                      </TableCell>
                      <TableCell>
                        {error.message.length > 80
                          ? `${error.message.substring(0, 80)}...`
                          : error.message}
                      </TableCell>
                      <TableCell align="right">
                        <IconButton size="small" onClick={() => handleOpenDetails(error)}>
                          <VisibilityIcon fontSize="small" />
                        </IconButton>
                      </TableCell>
                    </TableRow>
                  );
                })}
              </React.Fragment>
            ))}
          </TableBody>
        </Table>
      </TableContainer>

      {/* Error Detail Modal */}
      <Dialog open={!!selectedError} onClose={handleCloseDetails} maxWidth="md" fullWidth>
        <DialogTitle>Error Details</DialogTitle>
        <DialogContent sx={{ maxHeight: '60vh', overflow: 'auto' }}>
          {selectedError && (
            <Box>
              <Typography variant="subtitle2" gutterBottom>
                <strong>Timestamp:</strong> {selectedError.timestamp}
              </Typography>
              <Typography variant="subtitle2" gutterBottom>
                <strong>Level:</strong>{' '}
                <Chip
                  label={selectedError.level}
                  color={selectedError.level === 'ERROR' ? 'error' : 'warning'}
                  size="small"
                />
              </Typography>
              <Typography variant="subtitle2" gutterBottom>
                <strong>Message:</strong>
              </Typography>
              <Paper sx={{ p: 2, backgroundColor: 'grey.50', mt: 1 }}>
                <Typography variant="body2" component="pre" sx={{ whiteSpace: 'pre-wrap' }}>
                  {selectedError.message}
                </Typography>
              </Paper>
            </Box>
          )}
        </DialogContent>
        <DialogActions>
          <Button onClick={handleCloseDetails}>Close</Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default ErrorLogTable;
