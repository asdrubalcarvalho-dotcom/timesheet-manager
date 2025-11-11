import React, { useState } from 'react';
import {
  Box,
  Card,
  CardContent,
  TextField,
  Button,
  Typography,
  Alert,
  Container,
  Avatar
} from '@mui/material';
import { SmartToy as RobotIcon } from '@mui/icons-material';
import { useAuth } from './AuthContext';

export const LoginForm: React.FC = () => {
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [error, setError] = useState('');
  const [loading, setLoading] = useState(false);
  
  const { login } = useAuth();

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setLoading(true);
    setError('');

    // Basic validation
    if (!email || !password) {
      setError('Please enter both email and password');
      setLoading(false);
      return;
    }

    console.log('Form submitting with:', { email, password: '***' });
    const success = await login(email, password);
    
    if (!success) {
      setError('Invalid email or password');
    }
    // Note: Navigation is handled automatically by App.tsx when user state changes
    
    setLoading(false);
  };

  return (
    <Container maxWidth="xs">
      <Box
        sx={{
          marginTop: 8,
          display: 'flex',
          flexDirection: 'column',
          alignItems: 'center',
        }}
      >
        <Card sx={{ width: '100%', maxWidth: 360 }}>
          <CardContent sx={{ p: 3 }}>
            <Box sx={{ textAlign: 'center', mb: 2 }}>
              <Box
                sx={{
                  display: 'inline-flex',
                  alignItems: 'flex-start',
                  justifyContent: 'center',
                  gap: 0.75,
                  mb: 1
                }}
              >
                <Typography component="h1" variant="h4" sx={{ fontWeight: 'bold', lineHeight: 1.2 }}>
                  TimePerk
                </Typography>
                <Box
                  sx={{
                    display: 'inline-flex',
                    alignItems: 'center',
                    gap: 0.4,
                    background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                    borderRadius: '8px',
                    padding: '2px 6px',
                    boxShadow: '0 2px 6px rgba(102, 126, 234, 0.25)',
                    transform: 'translateY(-2px)'
                  }}
                >
                  <Avatar sx={{ 
                    bgcolor: 'rgba(255, 255, 255, 0.2)', 
                    width: 14, 
                    height: 14,
                    '& .MuiSvgIcon-root': { fontSize: 10 }
                  }}>
                    <RobotIcon />
                  </Avatar>
                  <Typography 
                    variant="caption" 
                    sx={{ 
                      color: 'white', 
                      fontWeight: 700,
                      fontSize: '0.5rem',
                      letterSpacing: 0.2,
                      whiteSpace: 'nowrap'
                    }}
                  >
                    AI CORTEX
                  </Typography>
                </Box>
              </Box>
              <Typography 
                variant="body2" 
                sx={{ 
                  fontStyle: 'italic', 
                  color: 'text.secondary',
                  fontSize: '0.85rem',
                  mt: 0.5,
                  mb: 2
                }}
              >
                The brain behind your team's time
              </Typography>
              <Typography variant="h6" color="text.secondary">
                Sign In
              </Typography>
            </Box>

            {error && (
              <Alert severity="error" sx={{ mb: 2 }}>
                {error}
              </Alert>
            )}

            <Box component="form" onSubmit={handleSubmit} sx={{ mt: 1 }}>
              <TextField
                margin="normal"
                required
                fullWidth
                size="small"
                id="email"
                label="Email Address"
                name="email"
                autoComplete="email"
                autoFocus
                value={email}
                onChange={(e) => setEmail(e.target.value)}
              />
              <TextField
                margin="normal"
                required
                fullWidth
                size="small"
                name="password"
                label="Password"
                type="password"
                id="password"
                autoComplete="current-password"
                value={password}
                onChange={(e) => setPassword(e.target.value)}
              />
              <Button
                type="submit"
                fullWidth
                variant="contained"
                sx={{ mt: 3, mb: 2 }}
                disabled={loading}
              >
                {loading ? 'Signing in...' : 'Sign In'}
              </Button>

              {/* SSO Options */}
              <Box sx={{ mt: 2, mb: 2 }}>
                <Box sx={{ display: 'flex', alignItems: 'center', mb: 2 }}>
                  <Box sx={{ flex: 1, height: '1px', bgcolor: 'grey.300' }} />
                  <Typography variant="caption" sx={{ px: 2, color: 'text.secondary' }}>
                    or continue with
                  </Typography>
                  <Box sx={{ flex: 1, height: '1px', bgcolor: 'grey.300' }} />
                </Box>
                
                <Box sx={{ display: 'flex', gap: 1, flexDirection: 'column' }}>
                  <Button
                    fullWidth
                    variant="outlined"
                    disabled
                    sx={{ 
                      textTransform: 'none',
                      justifyContent: 'center',
                      py: 1
                    }}
                  >
                    Sign in with Microsoft
                  </Button>
                  <Button
                    fullWidth
                    variant="outlined"
                    disabled
                    sx={{ 
                      textTransform: 'none',
                      justifyContent: 'center',
                      py: 1
                    }}
                  >
                    Sign in with Google
                  </Button>
                </Box>
              </Box>

              {/* Demo Accounts */}
              <Box sx={{ mt: 2, display: 'flex', gap: 1, flexDirection: 'column' }}>
                <Typography variant="caption" color="text.secondary" align="center">
                  Demo Accounts:
                </Typography>
                <Box sx={{ display: 'flex', gap: 1, justifyContent: 'center' }}>
                  <Button
                    size="small"
                    variant="outlined"
                    onClick={() => {
                      setEmail('joao.silva@example.com');
                      setPassword('password');
                    }}
                    disabled={loading}
                    sx={{ flex: 1, minWidth: 0 }}
                  >
                    Tech
                  </Button>
                  <Button
                    size="small"
                    variant="outlined"
                    onClick={() => {
                      setEmail('carlos.manager@example.com');
                      setPassword('password');
                    }}
                    disabled={loading}
                    sx={{ flex: 1, minWidth: 0 }}
                  >
                    Manag
                  </Button>
                  <Button
                    size="small"
                    variant="outlined"
                    onClick={() => {
                      setEmail('admin@timeperk.com');
                      setPassword('admin123');
                    }}
                    disabled={loading}
                    sx={{ flex: 1, minWidth: 0 }}
                  >
                    Admin
                  </Button>
                </Box>
              </Box>
            </Box>
          </CardContent>
        </Card>
      </Box>
    </Container>
  );
};