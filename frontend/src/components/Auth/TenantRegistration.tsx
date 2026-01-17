import React, { useCallback, useEffect, useState } from 'react';
import {
  Box,
  Button,
  TextField,
  Typography,
  Paper,
  FormControl,
  InputLabel,
  Select,
  MenuItem,
  CircularProgress,
  InputAdornment,
} from '@mui/material';
import CheckCircleIcon from '@mui/icons-material/CheckCircle';
import ErrorIcon from '@mui/icons-material/Error';
import BusinessIcon from '@mui/icons-material/Business';
import ShieldOutlinedIcon from '@mui/icons-material/ShieldOutlined';
import { useNavigate } from 'react-router-dom';
import { useNotification } from '../../contexts/NotificationContext';
import api from '../../services/api';
import { CaptchaWidget, type CaptchaChallenge } from './CaptchaWidget';

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

        showSuccess(data?.message || 'Verification email sent! Please check your inbox.');
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
        showError('Please fix the form errors');
        return;
      }

      if (data?.message) {
        showError(data.message);
        return;
      }

      showError('Signup failed. Please try again.');
    } catch (error: any) {
      console.error('Registration failed:', error);
      showError(error?.message || 'Signup failed. Please try again.');
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
      setSlugError('Slug can only contain lowercase letters, numbers, and hyphens');
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
          setSlugError(res.data.message || 'This slug is not available');
        }
      } catch {
        setSlugAvailable(null);
      } finally {
        setSlugChecking(false);
      }
    }, 500);

    return () => clearTimeout(timer);
  }, [formData.slug]);

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
    if (!formData.company_name) newErrors.company_name = ['Company name is required'];
    if (!formData.slug) newErrors.slug = ['Slug is required'];
    if (!slugAvailable) newErrors.slug = ['Please choose an available slug'];
    if (!formData.admin_name) newErrors.admin_name = ['Admin name is required'];
    if (!formData.admin_email) newErrors.admin_email = ['Admin email is required'];
    if (!formData.admin_password) newErrors.admin_password = ['Password is required'];
    if (formData.admin_password.length < 8)
      newErrors.admin_password = ['Password must be at least 8 characters'];
    if (formData.admin_password !== formData.admin_password_confirmation)
      newErrors.admin_password_confirmation = ['Passwords do not match'];

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      showError('Please fix the form errors');
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
            Check Your Email
          </Typography>

          <Typography variant="body1" sx={{ mb: 1 }}>
            We've sent a verification link to:
          </Typography>

          <Typography variant="h6" sx={{ mb: 3 }}>
            {registeredEmail}
          </Typography>

          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Please click the link in the email to activate your workspace.
          </Typography>

          <Typography variant="caption" display="block" sx={{ mb: 3 }}>
            The verification link will expire in {expiresInHours} hours.
          </Typography>

          {verificationUrl && (
            <Box sx={{ mb: 3, p: 2, bgcolor: 'warning.light', borderRadius: 1 }}>
              <Typography variant="caption" display="block" fontWeight="bold" sx={{ mb: 1 }}>
                🔧 DEV MODE - Direct Verification Link:
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
                    showSuccess('Verification URL + token copied to clipboard');
                  } catch {
                    showError('Failed to copy to clipboard');
                  }
                }}
                sx={{ textTransform: 'none' }}
              >
                Copy verification URL + token
              </Button>
              <Typography variant="caption" display="block" sx={{ mt: 1, wordBreak: 'break-all' }}>
                {verificationUrl}
              </Typography>
              {verificationToken && (
                <Typography variant="caption" display="block" sx={{ mt: 1, wordBreak: 'break-all' }}>
                  Token: {verificationToken}
                </Typography>
              )}
            </Box>
          )}

          <Typography variant="caption" display="block">
            Didn't receive the email? Check your spam folder or{' '}
            <Button
              onClick={() => {
                setRegistrationComplete(false);
                setRegisteredEmail('');
                setVerificationUrl('');
              }}
              sx={{ textTransform: 'none', p: 0 }}
            >
              try again
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
            Create Your Workspace
          </Typography>
          <Typography variant="body2" color="text.secondary">
            Start your 14-day free trial • No credit card required
          </Typography>
        </Box>

        <Box component="form" onSubmit={handleSubmit} noValidate>
          <Typography variant="h6" sx={{ mt: 3, mb: 2 }}>
            Company Information
          </Typography>

          <TextField
            fullWidth
            label="Company Name"
            value={formData.company_name}
            onChange={handleChange('company_name')}
            error={!!errors.company_name}
            helperText={errors.company_name?.[0]}
            required
            sx={{ mb: 2 }}
          />

          <TextField
            fullWidth
            label="Workspace Slug"
            value={formData.slug}
            onChange={handleChange('slug')}
            error={!!errors.slug || slugAvailable === false}
            helperText={
              slugError ||
              errors.slug?.[0] ||
              'This will be your unique workspace identifier (e.g., acme.vendaslive.com)'
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
            <InputLabel>Industry (Optional)</InputLabel>
            <Select
              value={formData.industry}
              onChange={(e) =>
                handleChange('industry')({ target: { value: e.target.value } })
              }
              label="Industry (Optional)"
            >
              <MenuItem value="">
                <em>Select industry</em>
              </MenuItem>
              {industries.map((industry) => (
                <MenuItem key={industry} value={industry}>
                  {industry}
                </MenuItem>
              ))}
            </Select>
          </FormControl>

          <FormControl fullWidth sx={{ mb: 2 }}>
            <InputLabel>Country (Optional)</InputLabel>
            <Select
              value={formData.country}
              onChange={(e) =>
                handleChange('country')({ target: { value: e.target.value } })
              }
              label="Country (Optional)"
            >
              <MenuItem value="">
                <em>Select country</em>
              </MenuItem>
              {countries.map((country) => (
                <MenuItem key={country.code} value={country.code}>
                  {country.name}
                </MenuItem>
              ))}
            </Select>
          </FormControl>

          <FormControl fullWidth sx={{ mb: 2 }}>
            <InputLabel>Region</InputLabel>
            <Select
              value={formData.region}
              onChange={(e) =>
                handleChange('region')({ target: { value: e.target.value } })
              }
              label="Region"
            >
              <MenuItem value="EU">Europe (EU)</MenuItem>
              <MenuItem value="US">United States (US)</MenuItem>
            </Select>
          </FormControl>

          <Typography variant="h6" sx={{ mt: 4, mb: 2 }}>
            Admin Account
          </Typography>

          <TextField
            fullWidth
            label="Admin Name"
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
            label="Admin Email"
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
            label="Password"
            value={formData.admin_password}
            onChange={handleChange('admin_password')}
            error={!!errors.admin_password}
            helperText={errors.admin_password?.[0] || 'Minimum 8 characters'}
            required
            sx={{ mb: 2 }}
          />

          <TextField
            fullWidth
            type="password"
            label="Confirm Password"
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
                    Just one more step: confirm you’re not a robot.
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

          <Button
            type="submit"
            fullWidth
            variant="contained"
            size="large"
            disabled={loading || !slugAvailable || slugChecking || captchaStatus === 'verifying'}
            sx={{ mb: 2 }}
          >
            {loading ? (
              <>
                <CircularProgress size={20} sx={{ mr: 1 }} />
                Sending verification email...
              </>
            ) : (
              'Create Workspace'
            )}
          </Button>

          <Box sx={{ textAlign: 'center' }}>
            <Typography variant="body2" color="text.secondary">
              Already have a workspace?{' '}
              <Button
                onClick={() => navigate('/login')}
                sx={{ textTransform: 'none' }}
              >
                Sign In
              </Button>
            </Typography>
          </Box>
        </Box>
      </Paper>
    </Box>
  );
};

export default TenantRegistration;
