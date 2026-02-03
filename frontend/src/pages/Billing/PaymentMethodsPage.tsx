import React, { useState, useEffect } from 'react';
import {
  Box,
  Card,
  CardContent,
  Typography,
  Button,
  IconButton,
  Chip,
  Stack,
  Alert,
  CircularProgress,
  Grid,
} from '@mui/material';
import {
  Add as AddIcon,
  CreditCard as CreditCardIcon,
  Delete as DeleteIcon,
  Star as StarIcon,
  StarBorder as StarBorderIcon,
} from '@mui/icons-material';
import { useNotification } from '../../contexts/NotificationContext';
import {
  getPaymentMethods,
  setDefaultPaymentMethod,
  removePaymentMethod,
  type PaymentMethod,
} from '../../api/billing';
import AddCardModal from '../../components/Billing/AddCardModal';
import { useTranslation } from 'react-i18next';

/**
 * Payment Methods Management Page
 * 
 * Features:
 * - List all saved cards
 * - Add new card (Stripe Elements)
 * - Set default card
 * - Remove card
 * 
 * Styling: Follows BillingPage patterns (cards, spacing, colors)
 */
const PaymentMethodsPage: React.FC = () => {
  const [paymentMethods, setPaymentMethods] = useState<PaymentMethod[]>([]);
  const [loading, setLoading] = useState(true);
  const [addCardModalOpen, setAddCardModalOpen] = useState(false);
  const { showSuccess, showError } = useNotification();
  const { t } = useTranslation();

  useEffect(() => {
    fetchPaymentMethods();
  }, []);

  const fetchPaymentMethods = async () => {
    setLoading(true);
    try {
      const methods = await getPaymentMethods();
      setPaymentMethods(methods);
    } catch (error: any) {
      showError(error.message || t('billing.paymentMethods.errors.loadFailed'));
    } finally {
      setLoading(false);
    }
  };

  const handleSetDefault = async (paymentMethodId: string) => {
    try {
      await setDefaultPaymentMethod(paymentMethodId);
      showSuccess(t('billing.paymentMethods.toast.defaultUpdated'));
      fetchPaymentMethods();
    } catch (error: any) {
      showError(error.message || t('billing.paymentMethods.errors.setDefaultFailed'));
    }
  };

  const handleRemove = async (paymentMethodId: string) => {
    if (!confirm(t('billing.paymentMethods.confirmRemove'))) {
      return;
    }

    try {
      await removePaymentMethod(paymentMethodId);
      showSuccess(t('billing.paymentMethods.toast.removed'));
      fetchPaymentMethods();
    } catch (error: any) {
      showError(error.message || t('billing.paymentMethods.errors.removeFailed'));
    }
  };

  const handleCardAdded = () => {
    setAddCardModalOpen(false);
    fetchPaymentMethods();
    showSuccess(t('billing.paymentMethods.toast.added'));
  };

  const getCardBrandIcon = (_brand: string) => {
    // Could add brand-specific icons here (Visa, Mastercard, etc.)
    return <CreditCardIcon />;
  };

  const getCardBrandColor = (brand: string): string => {
    const brandColors: Record<string, string> = {
      visa: '#1A1F71',
      mastercard: '#EB001B',
      amex: '#006FCF',
      discover: '#FF6000',
    };
    return brandColors[brand.toLowerCase()] || '#667eea';
  };

  if (loading) {
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', minHeight: '400px' }}>
        <CircularProgress />
      </Box>
    );
  }

  return (
    <Box sx={{ maxWidth: 1200, margin: '0 auto', padding: 3 }}>
      {/* Header */}
      <Box sx={{ mb: 4, display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
        <Box>
          <Typography variant="h4" sx={{ fontWeight: 600, color: '#667eea', mb: 1 }}>
            {t('billing.paymentMethods.title')}
          </Typography>
          <Typography variant="body2" color="text.secondary">
            {t('billing.paymentMethods.subtitle')}
          </Typography>
        </Box>
        <Button
          variant="contained"
          startIcon={<AddIcon />}
          onClick={() => setAddCardModalOpen(true)}
          sx={{
            background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
            textTransform: 'none',
            fontWeight: 600,
            px: 3,
          }}
        >
          {t('billing.paymentMethods.addCard')}
        </Button>
      </Box>

      {/* Info Alert */}
      {paymentMethods.length === 0 && (
        <Alert severity="info" sx={{ mb: 3 }}>
          {t('billing.paymentMethods.empty')}
        </Alert>
      )}

      {/* Payment Methods Grid */}
      <Grid container spacing={3}>
        {paymentMethods.map((method) => (
          <Grid item xs={12} sm={6} md={4} key={method.id}>
            <Card
              sx={{
                height: '100%',
                border: method.is_default ? '2px solid #667eea' : '1px solid #e0e0e0',
                boxShadow: method.is_default ? '0 4px 12px rgba(102, 126, 234, 0.2)' : 'none',
                transition: 'all 0.3s ease',
                '&:hover': {
                  boxShadow: '0 4px 12px rgba(0,0,0,0.1)',
                  transform: 'translateY(-2px)',
                },
              }}
            >
              <CardContent>
                {/* Default Badge */}
                {method.is_default && (
                  <Chip
                    label={t('billing.paymentMethods.defaultLabel')}
                    size="small"
                    icon={<StarIcon />}
                    sx={{
                      background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
                      color: 'white',
                      fontWeight: 600,
                      mb: 2,
                    }}
                  />
                )}

                {/* Card Icon & Brand */}
                <Box sx={{ display: 'flex', alignItems: 'center', mb: 2 }}>
                  <Box
                    sx={{
                      width: 50,
                      height: 50,
                      borderRadius: '50%',
                      backgroundColor: getCardBrandColor(method.card.brand),
                      display: 'flex',
                      alignItems: 'center',
                      justifyContent: 'center',
                      color: 'white',
                      mr: 2,
                    }}
                  >
                    {getCardBrandIcon(method.card.brand)}
                  </Box>
                  <Box>
                    <Typography variant="subtitle1" sx={{ fontWeight: 600, textTransform: 'capitalize' }}>
                      {method.card.brand}
                    </Typography>
                    <Typography variant="body2" color="text.secondary">
                      •••• {method.card.last4}
                    </Typography>
                  </Box>
                </Box>

                {/* Expiry Date */}
                <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
                  {t('billing.paymentMethods.expires', {
                    month: String(method.card.exp_month).padStart(2, '0'),
                    year: method.card.exp_year
                  })}
                </Typography>

                {/* Actions */}
                <Stack direction="row" spacing={1}>
                  {!method.is_default && (
                    <Button
                      size="small"
                      variant="outlined"
                      startIcon={<StarBorderIcon />}
                      onClick={() => handleSetDefault(method.id)}
                      sx={{ flex: 1, textTransform: 'none' }}
                    >
                      {t('billing.paymentMethods.setDefault')}
                    </Button>
                  )}
                  <IconButton
                    size="small"
                    color="error"
                    onClick={() => handleRemove(method.id)}
                    disabled={method.is_default && paymentMethods.length === 1}
                    title={
                      method.is_default && paymentMethods.length === 1
                        ? t('billing.paymentMethods.cannotRemoveOnly')
                        : t('billing.paymentMethods.removeCard')
                    }
                  >
                    <DeleteIcon fontSize="small" />
                  </IconButton>
                </Stack>
              </CardContent>
            </Card>
          </Grid>
        ))}
      </Grid>

      {/* Add Card Modal */}
      <AddCardModal
        open={addCardModalOpen}
        onClose={() => setAddCardModalOpen(false)}
        onSuccess={handleCardAdded}
      />
    </Box>
  );
};

export default PaymentMethodsPage;
