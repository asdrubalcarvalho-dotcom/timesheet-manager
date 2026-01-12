import React, { useCallback, useEffect, useMemo, useRef, useState } from 'react';
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
import { CaptchaWidget, type CaptchaChallenge } from './CaptchaWidget';

type CaptchaStatus = 'idle' | 'required' | 'verifying' | 'verified' | 'expired';

const VerifyEmail: React.FC = () => {
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { showSuccess, showError } = useNotification();

  const token = useMemo(() => searchParams.get('token') ?? '', [searchParams]);

  const [status, setStatus] = useState<'loading' | 'success' | 'error' | 'captcha'>('loading');
  const [message, setMessage] = useState<string>('');
  const [errorType, setErrorType] = useState<string>('');

  const [captcha, setCaptcha] = useState<CaptchaChallenge | null>(null);
  const [captchaToken, setCaptchaToken] = useState<string | null>(null);
  const [captchaStatus, setCaptchaStatus] = useState<CaptchaStatus>('idle');
  const [captchaWidgetKey, setCaptchaWidgetKey] = useState(0);

  const [rateLimitedUntil, setRateLimitedUntil] = useState<number | null>(null);
  const rateLimitTimerRef = useRef<number | null>(null);
  const isRateLimited = rateLimitedUntil !== null && Date.now() < rateLimitedUntil;

  useEffect(() => {
    return () => {
      if (rateLimitTimerRef.current) {
        window.clearTimeout(rateLimitTimerRef.current);
        rateLimitTimerRef.current = null;
      }
    };
  }, []);

  const resetCaptcha = useCallback(() => {
    setCaptcha(null);
    setCaptchaToken(null);
    setCaptchaStatus('idle');
    setCaptchaWidgetKey((k) => k + 1);
  }, []);

  const handleCaptchaVerifying = useCallback(() => {
    setCaptchaStatus((prev) => (prev === 'verifying' ? prev : 'verifying'));
  }, []);

  const handleCaptchaToken = useCallback((tokenValue: string) => {
    if (isRateLimited) return;
    setCaptchaToken(tokenValue);
    setCaptchaStatus('verified');
  }, [isRateLimited]);

  const handleCaptchaExpire = useCallback(() => {
    setCaptchaToken(null);
    setCaptchaStatus('expired');
  }, []);

  const startCooldown = useCallback((seconds: number) => {
    const safeSeconds = Number.isFinite(seconds) && seconds > 0 ? Math.floor(seconds) : 60;
    const until = Date.now() + safeSeconds * 1000;

    setRateLimitedUntil(until);

    if (rateLimitTimerRef.current) {
      window.clearTimeout(rateLimitTimerRef.current);
    }

    rateLimitTimerRef.current = window.setTimeout(() => {
      setRateLimitedUntil(null);
    }, safeSeconds * 1000);
  }, []);

  const verifyRequest = useCallback(async (captchaTokenValue?: string | null) => {
    if (!token) {
      setStatus('error');
      setMessage('Verification token is missing');
      setErrorType('missing_token');
      showError('Invalid verification link');
      return;
    }

    if (isRateLimited) {
      setStatus('error');
      setErrorType('rate_limited');
      setMessage('Too many attempts. Please wait before trying again.');
      return;
    }

    setStatus('loading');

    try {
      const response = await api.get('/api/tenants/verify-signup', {
        params: {
          token,
          ...(captchaTokenValue ? { captcha_token: captchaTokenValue } : {}),
        },
        // Expected flow: backend may return 422 captcha_required.
        // Avoid throwing AxiosError so we can handle it as a normal UI step.
        validateStatus: () => true,
      });

      if (response.status === 200) {
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
        return;
      }

      // 429: terminal cooldown state (no CAPTCHA loop)
      if (response.status === 429) {
        resetCaptcha();
        setStatus('error');
        setErrorType('rate_limited');
        setMessage('Too many attempts. Please wait before trying again.');

        const retryAfterHeader = (response.headers as any)?.['retry-after'];
        const parsed = Number(retryAfterHeader);
        startCooldown(!Number.isNaN(parsed) && parsed > 0 ? parsed : 60);
        return;
      }

      // 422 captcha_required: show CAPTCHA UI (backend-driven)
      if (
        response.status === 422 &&
        response.data?.code === 'captcha_required' &&
        response.data?.captcha
      ) {
        setCaptcha({
          provider: String(response.data.captcha.provider || ''),
          site_key: String(response.data.captcha.site_key || ''),
        });
        setCaptchaToken(null);
        setCaptchaStatus('required');
        setCaptchaWidgetKey((k) => k + 1);
        setStatus('captcha');
        setErrorType('captcha_required');
        setMessage('Please complete the security check.');
        return;
      }

      setStatus('error');
      setErrorType(response.data?.error || 'unknown');

      if (response.data?.error === 'expired') {
        setMessage('This verification link has expired. Please start the registration process again.');
      } else if (response.data?.error === 'already_verified') {
        setMessage('This email has already been verified. Please sign in.');
      } else if (response.data?.error === 'not_found') {
        setMessage('Invalid verification link. Please check your email or start registration again.');
      } else if (response.data?.error === 'slug_taken') {
        setMessage('This workspace name is no longer available. Please start registration again with a different name.');
      } else {
        setMessage(response.data?.message || 'Verification failed. Please try again or contact support.');
      }

      showError(response.data?.message || 'Verification failed');
    } catch (error: any) {
      console.error('Verification failed:', error);
      setStatus('error');
      setErrorType('unknown');
      setMessage('Verification failed. Please try again or contact support.');
      showError('Verification failed');
    }
  }, [isRateLimited, navigate, resetCaptcha, showError, showSuccess, startCooldown, token]);

  useEffect(() => {
    // New token -> reset previous captcha state and retry without captcha.
    resetCaptcha();
    setErrorType('');
    setMessage('');
    setRateLimitedUntil(null);
    verifyRequest(null);
  }, [resetCaptcha, token, verifyRequest]);

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

        {status === 'captcha' && (
          <>
            <ErrorIcon sx={{ fontSize: 64, color: 'warning.main', mb: 2 }} />
            <Typography variant="h5" gutterBottom color="warning.main">
              Security Check Required
            </Typography>
            <Typography variant="body1" color="text.secondary" sx={{ mb: 2 }}>
              {message || 'Please complete the security check to continue.'}
            </Typography>

            {captcha && (
              <CaptchaWidget
                key={captchaWidgetKey}
                challenge={captcha}
                onVerifying={handleCaptchaVerifying}
                onToken={handleCaptchaToken}
                onExpire={handleCaptchaExpire}
              />
            )}

            <Box sx={{ display: 'flex', flexDirection: 'column', gap: 2, mt: 2 }}>
              <Button
                variant="contained"
                onClick={() => verifyRequest(captchaToken)}
                fullWidth
                disabled={isRateLimited || captchaStatus === 'verifying' || captchaStatus !== 'verified' || !captchaToken}
              >
                Verify Email
              </Button>
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
