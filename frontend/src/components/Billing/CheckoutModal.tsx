import React, { useEffect, useState } from 'react';
import {
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  Button,
  TextField,
  Box,
  Typography,
  Divider,
  CircularProgress,
  Alert,
} from '@mui/material';
import { CreditCard as CreditCardIcon, Close as CloseIcon } from '@mui/icons-material';
import {
  PaymentElement,
  Elements,
  useStripe,
  useElements,
} from '@stripe/react-stripe-js';
import { loadStripe } from '@stripe/stripe-js';
import type { Stripe } from '@stripe/stripe-js';
import { useBilling } from '../../contexts/BillingContext';
import { useNotification } from '../../contexts/NotificationContext';
import api from '../../services/api';
import { useAuth } from '../Auth/AuthContext';
import { formatTenantMoney } from '../../utils/tenantFormatting';

/**
 * Gateway Configuration Response
 */
interface GatewayConfig {
  gateway: 'stripe' | 'fake';
  stripe_public_key?: string;
  currency: string;
}

/**
 * Checkout Start Response
 */
interface CheckoutStartResponse {
  payment_id: number;
  client_secret?: string;
  session_id?: string;
  gateway: 'stripe' | 'fake';
  amount: number;
  currency: string;
}

/**
 * Stripe Checkout Form Component
 * Renders inside Elements provider with PaymentElement
 */
const StripeCheckoutForm: React.FC<{
  clientSecret: string;
  paymentId: number;
  amount: number;
  currency: string;
  onSuccess: () => void;
  onError: (message: string) => void;
  onCancel: () => void;
}> = ({ paymentId, amount, onSuccess, onError, onCancel }) => {
  const stripe = useStripe();
  const elements = useElements();
  const [processing, setProcessing] = useState(false);
  const { tenantContext } = useAuth();

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();

    if (!stripe || !elements) {
      onError('Stripe is not loaded yet. Please try again.');
      return;
    }

    setProcessing(true);

    try {
      // Confirm payment with Stripe
      const { error: confirmError, paymentIntent } = await stripe.confirmPayment({
        elements,
        confirmParams: {
          return_url: window.location.origin + '/billing/payment-success',
        },
        redirect: 'if_required',
      });

      if (confirmError) {
        onError(confirmError.message || 'Payment confirmation failed');
        setProcessing(false);
        return;
      }

      if (paymentIntent && paymentIntent.status === 'succeeded') {
        // Confirm with backend
        try {
          const response = await api.post<{ success: boolean; message?: string }>(
            '/api/billing/checkout/confirm',
            {
              payment_id: paymentId,
              payment_method_id: paymentIntent.payment_method,
            }
          );

          if (response.data.success) {
            onSuccess();
          } else {
            onError(response.data.message || 'Payment confirmation failed on server');
          }
        } catch (backendError: any) {
          console.error('Backend confirmation error:', backendError);
          onError(backendError.response?.data?.message || 'Failed to confirm payment with server');
        }
      } else {
        onError('Payment status: ' + (paymentIntent?.status || 'unknown'));
      }
    } catch (err: any) {
      console.error('Payment error:', err);
      onError(err.message || 'An unexpected error occurred');
    } finally {
      setProcessing(false);
    }
  };

  return (
    <form onSubmit={handleSubmit}>
      <Box sx={{ mb: 3 }}>
        <Typography variant="h6" gutterBottom sx={{ fontWeight: 600, color: '#667eea' }}>
          Amount to charge: {formatTenantMoney(amount, tenantContext)}
        </Typography>
        <Divider sx={{ my: 2 }} />
        <PaymentElement />
      </Box>

      <DialogActions>
        <Button onClick={onCancel} disabled={processing} sx={{ textTransform: 'none' }}>
          Cancel
        </Button>
        <Button
          type="submit"
          variant="contained"
          disabled={!stripe || processing}
          startIcon={processing && <CircularProgress size={20} />}
          sx={{
            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            textTransform: 'none',
            fontWeight: 600,
            px: 3,
            boxShadow: '0 4px 12px rgba(102, 126, 234, 0.3)',
            '&:hover': {
              boxShadow: '0 6px 16px rgba(102, 126, 234, 0.4)',
            },
          }}
        >
          {processing ? 'Processing...' : `Pay ${formatTenantMoney(amount, tenantContext)}`}
        </Button>
      </DialogActions>
    </form>
  );
};

