import React from 'react';
import { BrowserRouter as Router, Routes, Route, Navigate } from 'react-router-dom';
import { ThemeProvider, createTheme } from '@mui/material/styles';
import CssBaseline from '@mui/material/CssBaseline';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Box } from '@mui/material';

import { AuthProvider, useAuth } from './components/Auth/AuthContext';
import { Header } from './components/Layout/Header';
import { LoginForm } from './components/Auth/LoginForm';
import TimesheetCalendar from './components/Timesheets/TimesheetCalendar';
import { ExpenseManager } from './components/Expenses/ExpenseManager';
import { ApprovalManager } from './components/Approvals/ApprovalManager';

// Create Material-UI theme
const theme = createTheme({
  palette: {
    primary: {
      main: '#1976d2',
    },
    secondary: {
      main: '#dc004e',
    },
  },
  typography: {
    h4: {
      fontWeight: 600,
    },
    h6: {
      fontWeight: 500,
    },
  },
});

// Create React Query client
const queryClient = new QueryClient({
  defaultOptions: {
    queries: {
      retry: 1,
      staleTime: 5 * 60 * 1000, // 5 minutes
    },
  },
});

// Wrapper for TimesheetCalendar that gets user from context
const TimesheetCalendarWrapper: React.FC = () => {
  const { user } = useAuth();
  return <TimesheetCalendar user={user} />;
};

// Protected Route component
const ProtectedRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user, loading } = useAuth();
  
  if (loading) {
    return <div>Loading...</div>;
  }
  
  if (!user) {
    return <Navigate to="/login" replace />;
  }
  
  return <>{children}</>;
};

// Manager-only Route component
const ManagerRoute: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user } = useAuth();
  
  if (user?.role !== 'Manager') {
    return <Navigate to="/timesheets" replace />;
  }
  
  return <>{children}</>;
};

// Main App Layout
const AppLayout: React.FC<{ children: React.ReactNode }> = ({ children }) => {
  const { user } = useAuth();
  
  return (
    <Box sx={{ display: 'flex', flexDirection: 'column', minHeight: '100vh', width: '100%' }}>
      {user && <Header />}
      <Box component="main" sx={{ flexGrow: 1, width: '100%', maxWidth: '100%' }}>
        {children}
      </Box>
    </Box>
  );
};

// App Routes
const AppRoutes: React.FC = () => {
  return (
    <Routes>
      <Route path="/login" element={<LoginForm />} />
      <Route
        path="/timesheets"
        element={
          <ProtectedRoute>
            <TimesheetCalendarWrapper />
          </ProtectedRoute>
        }
      />
      <Route
        path="/expenses"
        element={
          <ProtectedRoute>
            <ExpenseManager />
          </ProtectedRoute>
        }
      />
      <Route
        path="/approval"
        element={
          <ProtectedRoute>
            <ManagerRoute>
              <ApprovalManager />
            </ManagerRoute>
          </ProtectedRoute>
        }
      />
      <Route path="/" element={<Navigate to="/timesheets" replace />} />
    </Routes>
  );
};

// Main App Component
const App: React.FC = () => {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider theme={theme}>
        <CssBaseline />
        <Router future={{
          v7_startTransition: true,
          v7_relativeSplatPath: true
        }}>
          <AuthProvider>
            <AppLayout>
              <AppRoutes />
            </AppLayout>
          </AuthProvider>
        </Router>
      </ThemeProvider>
    </QueryClientProvider>
  );
};

export default App;