import React, { useState, useEffect } from 'react';
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
import { useNavigate } from 'react-router-dom';
import { useNotification } from '../../contexts/NotificationContext';
import api from '../../services/api';

interface RegistrationFormData {
  company_name: string;
  slug: string;
  admin_name: string;
  admin_email: string;
  admin_password: string;
  admin_password_confirmation: string;
  industry?: string;
  country?: string;
  timezone?: string;
}

const getTenancyBaseDomain = (): string => {
  // Allow override via environment variables
  const envBase =
    (import.meta as any).env?.VITE_TENANCY_BASE_DOMAIN ||
    (import.meta as any).env?.VITE_BASE_DOMAIN;

  if (envBase && typeof envBase === 'string') {
    return envBase;
  }

  const host = window.location.hostname;

  // In production we expect something like app.vendaslive.com
  // We want to extract the "vendaslive.com" part so we can build
  // tenant subdomains like "demo.vendaslive.com"
  if (host.includes('.')) {
    const parts = host.split('.');
    if (parts.length >= 2) {
      return parts.slice(-2).join('.');
    }
  }

  // Sensible default for local development
  return 'vendaslive.localhost';
};

const buildTenantLoginUrl = (slug: string, adminEmail: string): string => {
  const baseDomain = getTenancyBaseDomain();
  const protocol = window.location.protocol || 'http:';
  const port = window.location.port ? `:${window.location.port}` : '';
  const host = `${slug}.${baseDomain}`;
  const emailParam = encodeURIComponent(adminEmail);

  return `${protocol}//${host}${port}/login?email=${emailParam}`;
};

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
    industry: '',
    country: '',
    timezone: Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC',
  });

  const [loading, setLoading] = useState(false);
  const [slugChecking, setSlugChecking] = useState(false);
  const [slugAvailable, setSlugAvailable] = useState<boolean | null>(null);
  const [slugError, setSlugError] = useState<string>('');
  const [errors, setErrors] = useState<Record<string, string[]>>({});

  // Auto-generate slug from company name
  useEffect(() => {
    if (formData.company_name && !formData.slug) {
      const generatedSlug = formData.company_name
        .toLowerCase()
        .replace(/[^a-z0-9]+/g, '-')
        .replace(/^-+|-+$/g, '')
        .substring(0, 50);
      
      setFormData(prev => ({ ...prev, slug: generatedSlug }));
    }
  }, [formData.company_name, formData.slug]);

  // Check slug availability with debounce
  useEffect(() => {
    if (!formData.slug || formData.slug.length < 3) {
      setSlugAvailable(null);
      setSlugError('');
      return;
    }

    // Validate slug format
    if (!/^[a-z0-9-]+$/.test(formData.slug)) {
      setSlugAvailable(false);
      setSlugError('Slug can only contain lowercase letters, numbers, and hyphens');
      return;
    }

    const timer = setTimeout(async () => {
      setSlugChecking(true);
      setSlugError('');
      
      try {
        const response = await api.get(`/tenants/check-slug`, {
          params: { slug: formData.slug }
        });
        
        setSlugAvailable(response.data.available);
        if (!response.data.available) {
          setSlugError(response.data.message || 'This slug is not available');
        }
      } catch (error) {
        console.error('Slug check failed:', error);
        setSlugAvailable(null);
      } finally {
        setSlugChecking(false);
      }
    }, 500);

    return () => clearTimeout(timer);
  }, [formData.slug]);

  const handleChange = (field: keyof RegistrationFormData) => (
    event: React.ChangeEvent<HTMLInputElement | HTMLTextAreaElement> | { target: { value: string } }
  ) => {
    setFormData(prev => ({
      ...prev,
      [field]: event.target.value
    }));
    
    // Clear field-specific error
    if (errors[field]) {
      setErrors(prev => {
        const newErrors = { ...prev };
        delete newErrors[field];
        return newErrors;
      });
    }
  };

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setErrors({});

    // Frontend validation
    const newErrors: Record<string, string[]> = {};
    
    if (!formData.company_name) newErrors.company_name = ['Company name is required'];
    if (!formData.slug) newErrors.slug = ['Slug is required'];
    if (!slugAvailable) newErrors.slug = ['Please choose an available slug'];
    if (!formData.admin_name) newErrors.admin_name = ['Admin name is required'];
    if (!formData.admin_email) newErrors.admin_email = ['Admin email is required'];
    if (!formData.admin_password) newErrors.admin_password = ['Password is required'];
    if (formData.admin_password.length < 8) newErrors.admin_password = ['Password must be at least 8 characters'];
    if (formData.admin_password !== formData.admin_password_confirmation) {
      newErrors.admin_password_confirmation = ['Passwords do not match'];
    }

    if (Object.keys(newErrors).length > 0) {
      setErrors(newErrors);
      showError('Please fix the form errors');
      return;
    }

    setLoading(true);

    try {
      const response = await api.post('/api/tenants/register', {
        company_name: formData.company_name,
        slug: formData.slug,
        admin_name: formData.admin_name,
        admin_email: formData.admin_email,
        admin_password: formData.admin_password,
        admin_password_confirmation: formData.admin_password_confirmation,
        industry: formData.industry || undefined,
        country: formData.country || undefined,
        timezone: formData.timezone || 'UTC',
      });

      showSuccess(`Welcome! Your workspace "${formData.company_name}" has been created successfully!`);
      
      // Store tenant slug and token
      localStorage.setItem('tenant_slug', response.data.tenant);
      if (response.data.admin?.token) {
        localStorage.setItem('auth_token', response.data.admin.token);
      }

      // Redirect to tenant-specific login on its subdomain
      const tenantSlug = response.data.tenant || formData.slug;
      const loginUrl = buildTenantLoginUrl(tenantSlug, formData.admin_email);

      setTimeout(() => {
        window.location.href = loginUrl;
      }, 1500);

    } catch (error: any) {
      console.error('Registration failed:', error);
      
      if (error.response?.data?.errors) {
        setErrors(error.response.data.errors);
        showError('Please fix the form errors');
      } else {
        showError(error.response?.data?.message || 'Registration failed. Please try again.');
      }
    } finally {
      setLoading(false);
    }
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
          maxWidth: 600,
          width: '100%',
          mx: 2,
        }}
      >
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
          {/* Company Information */}
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
            error={!!errors.slug || (slugAvailable === false)}
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
              onChange={(e) => handleChange('industry')(e as any)}
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
              onChange={(e) => handleChange('country')(e as any)}
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

          {/* Admin Account */}
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

          {/* Submit */}
          <Button
            type="submit"
            fullWidth
            variant="contained"
            size="large"
            disabled={loading || !slugAvailable || slugChecking}
            sx={{ mb: 2 }}
          >
            {loading ? (
              <>
                <CircularProgress size={20} sx={{ mr: 1 }} />
                Creating workspace...
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
