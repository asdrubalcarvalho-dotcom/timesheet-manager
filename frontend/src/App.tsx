import React, { Suspense, useEffect } from 'react';
import { ThemeProvider, createTheme } from '@mui/material/styles';
import CssBaseline from '@mui/material/CssBaseline';
import { QueryClient, QueryClientProvider } from '@tanstack/react-query';
import { Alert, Box, Button, CircularProgress, Typography } from '@mui/material';
import { LocalizationProvider } from '@mui/x-date-pickers/LocalizationProvider';
import { AdapterDayjs } from '@mui/x-date-pickers/AdapterDayjs';
import 'dayjs/locale/pt';
import dayjs from 'dayjs';

// Set dayjs locale to Portuguese (Portugal) for DD/MM/YYYY format
dayjs.locale('pt');

import { AuthProvider, useAuth } from './components/Auth/AuthContext';
import { NotificationProvider } from './contexts/NotificationContext';
import { BillingProvider } from './contexts/BillingContext';
import { useBilling } from './contexts/BillingContext';
import { FeatureProvider } from './contexts/FeatureContext';
import RequireFeature from './components/Guards/RequireFeature';
import { useLocation, useNavigate } from 'react-router-dom';
import SideMenu from './components/Layout/SideMenu';
import { LoginForm } from './components/Auth/LoginForm';
import TenantRegistration from './components/Auth/TenantRegistration';
import VerifyEmail from './components/Auth/VerifyEmail';
import SuperAdminApp from './components/SuperAdmin/SuperAdminApp';
import SsoCallback from './pages/SsoCallback';
const TimesheetCalendar = React.lazy(() => import('./components/Timesheets/TimesheetCalendar'));
const Dashboard = React.lazy(() => import('./components/Dashboard/Dashboard'));
const PlanningGantt = React.lazy(() => import('./components/Planning/PlanningGantt'));
const PlanningGanttLocations = React.lazy(() => import('./components/Planning/PlanningGanttLocations'));
const PlanningGanttUsers = React.lazy(() => import('./components/Planning/PlanningGanttUsers'));
const ExpenseManager = React.lazy(() => import('./components/Expenses/ExpenseManager'));
const ApprovalManager = React.lazy(() => import('./components/Approvals/ApprovalManager'));
const AIInsights = React.lazy(() => import('./components/AIInsights/AIInsights'));
const AdminDashboard = React.lazy(() => import('./components/Admin/AdminDashboard'));
const ProjectsManager = React.lazy(() => import('./components/Admin/ProjectsManager'));
const TasksManager = React.lazy(() => import('./components/Admin/TasksManager'));
const LocationsManager = React.lazy(() => import('./components/Admin/LocationsManager'));
const CountriesManager = React.lazy(() => import('./components/Admin/CountriesManager'));
const UsersManager = React.lazy(() => import('./components/Admin/UsersManager'));
const AdminAccessManagerPage = React.lazy(() => import('./pages/AdminAccessManager'));
const TravelsList = React.lazy(() => import('./components/Travels/TravelsList'));
const BillingPage = React.lazy(() => import('./components/Billing/BillingPage'));
const PaymentMethodsPage = React.lazy(() => import('./pages/Billing/PaymentMethodsPage'));
const TimesheetPivotReport = React.lazy(() => import('./components/Timesheets/TimesheetPivotReport'));
const ApprovalHeatmapReport = React.lazy(() => import('./components/Approvals/ApprovalHeatmapReport'));
const ExpensesAnalysisReport = React.lazy(() => import('./components/Reports/Expenses/ExpensesAnalysisReport'));

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

// Page type definition
type Page = 'timesheets' | 'timesheets-pivot-report' | 'approvals-heatmap-report' | 'expenses-analysis-report' | 'expenses' | 'approvals' | 'dashboard' | 'ai-insights' | 'team' | 'admin' | 'admin-projects' | 'admin-tasks' | 'admin-locations' | 'admin-countries' | 'admin-users' | 'planning' | 'planning-locations' | 'planning-users' | 'admin-access' | 'travels' | 'billing' | 'payment-methods';

const DEFAULT_PAGE: Page = 'timesheets';

