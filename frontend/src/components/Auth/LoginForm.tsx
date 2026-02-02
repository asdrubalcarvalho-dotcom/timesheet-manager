import React, { useCallback, useEffect, useRef, useState } from 'react';
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
import { useLocation, useNavigate } from 'react-router-dom';
import { CaptchaWidget, type CaptchaChallenge } from './CaptchaWidget';
import { API_URL } from '../../services/api';
import { useTranslation } from 'react-i18next';

type CaptchaStatus = 'idle' | 'required' | 'verifying' | 'verified' | 'expired';

export const LoginForm: React.FC = () => {
  const { t } = useTranslation();
  const [email, setEmail] = useState('');
  const [password, setPassword] = useState('');
  const [tenantSlug, setTenantSlug] = useState('');
  const [error, setError] = useState('');
  const [ssoFailureNoticeOpen, setSsoFailureNoticeOpen] = useState(false);
  const [loading, setLoading] = useState(false);
  const [captcha, setCaptcha] = useState<CaptchaChallenge | null>(null);
  const [captchaToken, setCaptchaToken] = useState<string | null>(null);
  const [captchaStatus, setCaptchaStatus] = useState<CaptchaStatus>('idle');
  const [captchaWidgetKey, setCaptchaWidgetKey] = useState(0);
  const [rateLimitedUntil, setRateLimitedUntil] = useState<number | null>(null);
  const rateLimitTimerRef = useRef<number | null>(null);
  const hasProcessedSsoErrorRef = useRef(false);
  const [ssoOnly, setSsoOnly] = useState(false);
  const [ssoOnlyForced, setSsoOnlyForced] = useState(false);
  const [tenantCheckLoading, setTenantCheckLoading] = useState(false);
  const tenantCheckAbortRef = useRef<AbortController | null>(null);
  
  const { login } = useAuth();
  const location = useLocation();
  const navigate = useNavigate();

  const getSsoOnlyKey = (slug: string) => `tenant_sso_only:${slug}`;

  const startSso = (provider: 'google' | 'microsoft') => {
    const slug = tenantSlug.trim();
    if (!slug) {
      setError(t('auth.login.errors.workspaceRequired'));
      return;
    }

    const url = `${API_URL}/auth/${provider}/redirect?tenant=${encodeURIComponent(slug)}`;
    window.location.assign(url);
  };

  // Provider-agnostic SSO failure UX: show once on initial page load.
  // Must not trigger retries or affect CAPTCHA/rate limiting.
  useEffect(() => {
    if (hasProcessedSsoErrorRef.current) return;
    hasProcessedSsoErrorRef.current = true;

    const params = new URLSearchParams(location.search);
    if (params.get('error') === 'sso_failed') {
      setSsoFailureNoticeOpen(true);
    }
  }, [location.search]);

  useEffect(() => {
    const slug = tenantSlug.trim();

    if (!slug) {
      setTenantCheckLoading(false);
      setSsoOnly(false);
      setSsoOnlyForced(false);
      return;
    }

    // If backend already enforced SSO-only for this tenant, don't let the
    // pre-check endpoint override it.
    if (ssoOnlyForced) {
      setTenantCheckLoading(false);
      return;
    }

    // Debounce to avoid hitting the throttle while typing.
    const handle = window.setTimeout(async () => {
      try {
        tenantCheckAbortRef.current?.abort();
        const controller = new AbortController();
        tenantCheckAbortRef.current = controller;

        setTenantCheckLoading(true);

        const res = await fetch(`${API_URL}/api/tenants/check-slug?slug=${encodeURIComponent(slug)}`,
          {
            headers: {
              Accept: 'application/json',
            },
            signal: controller.signal,
          }
        );

        if (!res.ok) {
          // If tenant check fails, don't force SSO-only UI.
          setSsoOnly(false);
          return;
        }

        const data: unknown = await res.json();
        if (typeof data === 'object' && data !== null) {
          const requireSso = (data as Record<string, unknown>).require_sso;
          if (typeof requireSso === 'boolean') {
            setSsoOnly(requireSso);
            localStorage.setItem(getSsoOnlyKey(slug), requireSso ? 'true' : 'false');
            return;
          }
        }

        // Fallback: keep any cached hint.
        setSsoOnly(localStorage.getItem(getSsoOnlyKey(slug)) === 'true');
      } catch (e) {
        if (e instanceof DOMException && e.name === 'AbortError') return;
        setSsoOnly(localStorage.getItem(getSsoOnlyKey(slug)) === 'true');
      } finally {
        setTenantCheckLoading(false);
      }
    }, 500);

    return () => {
      window.clearTimeout(handle);
    };
  }, [ssoOnlyForced, tenantSlug]);

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

  const isRateLimited = rateLimitedUntil !== null && Date.now() < rateLimitedUntil;

  const attemptLogin = useCallback(async (token?: string | null) => {
    if (isRateLimited) {
      return;
    }

    setLoading(true);
    setError('');

    const result = await login(email, password, tenantSlug, token ?? null);

    if (result.ok) {
      resetCaptcha();
      setRateLimitedUntil(null);
      setSsoOnly(false);
      setLoading(false);
      return;
    }

    // 429: terminal state (no retry, no captcha reload, no auto-submit)
    if ('rateLimited' in result && result.rateLimited) {
      const seconds = typeof result.retryAfterSeconds === 'number' && result.retryAfterSeconds > 0
        ? result.retryAfterSeconds
        : 60;

      resetCaptcha();
      setSsoOnly(false);
      setError(t('auth.login.errors.rateLimited'));

      const until = Date.now() + seconds * 1000;
      setRateLimitedUntil(until);

      if (rateLimitTimerRef.current) {
        window.clearTimeout(rateLimitTimerRef.current);
      }
      rateLimitTimerRef.current = window.setTimeout(() => {
        setRateLimitedUntil(null);
      }, seconds * 1000);

      setLoading(false);
      return;
    }

    if ('ssoOnlyRequired' in result && result.ssoOnlyRequired) {
      resetCaptcha();
      setSsoOnly(true);
      setSsoOnlyForced(true);
      const slug = tenantSlug.trim();
      if (slug) {
        localStorage.setItem(getSsoOnlyKey(slug), 'true');
      }
      setLoading(false);
      return;
    }

    if ('captchaRequired' in result && result.captchaRequired) {
      setCaptcha(result.captcha);
      setCaptchaToken(null);
      setCaptchaStatus('required');
      setCaptchaWidgetKey((k) => k + 1);
      setError(t('auth.login.errors.securityCheck'));
      setLoading(false);
      return;
    }

    if ('error' in result) {
      setError(result.error);
    } else {
      setError(t('auth.login.errors.signInFailed'));
    }
    setLoading(false);
  }, [email, isRateLimited, login, password, resetCaptcha, t, tenantSlug]);

  const handleCaptchaToken = useCallback(
    (token: string) => {
      if (isRateLimited) {
        return;
      }
      setCaptchaToken(token);
      setCaptchaStatus('verified');
    },
    [isRateLimited]
  );

  const handleCaptchaExpire = useCallback(() => {
    setCaptchaToken(null);
    setCaptchaStatus('expired');
  }, []);

  const handleCaptchaVerifying = useCallback(() => {
    setCaptchaStatus('verifying');
  }, []);

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();

    setError('');

    if (isRateLimited) {
      setError(t('auth.login.errors.rateLimited'));
      return;
    }

    if (ssoOnly) {
      setError(t('auth.login.errors.ssoOnly'));
      return;
    }

    // Basic validation
    if (!email || !password || !tenantSlug) {
      setError(t('auth.login.errors.missingFields'));
      return;
    }

    if (captcha) {
      if (captchaStatus !== 'verified' || !captchaToken) {
        setError(t('auth.login.errors.securityCheck'));
        return;
      }
      await attemptLogin(captchaToken);
      return;
    }

    await attemptLogin(null);
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
                    {t('auth.login.aiCortex')}
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
                {t('auth.login.tagline')}
              </Typography>
              <Typography variant="h6" color="text.secondary">
                {t('auth.login.title')}
              </Typography>
            </Box>

            {error && (
              <Alert severity="error" sx={{ mb: 2 }}>
                {error}
              </Alert>
            )}

            {ssoFailureNoticeOpen && (
              <Alert
                severity="warning"
                sx={{ mb: 2 }}
                onClose={() => setSsoFailureNoticeOpen(false)}
              >
                {t('auth.login.errors.ssoFailed')}
              </Alert>
            )}

            {ssoOnly && (
              <Alert severity="info" sx={{ mb: 2 }}>
                {t('auth.login.ssoOnlyNotice')}
                <Box sx={{ mt: 1 }}>
                  <Button
                    size="small"
                    onClick={() => {
                      const slug = tenantSlug.trim();
                      if (slug) {
                        localStorage.removeItem(getSsoOnlyKey(slug));
                      }
                      setSsoOnly(false);
                      setSsoOnlyForced(false);
                    }}
                    sx={{ textTransform: 'none', p: 0, minWidth: 'auto' }}
                  >
                    {t('auth.login.usePasswordInstead')}
                  </Button>
                </Box>
              </Alert>
            )}

            <Box component="form" onSubmit={handleSubmit} sx={{ mt: 1 }}>
              <TextField
                margin="normal"
                required
                fullWidth
                size="small"
                id="tenant"
                label={t('auth.login.tenantLabel')}
                name="tenant"
                placeholder={t('auth.login.tenantPlaceholder')}
                helperText={t('auth.login.tenantHelper')}
                value={tenantSlug}
                onChange={(e) => {
                  const next = e.target.value;
                  setTenantSlug(next);

                  // Changing tenant releases any backend-enforced SSO-only state.
                  setSsoOnlyForced(false);

                  // Reset CAPTCHA only when user changes tenant.
                  resetCaptcha();

                  // If user changes tenant, drop previous SSO-only hint.
                  // Backend remains the source of truth; we only switch UI after a real 403.
                  const slug = next.trim();
                  if (!slug) {
                    setSsoOnly(false);
                    return;
                  }

                  setSsoOnly(localStorage.getItem(getSsoOnlyKey(slug)) === 'true');
                }}
              />

              {!ssoOnly && (
                <>
                  <TextField
                    margin="normal"
                    required
                    fullWidth
                    size="small"
                    id="email"
                    label={t('auth.login.emailLabel')}
                    name="email"
                    autoComplete="email"
                    autoFocus
                    value={email}
                    onChange={(e) => {
                      setEmail(e.target.value);

                      // Reset CAPTCHA only when user changes email.
                      resetCaptcha();
                    }}
                  />
                  <TextField
                    margin="normal"
                    required
                    fullWidth
                    size="small"
                    name="password"
                    label={t('auth.login.passwordLabel')}
                    type="password"
                    id="password"
                    autoComplete="current-password"
                    value={password}
                    onChange={(e) => {
                      setPassword(e.target.value);
                    }}
                  />

                  {captcha && (
                    <CaptchaWidget
                      key={captchaWidgetKey}
                      challenge={captcha}
                      onVerifying={handleCaptchaVerifying}
                      onToken={handleCaptchaToken}
                      onExpire={handleCaptchaExpire}
                    />
                  )}

                  <Button
                    type="submit"
                    fullWidth
                    variant="contained"
                    sx={{ mt: 3, mb: 2 }}
                    disabled={loading || isRateLimited || captchaStatus === 'verifying'}
                  >
                    {loading ? t('auth.login.signingIn') : t('auth.login.signIn')}
                  </Button>
                </>
              )}

              {/* SSO Options */}
              <Box sx={{ mt: 2, mb: 2 }}>
                <Box sx={{ display: 'flex', alignItems: 'center', mb: 2 }}>
                  <Box sx={{ flex: 1, height: '1px', bgcolor: 'grey.300' }} />
                  <Typography variant="caption" sx={{ px: 2, color: 'text.secondary' }}>
                    {ssoOnly ? t('auth.login.continueWith') : t('auth.login.orContinueWith')}
                  </Typography>
                  <Box sx={{ flex: 1, height: '1px', bgcolor: 'grey.300' }} />
                </Box>
                
                <Box sx={{ display: 'flex', gap: 1, flexDirection: 'column' }}>
                  <Button
                    fullWidth
                    variant="outlined"
                    disabled={loading || tenantCheckLoading || !tenantSlug.trim()}
                    sx={{ 
                      textTransform: 'none',
                      justifyContent: 'center',
                      py: 1
                    }}
                    onClick={() => startSso('microsoft')}
                  >
                    {t('auth.login.microsoftSso')}
                  </Button>
                  <Button
                    fullWidth
                    variant="outlined"
                    disabled={loading || tenantCheckLoading || !tenantSlug.trim()}
                    sx={{ 
                      textTransform: 'none',
                      justifyContent: 'center',
                      py: 1
                    }}
                    onClick={() => startSso('google')}
                  >
                    {t('auth.login.googleSso')}
                  </Button>
                </Box>
              </Box>

              {/* Sign up link */}
              <Box sx={{ mt: 3, textAlign: 'center' }}>
                <Typography variant="body2" color="text.secondary">
                  {t('auth.login.noWorkspace')}{' '}
                  <Button
                    onClick={() => navigate('/register')}
                    sx={{ 
                      textTransform: 'none',
                      fontWeight: 600,
                      p: 0,
                      minWidth: 'auto'
                    }}
                  >
                    {t('auth.login.createOne')}
                  </Button>
                </Typography>
              </Box>
            </Box>
          </CardContent>
        </Card>
      </Box>
    </Container>
  );
};