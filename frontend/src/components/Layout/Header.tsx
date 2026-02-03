import { AppBar, Toolbar, Typography, Button, Box } from '@mui/material';
import { useNavigate } from 'react-router-dom';
import { useTranslation } from 'react-i18next';
import { useAuth } from '../Auth/AuthContext';

export const Header = () => {
  const navigate = useNavigate();
  const { t } = useTranslation();
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
            {t('header.tagline')}
          </Typography>
        </Box>
        
        {user && (
          <Box sx={{ display: 'flex', gap: 2 }}>
            <Button 
              color="inherit" 
              onClick={() => navigate('/timesheets')}
            >
              {t('nav.timesheets')}
            </Button>
            <Button 
              color="inherit" 
              onClick={() => navigate('/expenses')}
            >
              {t('nav.expenses')}
            </Button>
            {user.role === 'Manager' && (
              <Button 
                color="inherit" 
                onClick={() => navigate('/approval')}
              >
                {t('nav.approvals')}
              </Button>
            )}
            <Button 
              color="inherit" 
              onClick={handleLogout}
            >
              {t('nav.logoutWithName', { name: user.name })}
            </Button>
          </Box>
        )}
      </Toolbar>
    </AppBar>
  );
};