const pageToPath: Record<Page, string> = {
  timesheets: '/timesheets',
  'timesheets-pivot-report': '/timesheets/reports/pivot',
  'approvals-heatmap-report': '/reports/approvals/heatmap',
  'expenses-analysis-report': '/reports/expenses/analysis',
  expenses: '/expenses',
  approvals: '/approvals',
  dashboard: '/dashboard',
  'ai-insights': '/ai-insights',
  team: '/team',
  planning: '/planning',
  'planning-locations': '/planning/locations',
  'planning-users': '/planning/users',
  travels: '/travels',
  billing: '/billing',
  'payment-methods': '/settings/billing/payment-methods',
  admin: '/admin',
  'admin-projects': '/admin/projects',
  'admin-tasks': '/admin/tasks',
  'admin-locations': '/admin/locations',
  'admin-countries': '/admin/countries',
  'admin-users': '/admin/users',
  'admin-access': '/admin/access'
};

const pathToPage = (pathname: string): Page => {
  if (pathname === '/' || pathname === '') {
    return DEFAULT_PAGE;
  }

  // Exact match first
  const exactMatch = Object.entries(pageToPath).find(([, path]) => path === pathname);
  if (exactMatch) {
    return exactMatch[0] as Page;
  }

  // Partial match for nested routes (e.g., /settings/billing/payment-methods)
  const partialMatch = Object.entries(pageToPath).find(([, path]) => pathname.startsWith(path));
  return (partialMatch?.[0] as Page) || DEFAULT_PAGE;
};

const ModuleLoader: React.FC<{ label?: string }> = ({ label = 'A carregar módulo...' }) => (
  <Box
    sx={{
      display: 'flex',
      flexDirection: 'column',
      alignItems: 'center',
      justifyContent: 'center',
      gap: 2,
      minHeight: 200,
      color: 'text.secondary'
    }}
  >
    <CircularProgress size={32} />
    <Typography variant="body2">{label}</Typography>
  </Box>
);

