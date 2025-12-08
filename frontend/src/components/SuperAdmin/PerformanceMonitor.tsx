/**
 * PerformanceMonitor Component
 * PART D — API PERFORMANCE MONITOR
 * 
 * Tests performance of selected endpoints using /api/superadmin/telemetry/ping?url=
 * Color coding:
 * - Green: <150ms
 * - Yellow: <350ms
 * - Red: ≥350ms
 */
import React, { useState } from 'react';
import {
  Box,
  Paper,
  Typography,
  Button,
  Table,
  TableBody,
  TableCell,
  TableContainer,
  TableHead,
  TableRow,
  Chip,
  CircularProgress
} from '@mui/material';
import { Speed as SpeedIcon } from '@mui/icons-material';
import api from '../../services/api';

interface PingResult {
  url: string;
  response_time_ms: number;
  status: string;
}

const ENDPOINTS_TO_TEST = [
  { label: 'Login', url: '/api/login' },
  { label: 'Timesheets', url: '/api/timesheets' },
  { label: 'Expenses', url: '/api/expenses' },
  { label: 'Projects', url: '/api/projects' }
];

const PerformanceMonitor: React.FC = () => {
  const [results, setResults] = useState<Record<string, PingResult>>({});
  const [loading, setLoading] = useState(false);

  const testEndpoints = async () => {
    setLoading(true);
    const newResults: Record<string, PingResult> = {};

    for (const endpoint of ENDPOINTS_TO_TEST) {
      try {
        const response = await api.get(`/api/superadmin/telemetry/ping?url=${encodeURIComponent(endpoint.url)}`);
        if (response.data.success && response.data.data) {
          newResults[endpoint.url] = response.data.data;
        } else {
          newResults[endpoint.url] = {
            url: endpoint.url,
            response_time_ms: 0,
            status: 'fail'
          };
        }
      } catch (error) {
        newResults[endpoint.url] = {
          url: endpoint.url,
          response_time_ms: 0,
          status: 'fail'
        };
      }
    }

    setResults(newResults);
    setLoading(false);
  };

  const getColorByTime = (ms: number): 'success' | 'warning' | 'error' => {
    if (ms < 150) return 'success';
    if (ms < 350) return 'warning';
    return 'error';
  };

  const getStatusColor = (status: string): 'success' | 'warning' | 'error' | 'info' => {
    switch (status) {
      case 'ok':
        return 'success';
      case 'auth_required':
        return 'warning'; // Expected for protected endpoints
      case 'method_not_allowed':
        return 'info'; // Expected for POST-only endpoints
      case 'error':
      case 'fail':
      default:
        return 'error';
    }
  };

  const getStatusLabel = (status: string): string => {
    switch (status) {
      case 'ok':
        return 'OK';
      case 'auth_required':
        return 'Auth Required';
      case 'method_not_allowed':
        return 'POST Required';
      case 'error':
        return 'Error';
      case 'fail':
      default:
        return 'Fail';
    }
  };

  return (
    <Paper sx={{ p: 3 }}>
      <Box display="flex" justifyContent="space-between" alignItems="center" mb={2}>
        <Typography variant="h6">API Performance Monitor</Typography>
        <Button
          variant="contained"
          startIcon={loading ? <CircularProgress size={20} color="inherit" /> : <SpeedIcon />}
          onClick={testEndpoints}
          disabled={loading}
        >
          Test Endpoints
        </Button>
      </Box>

      <TableContainer>
        <Table>
          <TableHead>
            <TableRow>
              <TableCell>Endpoint</TableCell>
              <TableCell>URL</TableCell>
              <TableCell align="right">Response Time</TableCell>
              <TableCell align="center">Status</TableCell>
            </TableRow>
          </TableHead>
          <TableBody>
            {ENDPOINTS_TO_TEST.map((endpoint) => {
              const result = results[endpoint.url];
              return (
                <TableRow key={endpoint.url}>
                  <TableCell>{endpoint.label}</TableCell>
                  <TableCell>
                    <Typography variant="body2" fontFamily="monospace">
                      {endpoint.url}
                    </Typography>
                  </TableCell>
                  <TableCell align="right">
                    {result ? (
                      <Chip
                        label={`${result.response_time_ms} ms`}
                        color={getColorByTime(result.response_time_ms)}
                        size="small"
                      />
                    ) : (
                      <Typography variant="body2" color="text.secondary">
                        -
                      </Typography>
                    )}
                  </TableCell>
                  <TableCell align="center">
                    {result ? (
                      <Chip
                        label={getStatusLabel(result.status)}
                        color={getStatusColor(result.status)}
                        size="small"
                      />
                    ) : (
                      <Typography variant="body2" color="text.secondary">
                        -
                      </Typography>
                    )}
                  </TableCell>
                </TableRow>
              );
            })}
          </TableBody>
        </Table>
      </TableContainer>

      <Box mt={2} p={2} bgcolor="info.light" borderRadius={1}>
        <Typography variant="caption" color="text.secondary">
          <strong>Note:</strong> Protected endpoints will show "Auth Required" or "Error" status, which is expected behavior. 
          Response times measure server availability, not authentication success.
        </Typography>
      </Box>
    </Paper>
  );
};

export default PerformanceMonitor;
