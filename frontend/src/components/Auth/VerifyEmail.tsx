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
import api, { API_URL } from '../../services/api';
import { CaptchaWidget, type CaptchaChallenge } from './CaptchaWidget';
import { useTranslation } from 'react-i18next';

type CaptchaStatus = 'idle' | 'required' | 'verifying' | 'verified' | 'expired';

const VerifyEmail: React.FC = () => {
  const { t } = useTranslation();
  const [searchParams] = useSearchParams();
  const navigate = useNavigate();
  const { showSuccess, showError } = useNotification();

  const token = useMemo(() => searchParams.get('token') ?? '', [searchParams]);
  const verifiedRaw = useMemo(() => searchParams.get('verified'), [searchParams]);
  const reason = useMemo(() => searchParams.get('reason') ?? '', [searchParams]);

  const verified = useMemo((): boolean | null => {
    if (verifiedRaw === null) return null;
    const v = String(verifiedRaw).trim().toLowerCase();
    if (v === '1' || v === 'true') return true;
    if (v === '0' || v === 'false') return false;
    return false;
  }, [verifiedRaw]);

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

  const completeRequest = useCallback(async (captchaTokenValue?: string | null) => {
    if (!token) {
      setStatus('error');
      setMessage(t('auth.verify.errors.missingToken'));
      setErrorType('missing_token');
      showError(t('auth.verify.errors.invalidLink'));
      return;
    }

    if (isRateLimited) {
      setStatus('error');
      setErrorType('rate_limited');
      setMessage(t('auth.verify.errors.rateLimited'));
      return;
    }

    setStatus('loading');

    try {
      const response = await api.post(
        '/api/tenants/complete-signup',
        {
          token,
          ...(captchaTokenValue ? { captcha_token: captchaTokenValue } : {}),
        },
        {
          // Expected flow: backend may return 422 captcha_required.
          // Avoid throwing AxiosError so we can handle it as a normal UI step.
          validateStatus: () => true,
        },
      );

      if (response.status === 200) {
        setStatus('success');
        setMessage(response.data.message || t('auth.verify.success.created'));
        showSuccess(t('auth.verify.success.redirecting'));

        setTimeout(() => {
          if (response.data.login_url) {
            window.location.href = response.data.login_url;
          } else {
            navigate('/login');
          }
        }, 3000);
        return;
      }

      // 429: terminal cooldown state
      if (response.status === 429) {
        resetCaptcha();
        setStatus('error');
        setErrorType('rate_limited');
        setMessage(t('auth.verify.errors.rateLimited'));

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
        setMessage(t('auth.verify.errors.securityCheck'));
        return;
      }

      if (response.status === 422 && response.data?.code === 'email_not_verified') {
        setStatus('error');
        setErrorType('email_not_verified');
        setMessage(t('auth.verify.errors.emailNotVerified'));
        showError(t('auth.verify.errors.emailNotVerifiedTitle'));
        return;
      }

      setStatus('error');
      setErrorType(response.data?.error || 'unknown');

      if (response.data?.error === 'expired') {
        setMessage(t('auth.verify.errors.expired'));
      } else if (response.data?.error === 'not_found') {
        setMessage(t('auth.verify.errors.notFound'));
      } else if (response.data?.error === 'slug_taken') {
        setMessage(t('auth.verify.errors.slugTaken'));
      } else {
        setMessage(response.data?.message || t('auth.verify.errors.signupFailed'));
      }

      showError(response.data?.message || t('auth.verify.errors.signupFailedTitle'));
    } catch (error: any) {
      console.error('Signup completion failed:', error);
      setStatus('error');
      setErrorType('unknown');
      setMessage(t('auth.verify.errors.signupFailed'));
      showError(t('auth.verify.errors.signupFailedTitle'));
    }
  }, [isRateLimited, navigate, resetCaptcha, showError, showSuccess, startCooldown, t, token]);

  useEffect(() => {
    resetCaptcha();
    setErrorType('');
    setMessage('');
    setRateLimitedUntil(null);

    if (!token) {
      setStatus('error');
      setMessage(t('auth.verify.errors.missingToken'));
      setErrorType('missing_token');
      return;
    }

    // Legacy links (or dev helpers) may still point to /verify-signup?token=... without redirect flags.
    // In that case, hand off to the backend browser endpoint which will 302 back with verified=1/0.
    if (verified === null) {
      setStatus('loading');
      window.location.replace(`${API_URL.replace(/\/+$/, '')}/tenants/verify-signup?token=${encodeURIComponent(token)}`);
      return;
    }

    if (verified !== true) {
      setStatus('error');
      setErrorType(reason || 'unknown');

      if (reason === 'expired') {
        setMessage(t('auth.verify.errors.expired'));
      } else if (reason === 'not_found') {
        setMessage(t('auth.verify.errors.notFound'));
      } else if (reason === 'missing_token') {
        setMessage(t('auth.verify.errors.missingTokenLink'));
      } else {
        setMessage(t('auth.verify.errors.verificationFailed'));
      }
      return;
    }

    // Email verified -> final submit creates tenant (may still require CAPTCHA).
    void completeRequest(null);
  }, [API_URL, completeRequest, reason, resetCaptcha, t, token, verified]);

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
              {t('auth.verify.loadingTitle')}
            </Typography>
            <Typography variant="body2" color="text.secondary">
              {t('auth.verify.loadingBody')}
            </Typography>
          </>
        )}

        {status === 'success' && (
          <>
            <CheckCircleIcon sx={{ fontSize: 64, color: 'success.main', mb: 2 }} />
            <Typography variant="h5" gutterBottom color="success.main">
              {t('auth.verify.success.title')}
            </Typography>
            <Typography variant="body1" color="text.secondary" sx={{ mb: 3 }}>
              {message}
            </Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mb: 3 }}>
              {t('auth.verify.success.redirecting')}
            </Typography>
            <CircularProgress size={24} />
          </>
        )}

        {status === 'captcha' && (
          <>
            <ErrorIcon sx={{ fontSize: 64, color: 'warning.main', mb: 2 }} />
            <Typography variant="h5" gutterBottom color="warning.main">
              {t('auth.verify.captcha.title')}
            </Typography>
            <Typography variant="body1" color="text.secondary" sx={{ mb: 2 }}>
              {message || t('auth.verify.captcha.body')}
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
                onClick={() => completeRequest(captchaToken)}
                fullWidth
                disabled={isRateLimited || captchaStatus === 'verifying' || captchaStatus !== 'verified' || !captchaToken}
              >
                {t('auth.verify.captcha.submit')}
              </Button>
              <Button
                variant="outlined"
                onClick={() => navigate('/')}
                fullWidth
              >
                {t('auth.verify.common.goHome')}
              </Button>
            </Box>
          </>
        )}

        {status === 'error' && (
          <>
            <ErrorIcon sx={{ fontSize: 64, color: 'error.main', mb: 2 }} />
            <Typography variant="h5" gutterBottom color="error.main">
              {t('auth.verify.error.title')}
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
                  {t('auth.verify.error.retryRegistration')}
                </Button>
              )}
              
              {errorType === 'already_verified' && (
                <Button
                  variant="contained"
                  onClick={() => navigate('/login')}
                  fullWidth
                >
                  {t('auth.verify.error.goLogin')}
                </Button>
              )}
              
              <Button
                variant="outlined"
                onClick={() => navigate('/')}
                fullWidth
              >
                {t('auth.verify.common.goHome')}
              </Button>
            </Box>
          </>
        )}
      </Paper>
    </Box>
  );
};

export default VerifyEmail;