// Main App Content with Side Menu
const AppContent: React.FC = () => {
  const { user, loading } = useAuth();
  const { billingSummary } = useBilling();
  const location = useLocation();
  const navigate = useNavigate();

  const hasStoredSession = (() => {
    try {
      return Boolean(localStorage.getItem('auth_token') && localStorage.getItem('tenant_slug'));
    } catch {
      return false;
    }
  })();

  const isTrialActive = (() => {
    if (!billingSummary?.is_trial) return false;
    const endsAt = billingSummary?.trial?.ends_at;
    if (!endsAt) return false;
    const parsed = Date.parse(endsAt);
    return Number.isFinite(parsed) && parsed > Date.now();
  })();

  // COPILOT GLOBAL RULES: Detect SuperAdmin host
    const host = window.location.hostname;
    const adminHosts = ['management.localhost', 'management.vendaslive.com'];
    const isSuperAdminHost = adminHosts.includes(host);  // If SuperAdmin host, render SuperAdmin app ONLY
  if (isSuperAdminHost) {
    if (!user) {
      return (
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '100vh', flexDirection: 'column', gap: 2 }}>
          <Typography variant="h5">Management Portal</Typography>
          <Typography variant="body2" color="text.secondary">Please authenticate to continue</Typography>
          <LoginForm />
        </Box>
      );
    }
    return <SuperAdminApp />;
  }

  // Normal tenant app logic below
  const currentPage = pathToPage(location.pathname);

  // ✅ ALWAYS call hooks before any conditional returns (Rules of Hooks)
  useEffect(() => {
    if (location.pathname === '/' || location.pathname === '') {
      navigate(pageToPath[DEFAULT_PAGE], { replace: true });
    }
  }, [location.pathname, navigate]);

  // Handle authentication and routing
  if (!user) {
    // If we already have a token+tenant in storage, don't flash the login screen while
    // AuthContext is validating the session (common right after SSO callback).
    if (loading && hasStoredSession) {
      return (
        <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'center', minHeight: '100vh', flexDirection: 'column', gap: 2 }}>
          <CircularProgress size={32} />
          <Typography variant="body2" color="text.secondary">
            Signing you in...
          </Typography>
        </Box>
      );
    }

    // Handle SSO browser handoff
    if (location.pathname === '/sso/callback') {
      return <SsoCallback />;
    }
    // Show VerifyEmail on /verify-signup route (public route)
    if (location.pathname === '/verify-signup') {
      return <VerifyEmail />;
    }
    // Show TenantRegistration ONLY on /register route
    if (location.pathname === '/register') {
      return <TenantRegistration />;
    }
    // Show LoginForm for all other routes
    return <LoginForm />;
  }

  const renderPage = () => {
    switch (currentPage) {
      case 'timesheets':
        return <TimesheetCalendar />;
      case 'timesheets-pivot-report':
        return <TimesheetPivotReport />;
      case 'approvals-heatmap-report':
        return <ApprovalHeatmapReport />;
      case 'expenses-analysis-report':
        return <ExpensesAnalysisReport />;
      case 'expenses':
        return <ExpenseManager />;
      case 'approvals':
        return <ApprovalManager />;
      case 'dashboard':
        return <Dashboard />;
      case 'ai-insights':
        return (
          <RequireFeature feature="ai">
            <AIInsights />
          </RequireFeature>
        );
      case 'team':
        return (
          <Box sx={{ p: 3 }}>
            <h1>👥 Team</h1>
            <p>Team management coming soon...</p>
          </Box>
        );
      case 'planning':
        return (
          <RequireFeature feature="planning">
            <PlanningGantt />
          </RequireFeature>
        );
      case 'planning-locations':
        return (
          <RequireFeature feature="planning">
            <PlanningGanttLocations />
          </RequireFeature>
        );
      case 'planning-users':
        return (
          <RequireFeature feature="planning">
            <PlanningGanttUsers />
          </RequireFeature>
        );
      case 'travels':
        return (
          <RequireFeature feature="travels">
            <TravelsList />
          </RequireFeature>
        );
      case 'admin':
        return <AdminDashboard />;
      case 'admin-projects':
        return <ProjectsManager />;
      case 'admin-tasks':
        return <TasksManager />;
      case 'admin-locations':
        return <LocationsManager />;
      case 'admin-countries':
        return <CountriesManager />;
      case 'admin-users':
        return <UsersManager />;
      case 'admin-access':
        return <AdminAccessManagerPage />;
      case 'billing':
        return <BillingPage />;
      case 'payment-methods':
        return <PaymentMethodsPage />;
      default:
        return <TimesheetCalendar />;
    }
};

  const handlePageChange = (page: string) => {
    const nextPage = page as Page;
    const targetPath = pageToPath[nextPage] || pageToPath[DEFAULT_PAGE];

    if (location.pathname !== targetPath) {
      navigate(targetPath);
    }
  };

  return (
    <Box sx={{ display: 'flex', minHeight: '100vh' }}>
      <SideMenu 
        currentPage={currentPage} 
        onPageChange={handlePageChange} 
      />
      <Box 
        component="main" 
        sx={{ 
          flexGrow: 1,
          minWidth: 0,
          p: 3,
          minHeight: '100vh',
          bgcolor: 'grey.50',
          overflowX: 'hidden'
        }}
      >
        {billingSummary?.read_only === true && !isTrialActive && (
          <Alert
            severity="warning"
            sx={{ mb: 2 }}
            action={
              <Button color="inherit" size="small" onClick={() => navigate('/billing')}>
                Upgrade
              </Button>
            }
          >
            Your subscription has expired. You are in read-only mode.
          </Alert>
        )}
        <Suspense fallback={<ModuleLoader />}>
          {renderPage()}
        </Suspense>
      </Box>
    </Box>
  );
};

// Main App Component
const App: React.FC = () => {
  return (
    <QueryClientProvider client={queryClient}>
      <ThemeProvider theme={theme}>
        <LocalizationProvider dateAdapter={AdapterDayjs} adapterLocale="pt">
          <CssBaseline />
          <AuthProvider>
            <NotificationProvider>
              <BillingProvider>
                <FeatureProvider>
                  <AppContent />
                </FeatureProvider>
              </BillingProvider>
            </NotificationProvider>
          </AuthProvider>
        </LocalizationProvider>
      </ThemeProvider>
    </QueryClientProvider>
  );
};

export default App;