/**
 * Fake Gateway Checkout Form
 * Simple card number input for development
 */
const FakeCheckoutForm: React.FC<{
  paymentId: number;
  amount: number;
  onSuccess: () => void;
  onError: (message: string) => void;
  onCancel: () => void;
}> = ({ paymentId, amount, onSuccess, onError, onCancel }) => {
  const [cardNumber, setCardNumber] = useState('4242424242424242');
  const [processing, setProcessing] = useState(false);
  const { tenantContext } = useAuth();

  const handleSubmit = async (event: React.FormEvent) => {
    event.preventDefault();
    setProcessing(true);

    try {
      const response = await api.post<{ success: boolean; message?: string }>(
        '/api/billing/checkout/confirm',
        {
          payment_id: paymentId,
          card_number: cardNumber,
        }
      );

      if (response.data.success) {
        onSuccess();
      } else {
        onError(response.data.message || 'Payment failed');
      }
    } catch (err: any) {
      console.error('Fake payment error:', err);
      onError(err.response?.data?.message || 'Payment failed');
    } finally {
      setProcessing(false);
    }
  };

  // Format card number with spaces
  const formatCardNumber = (value: string): string => {
    const cleaned = value.replace(/\s/g, '');
    const match = cleaned.match(/.{1,4}/g);
    return match ? match.join(' ') : cleaned;
  };

  const handleCardNumberChange = (e: React.ChangeEvent<HTMLInputElement>) => {
    const formatted = formatCardNumber(e.target.value);
    setCardNumber(formatted);
  };

  return (
    <form onSubmit={handleSubmit}>
      <Box sx={{ mb: 3 }}>
        <Typography variant="caption" color="text.secondary" gutterBottom display="block">
          Development Mode - Fake Payment Gateway
        </Typography>
        <Divider sx={{ my: 2 }} />
        <Alert severity="info" sx={{ mb: 2 }}>
          This is a test payment. Use any 16-digit number.
        </Alert>
        <TextField
          fullWidth
          label="Card Number"
          placeholder="4242 4242 4242 4242"
          value={cardNumber}
          onChange={handleCardNumberChange}
          disabled={processing}
          InputProps={{
            startAdornment: (
              <CreditCardIcon sx={{ color: 'text.secondary', mr: 1 }} />
            ),
          }}
          inputProps={{ maxLength: 19 }}
          sx={{
            '& .MuiOutlinedInput-root': {
              borderRadius: 2,
            },
          }}
        />
      </Box>

      <DialogActions>
        <Button onClick={onCancel} disabled={processing} sx={{ textTransform: 'none' }}>
          Cancel
        </Button>
        <Button
          type="submit"
          variant="contained"
          disabled={processing}
          startIcon={processing && <CircularProgress size={20} />}
          sx={{
            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            textTransform: 'none',
            fontWeight: 600,
            px: 3,
            boxShadow: '0 4px 12px rgba(102, 126, 234, 0.3)',
            '&:hover': {
              boxShadow: '0 6px 16px rgba(102, 126, 234, 0.4)',
            },
          }}
        >
          {processing ? 'Processing...' : `Pay ${formatTenantMoney(amount, tenantContext)}`}
        </Button>
      </DialogActions>
    </form>
  );
};

/**
 * CheckoutModal - Stripe Elements + Fake Gateway Support
 * 
 * Features:
 * - Auto-detects gateway (Stripe or Fake) via /api/billing/gateway
 * - Initializes checkout session via /api/billing/checkout/start
 * - Renders appropriate payment form based on gateway
 * - Confirms payment via /api/billing/checkout/confirm
 * 
 * Flow:
 * 1. Modal opens → fetch gateway config
 * 2. Initialize Stripe (if needed) → start checkout
 * 3. User completes payment → confirm with backend
 * 4. Success → refresh summary → close modal
 */
