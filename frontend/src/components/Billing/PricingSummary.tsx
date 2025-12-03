import React from 'react';
import {
  Card,
  CardContent,
  Typography,
  Box,
  Divider,
  Stack,
} from '@mui/material';
import {
  Receipt as ReceiptIcon,
} from '@mui/icons-material';

interface PricingSummaryProps {
  baseSubtotal: number;
  total: number;
  // Optional: detailed breakdown from BillingSummary
  pricePerUser?: number;
  userLimit?: number;
  addons?: {
    planning: number;
    ai: number;
  };
  nextRenewalAt?: string | null;
  isTrial?: boolean;
  trialEndsAt?: string | null;
}

/**
 * PricingSummary - Displays detailed pricing breakdown and checkout button
 * 
 * Shows:
 * - Base Plan: €X/user × N users = €Y
 * - Individual addons: Planning (+18%), AI (+18%)
 * - Total Monthly: €Z
 * 
 * All pricing values from BillingContext (backend source of truth)
 */
const PricingSummary: React.FC<PricingSummaryProps> = ({ 
  baseSubtotal, 
  total, 
  pricePerUser,
  userLimit,
  addons,
  nextRenewalAt,
  isTrial = false,
  trialEndsAt,
}) => {
  // Fallback to 0 if values are undefined
  const safeBaseSubtotal = baseSubtotal ?? 0;
  const safeTotal = total ?? 0;
  const safeUserLimit = userLimit ?? 0;
  const safePricePerUser = pricePerUser ?? 0;
  const planningAddon = addons?.planning ?? 0;
  const aiAddon = addons?.ai ?? 0;
  const addonsTotal = planningAddon + aiAddon;

  return (
    <Card
      sx={{
        background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
        color: 'white',
      }}
    >
      <CardContent>
        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1, mb: 3 }}>
          <ReceiptIcon sx={{ fontSize: 28 }} />
          <Typography variant="h6" sx={{ fontWeight: 600 }}>
            Pricing Summary
          </Typography>
        </Box>

        <Stack spacing={2}>
          {/* Base Plan with calculation - Only show breakdown if total > 0 */}
          {safeTotal > 0 ? (
            <Box>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                <Typography variant="body1" sx={{ fontWeight: 600 }}>Base Plan × {safeUserLimit} licenses</Typography>
                <Typography variant="h6" sx={{ fontWeight: 600 }}>
                  €{safeBaseSubtotal.toFixed(2)}
                </Typography>
              </Box>
              {safeUserLimit > 0 && safePricePerUser > 0 && (
                <Typography variant="caption" sx={{ opacity: 0.9, mt: 0.5, display: 'block' }}>
                  €{safePricePerUser.toFixed(2)}/user × {safeUserLimit} license{safeUserLimit !== 1 ? 's' : ''}
                </Typography>
              )}
            </Box>
          ) : null}

          {/* Individual Addons */}
          {addonsTotal > 0 && (
            <>
              <Divider sx={{ borderColor: 'rgba(255,255,255,0.2)' }} />
              <Box>
                <Typography variant="body1" sx={{ fontWeight: 600, mb: 1 }}>Add-ons</Typography>
                {planningAddon > 0 && (
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 0.5 }}>
                    <Typography variant="body2" sx={{ opacity: 0.9 }}>
                      • Planning (+18%)
                    </Typography>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>
                      €{planningAddon.toFixed(2)}
                    </Typography>
                  </Box>
                )}
                {aiAddon > 0 && (
                  <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
                    <Typography variant="body2" sx={{ opacity: 0.9 }}>
                      • AI Assistant (+18%)
                    </Typography>
                    <Typography variant="body2" sx={{ fontWeight: 600 }}>
                      €{aiAddon.toFixed(2)}
                    </Typography>
                  </Box>
                )}
              </Box>
            </>
          )}

          {/* Total */}
          <Divider sx={{ borderColor: 'rgba(255,255,255,0.3)' }} />
          <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center' }}>
            <Typography variant="h6" sx={{ fontWeight: 700 }}>
              Total Monthly
            </Typography>
            <Typography variant="h4" sx={{ fontWeight: 700 }}>
              €{safeTotal.toFixed(2)}
            </Typography>
          </Box>

          {/* Next Renewal Date */}
          {isTrial && trialEndsAt && (
            <Box sx={{ mt: 1 }}>
              <Typography variant="caption" sx={{ opacity: 0.9 }}>
                Trial ends on {new Date(trialEndsAt).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}
              </Typography>
            </Box>
          )}
          {!isTrial && nextRenewalAt && (
            <Box sx={{ mt: 1 }}>
              <Typography variant="caption" sx={{ opacity: 0.9 }}>
                Next renewal: {new Date(nextRenewalAt).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}
              </Typography>
            </Box>
          )}
        </Stack>
      </CardContent>
    </Card>
  );
};

export default PricingSummary;
