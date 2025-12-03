import React, { useState, useEffect } from 'react';
import {
  Card,
  CardContent,
  Typography,
  Box,
  Button,
  Dialog,
  DialogTitle,
  DialogContent,
  CircularProgress,
  Divider,
} from '@mui/material';
import CreditCardIcon from '@mui/icons-material/CreditCard';
import EditIcon from '@mui/icons-material/Edit';
import CloseIcon from '@mui/icons-material/Close';
import { Elements, PaymentElement, useStripe, useElements } from '@stripe/react-stripe-js';
import { loadStripe } from '@stripe/stripe-js';
import type { Stripe } from '@stripe/stripe-js';
import { useNotification } from '../../contexts/NotificationContext';
import api from '../../services/api';

interface PaymentMethod {
  id: string;
  brand: string;
  last4: string;
  exp_month: number;
  exp_year: number;
}

interface GatewayConfig {
  gateway: 'stripe' | 'fake';
  stripe_publishable_key?: string;
}

interface SetupIntentResponse {
  client_secret: string;
  setup_intent_id: string;
}

// Stripe Setup Form Component
const StripeSetupForm: React.FC<{
  clientSecret: string;
  onSuccess: () => void;
  onError: (error: string) => void;
  onCancel: () => void;
}> = ({ onSuccess, onError, onCancel }) => {
  const stripe = useStripe();
  const elements = useElements();
  const [processing, setProcessing] = useState(false);

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();

    if (!stripe || !elements) {
      onError('Stripe is not loaded yet');
      return;
    }

    setProcessing(true);

    try {
      const { error } = await stripe.confirmSetup({
        elements,
        confirmParams: {
          return_url: window.location.href, // Not used for manual confirmation
        },
        redirect: 'if_required', // Don't redirect, handle manually
      });

      if (error) {
        onError(error.message || 'Failed to save payment method');
      } else {
        onSuccess();
      }
    } catch (err: any) {
      onError(err.message || 'Failed to save payment method');
    } finally {
      setProcessing(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <Box sx={{ mb: 3 }}>
        <PaymentElement />
      </Box>

      <Box sx={{ display: 'flex', gap: 2, justifyContent: 'flex-end' }}>
        <Button onClick={onCancel} disabled={processing}>
          Cancel
        </Button>
        <Button
          type="submit"
          variant="contained"
          disabled={!stripe || processing}
          sx={{
            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            textTransform: 'none',
            fontWeight: 600,
            px: 3,
          }}
        >
          {processing ? (
            <>
              <CircularProgress size={20} color="inherit" sx={{ mr: 1 }} />
              Saving...
            </>
          ) : (
            'Save Payment Method'
          )}
        </Button>
      </Box>
    </form>
  );
};

// Fake Gateway Setup Form
const FakeSetupForm: React.FC<{
  onSuccess: () => void;
  onError: (error: string) => void;
  onCancel: () => void;
}> = ({ onSuccess, onError, onCancel }) => {
  const [cardNumber, setCardNumber] = useState('');
  const [processing, setProcessing] = useState(false);

  const handleCardNumberChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const value = e.target.value.replace(/\D/g, '');
    const formatted = value.match(/.{1,4}/g)?.join(' ') || value;
    setCardNumber(formatted);
  };

  const handleSubmit = async () => {
    if (cardNumber.length < 19) {
      onError('Please enter a valid card number');
      return;
    }

    setProcessing(true);

    try {
      await api.post('/api/billing/payment-methods/add', {
        card_number: cardNumber.replace(/\s/g, ''),
      });

      onSuccess();
    } catch (err: any) {
      onError(err.response?.data?.message || 'Failed to save payment method');
    } finally {
      setProcessing(false);
    }
  };

  return (
    <Box>
      <Box sx={{ mb: 3 }}>
        <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 2 }}>
          Card Number
        </Typography>
        <input
          type="text"
          value={cardNumber}
          onChange={handleCardNumberChange}
          placeholder="4242 4242 4242 4242"
          maxLength={19}
          disabled={processing}
          style={{
            width: '100%',
            padding: '12px 16px',
            fontSize: '16px',
            border: '1px solid #ddd',
            borderRadius: '8px',
            outline: 'none',
          }}
        />
        <Typography variant="caption" color="text.secondary" sx={{ mt: 1, display: 'block' }}>
          🧪 Test Mode: Use 4242 4242 4242 4242
        </Typography>
      </Box>

      <Box sx={{ display: 'flex', gap: 2, justifyContent: 'flex-end' }}>
        <Button onClick={onCancel} disabled={processing}>
          Cancel
        </Button>
        <Button
          onClick={handleSubmit}
          variant="contained"
          disabled={processing || !cardNumber}
          sx={{
            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            textTransform: 'none',
            fontWeight: 600,
            px: 3,
          }}
        >
          {processing ? (
            <>
              <CircularProgress size={20} color="inherit" sx={{ mr: 1 }} />
              Saving...
            </>
          ) : (
            'Save Payment Method'
          )}
        </Button>
      </Box>
    </Box>
  );
};