const CheckoutModal: React.FC = () => {
  const {
    checkoutState,
    billingSummary,
    closeCheckoutModal,
    refreshSummary,
  } = useBilling();
  const { showSuccess, showError } = useNotification();
  const { tenantContext } = useAuth();

  const [gatewayConfig, setGatewayConfig] = useState<GatewayConfig | null>(null);
  const [checkoutData, setCheckoutData] = useState<CheckoutStartResponse | null>(null);
  const [stripePromise, setStripePromise] = useState<Promise<Stripe | null> | null>(null);
  const [loading, setLoading] = useState(false);

  // Fetch gateway configuration when modal opens
  useEffect(() => {
    if (checkoutState.open && !gatewayConfig) {
      fetchGatewayConfig();
    }
  }, [checkoutState.open]);

  // Initialize checkout when modal opens with valid state
  useEffect(() => {
    if (
      checkoutState.open &&
      gatewayConfig &&
      !checkoutData &&
      (checkoutState.plan || checkoutState.addon || checkoutState.userLimit)
    ) {
      initializeCheckout();
    }
  }, [checkoutState.open, gatewayConfig, checkoutState.plan, checkoutState.addon, checkoutState.userLimit]);

  const fetchGatewayConfig = async () => {
    try {
      const response = await api.get<GatewayConfig>('/api/billing/gateway');
      setGatewayConfig(response.data);

      // Initialize Stripe if using Stripe gateway
      if (response.data.gateway === 'stripe' && response.data.stripe_public_key) {
        setStripePromise(loadStripe(response.data.stripe_public_key));
      }
    } catch (err: any) {
      console.error('Failed to fetch gateway config:', err);
      showError('Failed to load payment gateway configuration');
      closeCheckoutModal();
    }
  };

  const initializeCheckout = async () => {
    setLoading(true);

    try {
      const payload: any = {
        mode: checkoutState.mode,
      };

      if (checkoutState.plan) {
        payload.plan = checkoutState.plan;
      }

      if (checkoutState.addon) {
        payload.addon = checkoutState.addon;
      }

      // plan upgrades — always preserve purchased licenses
      if (checkoutState.mode === 'plan') {
        const currentPlan = billingSummary?.plan;

        if (currentPlan === 'starter') {
          // Starter → paid always carries purchased license count (starter always has 2)
          payload.user_limit = billingSummary?.user_limit ?? 2;
        } else {
          // Paid and trial → use purchased license count exactly
          payload.user_limit = billingSummary?.user_limit ?? 1;
        }
      }

      // License increments → use the resolved new limit
      if (checkoutState.mode === 'licenses' && checkoutState.userLimit) {
        payload.user_limit = checkoutState.userLimit; // exact new purchased value
      }

      // Add-on activation does not change user_limit

      const response = await api.post<CheckoutStartResponse>(
        '/api/billing/checkout/start',
        payload
      );

      setCheckoutData(response.data);
    } catch (err: any) {
      console.error('Failed to start checkout:', err);
      showError(err.response?.data?.message || 'Failed to initialize checkout');
      closeCheckoutModal();
    } finally {
      setLoading(false);
    }
  };

  const handleSuccess = async () => {
    showSuccess('Payment successful! Your plan has been updated.');
    // Refresh summary happens inside handleClose to prevent double refresh
    handleClose();
  };

  const handleError = (message: string) => {
    showError(message);
  };

  const handleClose = async () => {
    setCheckoutData(null);
    setGatewayConfig(null);
    setStripePromise(null);

    closeCheckoutModal();

    try {
        await refreshSummary();
    } catch (e) {
        console.error('Failed to refresh billing summary:', e);
    }
  };

  // Get checkout operation details following SaaS billing logic
  const getCheckoutDetails = () => {
    if (!billingSummary) return null;

    // Case A: Plan Upgrade/Downgrade
    if (checkoutState.mode === 'plan' && checkoutState.plan) {
      const newPlanName = checkoutState.plan.charAt(0).toUpperCase() + checkoutState.plan.slice(1);
      const currentPlanName = billingSummary.plan.charAt(0).toUpperCase() + billingSummary.plan.slice(1);
      const userCount = billingSummary.user_limit;
      
      // Determine if upgrade or downgrade
      const planPrices: Record<string, number> = { starter: 7.92, team: 39.60, enterprise: 59.40 };
      const currentPrice = planPrices[billingSummary.plan] || 0;
      const newPrice = planPrices[checkoutState.plan] || 0;
      const isDowngrade = newPrice < currentPrice;
      const isFromFreePlan = billingSummary.plan === 'starter';
      
      return {
        title: isDowngrade ? `Downgrade to ${newPlanName} Plan` : `Upgrade to ${newPlanName} Plan`,
        description: isDowngrade 
          ? `Scheduled downgrade from ${currentPlanName} to ${newPlanName}`
          : `Upgrading from ${currentPlanName} to ${newPlanName}`,
        operation: isDowngrade ? 'downgrade' : 'upgrade',
        data: {
          fromPlan: currentPlanName,
          toPlan: newPlanName,
          fromPrice: currentPrice,
          toPrice: newPrice,
          priceDifference: newPrice - currentPrice,
          userCount,
          isFromFreePlan,
        },
      };
    }

    // Case B: Add Add-on
    if (checkoutState.mode === 'addon' && checkoutState.addon) {
      const addonName = checkoutState.addon === 'planning' ? 'Planning Module' : 'AI Assistant';
      const basePrice = billingSummary.base_subtotal;
      const userCount = billingSummary.user_limit;
      const currentPlan = billingSummary.plan.charAt(0).toUpperCase() + billingSummary.plan.slice(1);
      
      // Get existing addons (already paid) - only those with price > 0 and NOT being added now
      const existingAddons: Array<{ name: string; price: number; percentage: number }> = [];
      Object.entries(billingSummary.addons || {}).forEach(([key, price]) => {
        // Only include addons that:
        // 1. Have a price > 0 (are currently active)
        // 2. Are NOT the one being added right now
        if (typeof price === 'number' && price > 0 && key !== checkoutState.addon) {
          const name = key === 'planning' ? 'Planning Module' : 'AI Assistant';
          const percentage = basePrice > 0 ? Math.round((price / basePrice) * 100) : 0;
          existingAddons.push({ name, price, percentage });
        }
      });
      
      // Get percentage for the addon being added (from backend billing config - typically 18%)
      // Get percentage for the addon being added (from backend billing config - typically 18%)
      // This is the FULL monthly price, but checkoutData.amount will have the prorated value
      const fullAddonPrice = basePrice * 0.18; // 18% is standard addon rate
      const addonPercentage = 18; // Fixed percentage for display
      
      return {
        title: `Activate ${addonName}`,
        description: `Adding ${addonName} to your ${currentPlan} plan`,
        operation: 'add_addon',
        data: {
          planName: currentPlan,
          userCount,
          basePrice,
          existingAddons, // Already paid addons
          newAddon: { // The addon being activated NOW
            name: addonName,
            price: fullAddonPrice, // Full monthly price for fallback
            percentage: addonPercentage,
            prorated: true,
          },
        },
      };
    }

    // Case C: License Increment (Delta-Based, Prorated)
    if (checkoutState.mode === 'licenses' && checkoutState.userLimit) {
      const currentPlan = billingSummary.plan.charAt(0).toUpperCase() + billingSummary.plan.slice(1);
      const currentLimit = billingSummary.user_limit || 0;
      const newLimit = checkoutState.userLimit;
      const delta = newLimit - currentLimit;
      
      return {
        title: `Increase Licenses`,
        description: `Adding ${delta} license${delta > 1 ? 's' : ''} to your ${currentPlan} plan`,
        operation: 'add_licenses',
        data: {
          planName: currentPlan,
          currentLimit,
          newLimit,
          delta,
          prorated: true,
        },
      };
    }

    return null;
  };

  const details = getCheckoutDetails();

  return (
    <Dialog
      open={checkoutState.open}
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
            {details?.title || 'Checkout'}
          </Typography>
          <Typography variant="body2" color="text.secondary" sx={{ mt: 0.5 }}>
            {details?.description || ''}
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

        {gatewayConfig && (
          <>
        {loading && (
          <Box sx={{ display: 'flex', justifyContent: 'center', py: 4 }}>
            <CircularProgress />
          </Box>
        )}

        {!loading && details && (
          <Box sx={{ mb: 3 }}>
            <Typography variant="subtitle2" sx={{ fontWeight: 600, mb: 2.5, color: 'text.primary' }}>
              Order Summary
            </Typography>
            
            {/* Case A: Plan Upgrade */}
            {details.operation === 'upgrade' && details.data && (
              <Box sx={{ mb: 2 }}>
                <Typography variant="body2" sx={{ mb: 1.5, fontWeight: 500 }}>
                  Plan upgrade:
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                  {details.data.fromPlan} → {details.data.toPlan}
                </Typography>
                
                {/* Explanation for free plan upgrades */}
                {details.data.isFromFreePlan && (
                  <Typography variant="body2" color="text.secondary" sx={{ mb: 2, fontStyle: 'italic' }}>
                    You are upgrading from the free Starter plan. Your new plan price is charged for all {details.data.userCount} license{details.data.userCount !== 1 ? 's' : ''}.
                  </Typography>
                )}
                
                <Divider sx={{ my: 2 }} />
                
                <Box sx={{ display: 'flex', justifyContent: 'space-between', p: 2, bgcolor: '#fff3e0', borderRadius: 2 }}>
                  <Typography variant="body1" sx={{ fontWeight: 600, color: '#f57c00' }}>
                    Amount to charge now
                  </Typography>
                  <Typography variant="h6" sx={{ fontWeight: 700, color: '#f57c00' }}>
                    {formatTenantMoney(checkoutData?.amount ?? details.data.priceDifference ?? 0, tenantContext)}
                  </Typography>
                </Box>
              </Box>
            )}

            {/* Case B: Downgrade */}
            {details.operation === 'downgrade' && details.data && (
              <Box sx={{ mb: 2 }}>
                <Typography variant="body2" sx={{ mb: 1.5, fontWeight: 500 }}>
                  Scheduled downgrade:
                </Typography>
                <Typography variant="body2" color="text.secondary" sx={{ mb: 1 }}>
                  {details.data.fromPlan} → {details.data.toPlan}
                </Typography>
                <Typography variant="caption" sx={{ color: '#4caf50', fontWeight: 500, display: 'block', mb: 2 }}>
                  Effective on next renewal
                </Typography>
                
                <Divider sx={{ my: 2 }} />
                
                <Box sx={{ display: 'flex', justifyContent: 'space-between', p: 2, bgcolor: '#f5f5f5', borderRadius: 2 }}>
                  <Typography variant="body1" sx={{ fontWeight: 600, color: 'text.secondary' }}>
                    Amount to charge now
                  </Typography>
                  <Typography variant="h6" sx={{ fontWeight: 700, color: 'text.secondary' }}>
                    {formatTenantMoney(0, tenantContext)}
                  </Typography>
                </Box>
              </Box>
            )}

            {/* Case C: Add Add-on */}
            {details.operation === 'add_addon' && details.data && (
              <Box sx={{ mb: 2 }}>
                {/* Base Plan - Already Paid */}
                <Box sx={{ mb: 2 }}>
                  <Typography variant="body2" color="text.secondary" sx={{ mb: 0.5 }}>
                    Current base plan × {details.data.userCount ?? billingSummary?.user_limit ?? 0} license{(details.data.userCount ?? billingSummary?.user_limit ?? 0) > 1 ? 's' : ''}
                  </Typography>
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <Typography variant="caption" sx={{ color: '#4caf50', fontWeight: 500 }}>
                      (already paid for this billing cycle)
                    </Typography>
                    <Typography variant="body2" sx={{ color: 'text.disabled', fontWeight: 500 }}>
                      {formatTenantMoney(details.data.basePrice ?? 0, tenantContext)}
                    </Typography>
                  </Box>
                </Box>

                {/* Existing Add-ons - Already Paid */}
                {details.data.existingAddons && details.data.existingAddons.length > 0 && (
                  <Box sx={{ mb: 2 }}>
                    <Typography variant="body2" sx={{ mb: 1, fontWeight: 500 }}>
                      Add-ons:
                    </Typography>
                    {details.data.existingAddons.map((addon, index) => (
                      <Box key={index} sx={{ mb: 1.5, pl: 2 }}>
                        <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'baseline' }}>
                          <Typography variant="body2" color="text.secondary">
                            • {addon.name}
                          </Typography>
                          <Typography variant="body2" sx={{ color: 'text.disabled', fontWeight: 500 }}>
                            {formatTenantMoney(addon.price, tenantContext)}
                          </Typography>
                        </Box>
                        <Typography variant="caption" sx={{ color: '#4caf50', fontWeight: 500, pl: 2 }}>
                          (already paid for this billing cycle)
                        </Typography>
                      </Box>
                    ))}
                  </Box>
                )}

                {/* New Add-on Being Activated */}
                {details.data.newAddon && (
                  <Box sx={{ mb: 2 }}>
                    <Typography variant="body2" sx={{ mb: 1.5, fontWeight: 500 }}>
                      Add-on being activated:
                    </Typography>
                    <Box sx={{ display: 'flex', justifyContent: 'space-between', mb: 1, pl: 2 }}>
                      <Box>
                        <Typography variant="body2" color="text.secondary">
                          • {details.data.newAddon.name} (+{details.data.newAddon.percentage}%)
                        </Typography>
                        {details.data.newAddon.prorated && (
                          <Typography variant="caption" color="text.secondary">
                            (pro-rated for remaining cycle)
                          </Typography>
                        )}
                      </Box>
                      <Typography variant="body2" sx={{ fontWeight: 500, color: '#667eea' }}>
                        {formatTenantMoney(checkoutData?.amount ?? details.data.newAddon.price, tenantContext)}
                      </Typography>
                    </Box>
                  </Box>
                )}
                
                <Divider sx={{ my: 2 }} />
                
                {/* Amount to Charge */}
                <Box sx={{ display: 'flex', justifyContent: 'space-between', p: 2, bgcolor: '#fff3e0', borderRadius: 2 }}>
                  <Typography variant="body1" sx={{ fontWeight: 600, color: '#f57c00' }}>
                    Amount to charge now
                  </Typography>
                  <Typography variant="h6" sx={{ fontWeight: 700, color: '#f57c00' }}>
                    {formatTenantMoney(checkoutData?.amount ?? 0, tenantContext)}
                  </Typography>
                </Box>
              </Box>
            )}

            {/* Case D: License Increment */}
            {details.operation === 'add_licenses' && details.data && (
              <Box sx={{ mb: 2 }}>
                <Typography variant="body2" sx={{ mb: 1.5, fontWeight: 500 }}>
                  License Details:
                </Typography>
                
                <Box sx={{ mb: 1.5, pl: 2 }}>
                  <Typography variant="body2" color="text.secondary">
                    Current Plan: {details.data.planName}
                  </Typography>
                </Box>
                
                <Box sx={{ mb: 1.5, pl: 2 }}>
                  <Typography variant="body2" color="text.secondary">
                    Current Licenses: {details.data.currentLimit}
                  </Typography>
                </Box>
                
                <Box sx={{ mb: 2, pl: 2 }}>
                  <Typography variant="body2" sx={{ fontWeight: 600, color: '#667eea' }}>
                    Additional Licenses: +{details.data.delta}
                  </Typography>
                </Box>
                
                <Divider sx={{ my: 2 }} />
                
                {/* Amount to Charge */}
                <Box sx={{ display: 'flex', justifyContent: 'space-between', p: 2, bgcolor: '#fff3e0', borderRadius: 2 }}>
                  <Typography variant="body1" sx={{ fontWeight: 600, color: '#f57c00' }}>
                    Amount to charge now
                  </Typography>
                  <Typography variant="h6" sx={{ fontWeight: 700, color: '#f57c00' }}>
                    {formatTenantMoney(checkoutData?.amount ?? 0, tenantContext)}
                  </Typography>
                </Box>
              </Box>
            )}
          </Box>
        )}

        {!loading && checkoutData && gatewayConfig?.gateway === 'stripe' && checkoutData.client_secret && stripePromise && (
          <Elements stripe={stripePromise} options={{ clientSecret: checkoutData.client_secret }}>
            <StripeCheckoutForm
              clientSecret={checkoutData.client_secret}
              paymentId={checkoutData.payment_id}
              amount={checkoutData.amount}
              currency={checkoutData.currency}
              onSuccess={handleSuccess}
              onError={handleError}
              onCancel={handleClose}
            />
          </Elements>
        )}

        {!loading && checkoutData && gatewayConfig?.gateway === 'fake' && (
          <FakeCheckoutForm
            paymentId={checkoutData.payment_id}
            amount={checkoutData.amount}
            onSuccess={handleSuccess}
            onError={handleError}
            onCancel={handleClose}
          />
        )}
          </>
        )}
      </DialogContent>
    </Dialog>
  );
};

export default CheckoutModal;
