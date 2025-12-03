import React, { useEffect, useState } from 'react';
import { useSearchParams, useNavigate } from 'react-router-dom';
import {
  Box,
  Paper,
  Typography,
  CircularProgress,
  Button,
} from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import ErrorIcon from '@mui/icons-material/Error';
import { useNotification } from '../../contexts/NotificationContext';
import api from '../../services/api';

const VerifyEmail: React.FC = () => {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { showSuccess, showError } = useNotification();
  
  const [status, setStatus] = useState<'loading' | 'success' | 'error'>('loading');
  const [message, setMessage] = useState<string>('');
  const [errorType, setErrorType] = useState<string>('');

  useEffect(() => {
    const verifyEmail = async () => {
      const token = searchParams.get('token');

      if (!token) {
        setStatus('error');
        setMessage('Verification token is missing');
        setErrorType('missing_token');
        showError('Invalid verification link');
        return;
      }

      try {
        const response = await api.get('/api/tenants/verify-signup', {
          params: { token }
        });

        setStatus('success');
        setMessage(response.data.message || 'Email verified successfully!');
        showSuccess('Your workspace has been created! Redirecting to login...');

        // Redirect to login after 3 seconds
        setTimeout(() => {
          if (response.data.login_url) {
            window.location.href = response.data.login_url;
          } else {
            navigate('/login');
          }
        }, 3000);

      } catch (error: any) {
        console.error('Verification failed:', error);
        
        setStatus('error');
        setErrorType(error.response?.data?.error || 'unknown');
        
        if (error.response?.data?.error === 'expired') {
          setMessage('This verification link has expired. Please start the registration process again.');
        } else if (error.response?.data?.error === 'already_verified') {
          setMessage('This email has already been verified. Please sign in.');
        } else if (error.response?.data?.error === 'not_found') {
          setMessage('Invalid verification link. Please check your email or start registration again.');
        } else if (error.response?.data?.error === 'slug_taken') {
          setMessage('This workspace name is no longer available. Please start registration again with a different name.');
        } else {
          setMessage(error.response?.data?.message || 'Verification failed. Please try again or contact support.');
        }
        
        showError(error.response?.data?.message || 'Verification failed');
      }
    };

    verifyEmail();
  }, [searchParams, navigate, showSuccess, showError]);

  return (
    <Box
      sx={{
        minHeight: '100vh',
        display: 'flex',
        alignItems: 'center',
        justifyContent: 'center',
        bgcolor: 'background.default',
        py: 4,
      }}
    >
      <Paper
        elevation={3}
        sx={{
          p: 4,
          maxWidth: 500,
          width: '100%',
          mx: 2,
          textAlign: 'center',
        }}
      >
        {status === 'loading' && (
          <>
            <CircularProgress size={64} sx={{ mb: 3 }} />
            <Typography variant="h5" gutterBottom>
              Verifying your email...
            </Typography>
            <Typography variant="body2" color="text.secondary">
              Please wait while we verify your email address.
            </Typography>
          </>
        )}

        {status === 'success' && (
          <>
            <CheckCircleIcon sx={{ fontSize: 64, color: 'success.main', mb: 2 }} />
            <Typography variant="h5" gutterBottom color="success.main">
              Email Verified!
            </Typography>
            <Typography variant="body1" color="text.secondary" sx={{ mb: 3 }}>
              {message}
            </Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
              Your workspace has been successfully created. Redirecting you to login...
            </Typography>
            <CircularProgress size={24} />
          </>
        )}

        {status === 'error' && (
          <>
            <ErrorIcon sx={{ fontSize: 64, color: 'error.main', mb: 2 }} />
            <Typography variant="h5" gutterBottom color="error.main">
              Verification Failed
            </Typography>
            <Typography variant="body1" color="text.secondary" sx={{ mb: 3 }}>
              {message}
            </Typography>
            
            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2 }}>
              {(errorType === 'expired' || errorType === 'slug_taken' || errorType === 'not_found') && (
                <Button
                  variant="contained"
                  onClick={() => navigate('/register')}
                  fullWidth
                >
                  Start Registration Again
                </Button>
              )}
              
              {errorType === 'already_verified' && (
                <Button
                  variant="contained"
                  onClick={() => navigate('/login')}
                  fullWidth
                >
                  Go to Login
                </Button>
              )}
              
              <Button
                variant="outlined"
                onClick={() => navigate('/')}
                fullWidth
              >
                Go to Home
              </Button>
            </Box>
          </>
        )}
      </Paper>
    </Box>
  );
};

export default VerifyEmail;