// Main Component
const PaymentMethodCard: React.FC<{ onUpdate?: () => void }> = ({ onUpdate }) => {
  const { showSuccess, showError } = useNotification();
  const [paymentMethod, setPaymentMethod] = useState<PaymentMethod | null>(null);
  const [loading, setLoading] = useState(true);
  const [dialogOpen, setDialogOpen] = useState(false);
  const [gatewayConfig, setGatewayConfig] = useState<GatewayConfig | null>(null);
  const [setupData, setSetupData] = useState<SetupIntentResponse | null>(null);
  const [stripePromise, setStripePromise] = useState<Promise<Stripe | null> | null>(null);

  // Fetch payment method on mount
  useEffect(() => {
    fetchPaymentMethod();
  }, []);

  const fetchPaymentMethod = async () => {
    setLoading(true);
    try {
      const response = await api.get('/api/billing/payment-methods');
      if (response.data.payment_method) {
        setPaymentMethod(response.data.payment_method);
      }
    } catch (err: any) {
      console.error('Failed to fetch payment method:', err);
    } finally {
      setLoading(false);
    }
  };

  const fetchGatewayConfig = async () => {
    try {
      const response = await api.get('/api/billing/gateway');
      const config = response.data;
      setGatewayConfig(config);

      // Load Stripe if needed
      if (config.gateway === 'stripe' && config.stripe_publishable_key) {
        const stripe = loadStripe(config.stripe_publishable_key);
        setStripePromise(stripe);
      }
    } catch (err: any) {
      showError('Failed to load gateway configuration');
      console.error('Gateway config error:', err);
    }
  };

  const initializeSetup = async () => {
    if (!gatewayConfig) return;

    try {
      if (gatewayConfig.gateway === 'stripe') {
        // Create Stripe SetupIntent
        const response = await api.post('/api/billing/payment-methods/setup-intent');
        setSetupData(response.data);
      }
      // For fake gateway, no setup needed (just open form)
    } catch (err: any) {
      showError(err.response?.data?.message || 'Failed to initialize payment setup');
      console.error('Setup initialization error:', err);
      setDialogOpen(false);
    }
  };

  const handleOpenDialog = async () => {
    setDialogOpen(true);
    await fetchGatewayConfig();
  };

  // Initialize setup when gateway config is loaded
  useEffect(() => {
    if (dialogOpen && gatewayConfig) {
      initializeSetup();
    }
  }, [dialogOpen, gatewayConfig]);

  const handleSuccess = () => {
    showSuccess('Payment method saved successfully');
    setDialogOpen(false);
    setSetupData(null);
    setGatewayConfig(null);
    setStripePromise(null);
    fetchPaymentMethod();
    if (onUpdate) onUpdate();
  };

  const handleError = (error: string) => {
    showError(error);
  };

  const handleClose = () => {
    setDialogOpen(false);
    setSetupData(null);
    setGatewayConfig(null);
    setStripePromise(null);
  };

  if (loading) {
    return (
      <Card sx={{ borderRadius: 3, boxShadow: '0 2px 8px rgba(0,0,0,0.08)' }}>
        <CardContent>
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 3 }}>
            <CircularProgress size={24} />
          </Box>
        </CardContent>
      </Card>
    );
  }

  return (
    <>
      <Card sx={{ borderRadius: 3, boxShadow: '0 2px 8px rgba(0,0,0,0.08)' }}>
        <CardContent>
          <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
            <Typography variant="h6" sx={{ fontWeight: 600, color: 'text.primary' }}>
              Payment Method
            </Typography>
            <Button
              startIcon={paymentMethod ? <EditIcon /> : undefined}
              onClick={handleOpenDialog}
              sx={{
                textTransform: 'none',
                fontWeight: 500,
                color: '#667eea',
              }}
            >
              {paymentMethod ? 'Update Card' : 'Add Card'}
            </Button>
          </Box>

          {paymentMethod ? (
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 2 }}>
              <Box
                sx={{
                  width: 48,
                  height: 32,
                  borderRadius: 1,
                  bgcolor: '#667eea',
                  display: 'flex',
                  alignItems: 'center',
                  justifyContent: 'center',
                }}
              >
                <CreditCardIcon sx={{ color: 'white', fontSize: 24 }} />
              </Box>
              <Box>
                <Typography variant="body1" sx={{ fontWeight: 500 }}>
                  {paymentMethod.brand.charAt(0).toUpperCase() + paymentMethod.brand.slice(1)} ending in {paymentMethod.last4}
                </Typography>
                <Typography variant="body2" color="text.secondary">
                  Expires {paymentMethod.exp_month}/{paymentMethod.exp_year}
                </Typography>
              </Box>
            </Box>
          ) : (
            <Box sx={{ py: 2, textAlign: 'center' }}>
              <Typography variant="body2" color="text.secondary">
                No payment method on file
              </Typography>
              <Typography variant="caption" color="text.secondary">
                Add a card to enable automatic billing
              </Typography>
            </Box>
          )}
        </CardContent>
      </Card>

      {/* Update/Add Card Dialog */}
      <Dialog
        open={dialogOpen}
        onClose={handleClose}
        maxWidth="sm"
        fullWidth
        PaperProps={{
          sx: {
            borderRadius: 3,
            boxShadow: '0 8px 32px rgba(0,0,0,0.12)',
          },
        }}
      >
        <DialogTitle
          sx={{
            display: 'flex',
            justifyContent: 'space-between',
            alignItems: 'center',
            pb: 2,
          }}
        >
          <Box>
            <Typography variant="h6" sx={{ fontWeight: 600, color: '#667eea' }}>
              {paymentMethod ? 'Update Payment Method' : 'Add Payment Method'}
            </Typography>
            <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
              {paymentMethod ? 'Replace your existing card' : 'Add a card for automatic billing'}
            </Typography>
          </Box>
          <CloseIcon
            onClick={handleClose}
            sx={{
              cursor: 'pointer',
              color: 'text.secondary',
              '&:hover': { color: 'text.primary' },
            }}
          />
        </DialogTitle>

        <Divider />

        <DialogContent sx={{ pt: 3 }}>
          {!gatewayConfig && (
            <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}>
              <CircularProgress />
            </Box>
          )}

          {gatewayConfig?.gateway === 'stripe' && setupData?.client_secret && stripePromise && (
            <Elements stripe={stripePromise} options={{ clientSecret: setupData.client_secret }}>
              <StripeSetupForm
                clientSecret={setupData.client_secret}
                onSuccess={handleSuccess}
                onError={handleError}
                onCancel={handleClose}
              />
            </Elements>
          )}

          {gatewayConfig?.gateway === 'fake' && (
            <FakeSetupForm onSuccess={handleSuccess} onError={handleError} onCancel={handleClose} />
          )}
        </DialogContent>
      </Dialog>
    </>
  );
};

export default PaymentMethodCard;
