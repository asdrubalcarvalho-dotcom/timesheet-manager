import React, { useCallback, useEffect, useState } from 'react';
import {
  Box,
  Button,
  TextField,
  Typography,
  Paper,
  Checkbox,
  FormControlLabel,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  CircularProgress,
  InputAdornment,
  Link,
} from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import ErrorIcon from '@mui/icons-material/Error';
import BusinessIcon from '@mui/icons-material/Business';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';
import { useNavigate } from 'react-router-dom';
import { useNotification } from '../../contexts/NotificationContext';
import api from '../../services/api';
import { CaptchaWidget, type CaptchaChallenge } from './CaptchaWidget';
import { useTranslation } from 'react-i18next';

type CaptchaStatus = 'idle' | 'required' | 'verifying' | 'verified' | 'expired';

interface RegistrationFormData {
  company_name: string;
  slug: string;
  admin_name: string;
  admin_email: string;
  admin_password: string;
  admin_password_confirmation: string;
  region: 'EU' | 'US';
  industry?: string;
  country?: string;
  timezone?: string;
}

const TenantRegistration: React.FC = () => {
  const { t } = useTranslation();
  const navigate = useNavigate();
  const { showSuccess, showError } = useNotification();

  const [formData, setFormData] = useState<RegistrationFormData>({
    company_name: '',
    slug: '',
    admin_name: '',
    admin_email: '',
    admin_password: '',
    admin_password_confirmation: '',
    region: 'EU',
    industry: '',
    country: '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
  });

  const [loading, setLoading] = useState(false);
  const [registrationComplete, setRegistrationComplete] = useState(false);
  const [registeredEmail, setRegisteredEmail] = useState<string>('');
  const [expiresInHours, setExpiresInHours] = useState<number>(24);
  const [verificationUrl, setVerificationUrl] = useState<string>('');
  const [verificationToken, setVerificationToken] = useState<string>('');

  const [slugChecking, setSlugChecking] = useState(false);
  const [slugAvailable, setSlugAvailable] = useState<boolean | null>(null);
  const [slugError, setSlugError] = useState<string>('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});
  const [captcha, setCaptcha] = useState<CaptchaChallenge | null>(null);
  const [captchaToken, setCaptchaToken] = useState<string | null>(null);
  const [captchaStatus, setCaptchaStatus] = useState<CaptchaStatus>('idle');
  const [captchaWidgetKey, setCaptchaWidgetKey] = useState(0);
  const [legalAccepted, setLegalAccepted] = useState(false);

  const resetCaptcha = useCallback(() => {
    setCaptcha(null);
    setCaptchaToken(null);
    setCaptchaStatus('idle');
    setCaptchaWidgetKey((k) => k + 1);
  }, []);

  const handleCaptchaVerifying = useCallback(() => {
    setCaptchaStatus((prev) => (prev === 'verifying' ? prev : 'verifying'));
  }, []);

  const handleCaptchaExpire = useCallback(() => {
    setCaptchaToken(null);
    setCaptchaStatus('expired');
  }, []);

  const submitRequestSignup = async (token?: string | null) => {
    const res = await api.post(
      '/api/tenants/request-signup',
      {
      company_name: formData.company_name,
      slug: formData.slug,
      admin_name: formData.admin_name,
      admin_email: formData.admin_email,
      admin_password: formData.admin_password,
      admin_password_confirmation: formData.admin_password_confirmation,
      region: formData.region,
      industry: formData.industry || undefined,
      country: formData.country || undefined,
      timezone: formData.timezone || 'UTC',
      legal_accepted: legalAccepted,
      ...(token ? { captcha_token: token } : {}),
      },
      {
        // Expected flow: backend may return 422 captcha_required for risk/adaptive scenarios.
        // Avoid throwing AxiosError so we can handle it as a normal UI step.
        validateStatus: () => true,
      },
    );

    return res;
  };

  const attemptSignup = async (token?: string | null) => {
    setLoading(true);
    try {
      const response = await submitRequestSignup(token);
      const data = response.data;

      if (response.status === 200) {
        // Show confirmation screen instead of redirecting
        console.log('[TenantRegistration] API Response:', data);
        console.log('[TenantRegistration] Verification URL:', data?.verification_url);

        setRegisteredEmail(formData.admin_email);
        setExpiresInHours(data?.expires_in_hours ?? 24);
        setVerificationUrl(data?.verification_url || '');
        setVerificationToken(data?.verification_token || '');
        setRegistrationComplete(true);

        showSuccess(data?.message || t('auth.register.successEmailSent'));
        resetCaptcha();
        return;
      }

      // 422 captcha_required: expected step for risk/adaptive flows
      if (response.status === 422 && data?.code === 'captcha_required' && data?.captcha) {
        setCaptcha({
          provider: String(data.captcha.provider || ''),
          site_key: String(data.captcha.site_key || ''),
        });
        setCaptchaToken(null);
        setCaptchaStatus('required');
        setCaptchaWidgetKey((k) => k + 1);
        return;
      }

      if (data?.errors) {
        setErrors(data.errors);
        showError(t('auth.register.errors.fixFormErrors'));
        return;
      }

      if (data?.message) {
        showError(data.message);
        return;
      }

      showError(t('auth.register.errors.signupFailed'));
    } catch (error: any) {
      console.error('Registration failed:', error);
      showError(error?.message || t('auth.register.errors.signupFailed'));
    } finally {
      setLoading(false);
    }
  };

  const handleCaptchaToken = useCallback((token: string) => {
    setCaptchaToken(token);
    setCaptchaStatus('verified');

    // IMPORTANT: Do not auto-submit on CAPTCHA solve.
    // If the backend returned 422 (validation or captcha_required), auto-resubmitting can
    // cause Cloudflare Turnstile to re-trigger in a loop. Require an explicit user click.
  }, []);

  // Auto-generate slug
  useEffect(() => {
    if (formData.company_name && !formData.slug) {
      const generated = formData.company_name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .substring(0, 50);
      setFormData(prev => ({ ...prev, slug: generated }));
    }
  }, [formData.company_name, formData.slug]);

  // Check slug availability
  useEffect(() => {
    if (!formData.slug || formData.slug.length < 3) {
      setSlugAvailable(null);
      setSlugError('');
      return;
    }

    if (!/^[a-z0-9-]+$/.test(formData.slug)) {
      setSlugAvailable(false);
      setSlugError(t('auth.register.errors.slugFormat'));
      return;
    }

    const timer = setTimeout(async () => {
      setSlugChecking(true);
      setSlugError('');
      try {
        const res = await api.get('/api/tenants/check-slug', {
          params: { slug: formData.slug },
        });
        setSlugAvailable(res.data.available);
        if (!res.data.available) {
          setSlugError(res.data.message || t('auth.register.errors.slugUnavailable'));
        }
      } catch {
        setSlugAvailable(null);
      } finally {
        setSlugChecking(false);
      }
    }, 500);

    return () => clearTimeout(timer);
  }, [formData.slug, t]);

  const handleChange = (field: keyof RegistrationFormData) => (
    event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement> | { target: { value: string } },
  ) => {
    setFormData(prev => ({ ...prev, [field]: event.target.value }));

    // Reset CAPTCHA only when user changes email or tenant slug.
    if (field === 'admin_email' || field === 'slug') {
      resetCaptcha();
    }

    if (errors[field]) {
      setErrors(prev => {
        const updated = { ...prev };
        delete updated[field];
        return updated;
      });
    }
  };

  const handleSubmit = async (e: React.FormEvent) => {
    e.preventDefault();
    setErrors({});

    const newErrors: Record<string, string[]> = {};
    if (!formData.company_name) newErrors.company_name = [t('auth.register.errors.companyRequired')];
    if (!formData.slug) newErrors.slug = [t('auth.register.errors.slugRequired')];
    if (!slugAvailable) newErrors.slug = [t('auth.register.errors.slugChooseAvailable')];
    if (!formData.admin_name) newErrors.admin_name = [t('auth.register.errors.adminNameRequired')];
    if (!formData.admin_email) newErrors.admin_email = [t('auth.register.errors.adminEmailRequired')];
    if (!formData.admin_password) newErrors.admin_password = [t('auth.register.errors.passwordRequired')];
    if (formData.admin_password.length < 8)
      newErrors.admin_password = [t('auth.register.errors.passwordMin')];
    if (formData.admin_password !== formData.admin_password_confirmation)
      newErrors.admin_password_confirmation = [t('auth.register.errors.passwordMismatch')];
    if (!legalAccepted)
      newErrors.legal_accepted = [t('auth.register.errors.legalRequired')];

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      showError(t('auth.register.errors.fixFormErrors'));
      return;
    }

    if (captcha) {
      if (captchaStatus !== 'verified' || !captchaToken) {
        // Informational banner is shown while CAPTCHA is required but incomplete.
        // This state should not be treated as an error.
        setCaptchaStatus((prev) => (prev === 'verifying' ? prev : 'required'));
        return;
      }
      await attemptSignup(captchaToken);
      return;
    }

    await attemptSignup(null);
  };

  const industries = [
    'Technology',
    'Healthcare',
    'Finance',
    'Education',
    'Manufacturing',
    'Retail',
    'Consulting',
    'Construction',
    'Other',
  ];

  const countries = [
    { code: 'PT', name: 'Portugal' },
    { code: 'ES', name: 'Spain' },
    { code: 'FR', name: 'France' },
    { code: 'DE', name: 'Germany' },
    { code: 'GB', name: 'United Kingdom' },
    { code: 'US', name: 'United States' },
    { code: 'BR', name: 'Brazil' },
  ];

  if (registrationComplete) {
    const copyToClipboard = async (text: string) => {
      try {
        if (navigator.clipboard?.writeText) {
          await navigator.clipboard.writeText(text);
          return;
        }

        // Fallback for older browsers / restricted contexts
        const textarea = document.createElement('textarea');
        textarea.value = text;
        textarea.style.position = 'fixed';
        textarea.style.left = '-9999px';
        document.body.appendChild(textarea);
        textarea.focus();
        textarea.select();
        document.execCommand('copy');
        document.body.removeChild(textarea);
      } catch (e) {
        throw e;
      }
    };

    return (
      <Box
        sx={{
          minHeight: '100vh',
          display: 'flex',
          alignItems: 'center',
          justifyContent: 'center',
          bgcolor: 'background.default',
          p: 4,
        }}
      >
        <Paper sx={{ p: 4, maxWidth: 600, width: '100%', textAlign: 'center' }}>
          <CheckCircleIcon sx={{ fontSize: 64, color: 'success.main', mb: 2 }} />

          <Typography variant="h4" gutterBottom>
            {t('auth.register.checkEmailTitle')}
          </Typography>

          <Typography variant="body1" sx={{ mb: 1 }}>
            {t('auth.register.checkEmailSubtitle')}
          </Typography>

          <Typography variant="h6" sx={{ mb: 3 }}>
            {registeredEmail}
          </Typography>

          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            {t('auth.register.checkEmailBody')}
          </Typography>

          <Typography variant="caption" display="block" sx={{ mb: 3 }}>
            {t('auth.register.expiresIn', { hours: expiresInHours })}
          </Typography>

          {verificationUrl && (
            <Box sx={{ mb: 3, p: 2, bgcolor: 'warning.light', borderRadius: 1 }}>
              <Typography variant="caption" display="block" fontWeight="bold" sx={{ mb: 1 }}>
                {t('auth.register.devLinkLabel')}
              </Typography>
              <Button
                variant="contained"
                color="warning"
                size="small"
                fullWidth
                onClick={async () => {
                  try {
                    const payload = [
                      `URL: ${verificationUrl}`,
                      verificationToken ? `TOKEN: ${verificationToken}` : null,
                    ]
                      .filter(Boolean)
                      .join('\n');
                    await copyToClipboard(payload);
                    showSuccess(t('auth.register.devLinkCopied'));
                  } catch {
                    showError(t('auth.register.devLinkCopyFailed'));
                  }
                }}
                sx={{ textTransform: 'none' }}
              >
                {t('auth.register.devLinkCopyButton')}
              </Button>
              <Typography variant="caption" display="block" sx={{ mt: 1, wordBreak: 'break-all' }}>
                {verificationUrl}
              </Typography>
              {verificationToken && (
                <Typography variant="caption" display="block" sx={{ mt: 1, wordBreak: 'break-all' }}>
                  {t('auth.register.devLinkToken')} {verificationToken}
                </Typography>
              )}
            </Box>
          )}

          <Typography variant="caption" display="block">
            {t('auth.register.noEmailNotice')}{' '}
            <Button
              onClick={() => {
                setRegistrationComplete(false);
                setRegisteredEmail('');
                setVerificationUrl('');
              }}
              sx={{ textTransform: 'none', p: 0 }}
            >
              {t('auth.register.tryAgain')}
            </Button>
          </Typography>
        </Paper>
      </Box>
    );
  }

  return (
    <Box sx={{ minHeight: '100vh', display: 'flex', alignItems: 'center', justifyContent: 'center', bgcolor: 'background.default', p: 4 }}>
      <Paper sx={{ p: 4, maxWidth: 600, width: '100%' }}>
        <Box sx={{ textAlign: 'center', mb: 3 }}>
          <BusinessIcon sx={{ fontSize: 48, color: 'primary.main', mb: 1 }} />
          <Typography variant="h4" gutterBottom>
            {t('auth.register.title')}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {t('auth.register.subtitle')}
          </Typography>
        </Box>

        <Box component="form" onSubmit={handleSubmit} noValidate>
          <Typography variant="h6" sx={{ mt: 3, mb: 2 }}>
            {t('auth.register.sections.companyInfo')}
          </Typography>

          <TextField
            fullWidth
            label={t('auth.register.fields.companyName')}
            value={formData.company_name}
            onChange={handleChange('company_name')}
            error={!!errors.company_name}
            helperText={errors.company_name?.[0]}
            required
            sx={{ mb: 2 }}
          />

          <TextField
            fullWidth
            label={t('auth.register.fields.workspaceSlug')}
            value={formData.slug}
            onChange={handleChange('slug')}
            error={!!errors.slug || slugAvailable === false}
            helperText={
              slugError ||
              errors.slug?.[0] ||
              t('auth.register.fields.workspaceSlugHelper')
            }
            required
            InputProps={{
              endAdornment: (
                <InputAdornment position="end">
                  {slugChecking && <CircularProgress size={20} />}
                  {!slugChecking && slugAvailable === true && (
                    <CheckCircleIcon color="success" />
                  )}
                  {!slugChecking && slugAvailable === false && (
                    <ErrorIcon color="error" />
                  )}
                </InputAdornment>
              ),
            }}
            sx={{ mb: 2 }}
          />

          <FormControl fullWidth sx={{ mb: 2 }}>
            <InputLabel>{t('auth.register.fields.industryOptional')}</InputLabel>
            <Select
              value={formData.industry}
              onChange={(e) =>
                handleChange('industry')({ target: { value: e.target.value } })
              }
              label={t('auth.register.fields.industryOptional')}
            >
              <MenuItem value="">
                <em>{t('auth.register.fields.selectIndustry')}</em>
              </MenuItem>
              {industries.map((industry) => (
                <MenuItem key={industry} value={industry}>
                  {industry}
                </MenuItem>
              ))}
            </Select>
          </FormControl>

          <FormControl fullWidth sx={{ mb: 2 }}>
            <InputLabel>{t('auth.register.fields.countryOptional')}</InputLabel>
            <Select
              value={formData.country}
              onChange={(e) =>
                handleChange('country')({ target: { value: e.target.value } })
              }
              label={t('auth.register.fields.countryOptional')}
            >
              <MenuItem value="">
                <em>{t('auth.register.fields.selectCountry')}</em>
              </MenuItem>
              {countries.map((country) => (
                <MenuItem key={country.code} value={country.code}>
                  {country.name}
                </MenuItem>
              ))}
            </Select>
          </FormControl>

          <FormControl fullWidth sx={{ mb: 2 }}>
            <InputLabel>{t('auth.register.fields.region')}</InputLabel>
            <Select
              value={formData.region}
              onChange={(e) =>
                handleChange('region')({ target: { value: e.target.value } })
              }
              label={t('auth.register.fields.region')}
            >
              <MenuItem value="EU">{t('auth.register.fields.regionEU')}</MenuItem>
              <MenuItem value="US">{t('auth.register.fields.regionUS')}</MenuItem>
            </Select>
          </FormControl>

          <Typography variant="h6" sx={{ mt: 4, mb: 2 }}>
            {t('auth.register.sections.adminAccount')}
          </Typography>

          <TextField
            fullWidth
            label={t('auth.register.fields.adminName')}
            value={formData.admin_name}
            onChange={handleChange('admin_name')}
            error={!!errors.admin_name}
            helperText={errors.admin_name?.[0]}
            required
            sx={{ mb: 2 }}
          />

          <TextField
            fullWidth
            type="email"
            label={t('auth.register.fields.adminEmail')}
            value={formData.admin_email}
            onChange={handleChange('admin_email')}
            error={!!errors.admin_email}
            helperText={errors.admin_email?.[0]}
            required
            sx={{ mb: 2 }}
          />

          <TextField
            fullWidth
            type="password"
            label={t('auth.register.fields.password')}
            value={formData.admin_password}
            onChange={handleChange('admin_password')}
            error={!!errors.admin_password}
            helperText={errors.admin_password?.[0] || t('auth.register.fields.passwordMinHelper')}
            required
            sx={{ mb: 2 }}
          />

          <TextField
            fullWidth
            type="password"
            label={t('auth.register.fields.passwordConfirm')}
            value={formData.admin_password_confirmation}
            onChange={handleChange('admin_password_confirmation')}
            error={!!errors.admin_password_confirmation}
            helperText={errors.admin_password_confirmation?.[0]}
            required
            sx={{ mb: 3 }}
          />

          {captcha && (
            <>
              {captchaStatus !== 'verified' && captchaStatus !== 'verifying' && (
                <Box
                  sx={{
                    display: 'flex',
                    alignItems: 'center',
                    gap: 1,
                    bgcolor: 'info.light',
                    color: 'info.dark',
                    borderRadius: 2,
                    px: 2,
                    py: 1.5,
                    mb: 2,
                  }}
                  role="status"
                  aria-live="polite"
                >
                  <ShieldOutlinedIcon sx={{ color: 'info.main' }} />
                  <Typography variant="body2" sx={{ fontWeight: 600 }}>
                    {t('auth.register.captchaPrompt')}
                  </Typography>
                </Box>
              )}

              <CaptchaWidget
                challenge={captcha}
                key={captchaWidgetKey}
                onVerifying={handleCaptchaVerifying}
                onToken={handleCaptchaToken}
                onExpire={handleCaptchaExpire}
              />
            </>
          )}

          <Box sx={{ mb: 2 }}>
            <FormControlLabel
              control={
                <Checkbox
                  checked={legalAccepted}
                  onChange={(e) => {
                    setLegalAccepted(e.target.checked);
                    if (errors.legal_accepted) {
                      setErrors((prev) => {
                        const updated = { ...prev };
                        delete updated.legal_accepted;
                        return updated;
                      });
                    }
                  }}
                />
              }
              label={
                <Typography variant="body2" color="text.secondary">
                  {t('auth.register.legal.prefix')}{' '}
                  <Link href="/legal/terms" target="_blank" rel="noopener noreferrer">
                    {t('auth.register.legal.terms')}
                  </Link>
                  ,{' '}
                  <Link href="/legal/privacy" target="_blank" rel="noopener noreferrer">
                    {t('auth.register.legal.privacy')}
                  </Link>
                  , and{' '}
                  <Link href="/legal/acceptable-use" target="_blank" rel="noopener noreferrer">
                    {t('auth.register.legal.acceptableUse')}
                  </Link>
                  .
                </Typography>
              }
            />

            {errors.legal_accepted?.[0] && (
              <Typography variant="caption" color="error" display="block" sx={{ mt: 0.5 }}>
                {errors.legal_accepted[0]}
              </Typography>
            )}
          </Box>

          <Button
            type="submit"
            fullWidth
            variant="contained"
            size="large"
            disabled={loading || !slugAvailable || slugChecking || captchaStatus === 'verifying' || !legalAccepted}
            sx={{ mb: 2 }}
          >
            {loading ? (
              <>
                <CircularProgress size={20} sx={{ mr: 1 }} />
                {t('auth.register.sendingVerification')}
              </>
            ) : (
              t('auth.register.submit')
            )}
          </Button>

          <Box sx={{ textAlign: 'center' }}>
            <Typography variant="body2" color="text.secondary">
              {t('auth.register.haveWorkspace')}{' '}
              <Button
                onClick={() => navigate('/login')}
                sx={{ textTransform: 'none' }}
              >
                {t('auth.register.signIn')}
              </Button>
            </Typography>
          </Box>
        </Box>
      </Paper>
    </Box>
  );
};

export default TenantRegistration;
