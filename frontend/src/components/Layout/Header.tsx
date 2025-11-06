import { AppBar, Toolbar, Typography, Button, Box } from '@mui/material';
import { useNavigate } from 'react-router-dom';
import { useAuth } from '../Auth/AuthContext';

export const Header = () => {
  const navigate = useNavigate();
  const { user, logout } = useAuth();

  const handleLogout = () => {
    logout();
    navigate('/login');
  };

  return (
    <AppBar position="static" sx={{ backgroundColor: '#1976d2' }}>
      <Toolbar>
        <Box sx={{ flexGrow: 1 }}>
          <Typography variant="h6" component="div" sx={{ fontWeight: 'bold', lineHeight: 1.2 }}>
            TimePerk Cortex
          </Typography>
          <Typography 
            variant="caption" 
            component="div" 
            sx={{ 
              fontStyle: 'italic', 
              fontSize: '0.7rem', 
              opacity: 0.8,
              lineHeight: 1
            }}
          >
            The brain behind your team's time
          </Typography>
        </Box>
        
        {user && (
          <Box sx={{ display: 'flex', gap: 2 }}>
            <Button 
              color="inherit" 
              onClick={() => navigate('/timesheets')}
            >
              Timesheets
            </Button>
            <Button 
              color="inherit" 
              onClick={() => navigate('/expenses')}
            >
              Expenses
            </Button>
            {user.role === 'Manager' && (
              <Button 
                color="inherit" 
                onClick={() => navigate('/approval')}
              >
                Approvals
              </Button>
            )}
            <Button 
              color="inherit" 
              onClick={handleLogout}
            >
              Logout ({user.name})
            </Button>
          </Box>
        )}
      </Toolbar>
    </AppBar>
  );
};