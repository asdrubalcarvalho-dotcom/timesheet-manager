import React, { useEffect, useState } from 'react';
import {
  Box,
  Container,
  Typography,
  Card,
  CardContent,
  Grid,
  CircularProgress,
  Alert,
  AlertTitle,
  Button,
  Chip,
  Divider,
  Tooltip,
  Dialog,
  DialogTitle,
  DialogContent,
  DialogActions,
  TextField,
  Switch,
} from '@mui/material';
import {
  AdminPanelSettings as BillingIcon,
  People as UsersIcon,
  Info as InfoIcon,
  Refresh as RefreshIcon,
  ManageAccounts as PortalIcon,
} from '@mui/icons-material';
import { useBilling } from '../../contexts/BillingContext';
import { useNotification } from '../../contexts/NotificationContext';
import type { BillingSummary } from '../../types/billing';
import { getCustomerPortalUrl } from '../../api/billing';
import type { FeatureFlagValue } from '../../api/billing';
import PlanCard from './PlanCard';
import AddonToggle from './AddonToggle';
import PricingSummary from './PricingSummary';
import CheckoutModal from './CheckoutModal';
import { ConfirmDowngradeDialog } from './ConfirmDowngradeDialog';
import { ConfirmTrialExitDialog } from './ConfirmTrialExitDialog';
import api from '../../services/api';

/**
 * Calculate remaining trial days
 * Returns null if not in trial or trial has expired
 */
function getTrialDaysLeft(summary?: BillingSummary | null): number | null {
  if (!summary?.is_trial || !summary.trial?.ends_at) return null;

  const now = new Date();
  const end = new Date(summary.trial.ends_at);
  const diffMs = end.getTime() - now.getTime();
  const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));

  if (diffDays < 0) return null; // already expired, banner should not show
  return diffDays;
}

const resolveFeatureEnabled = (value: FeatureFlagValue | boolean | undefined): boolean => {
  if (value && typeof value === 'object') {
    return Boolean(value.enabled);
  }
  return Boolean(value);
};
// BILLING RULE: License caps are enforced in UI to prevent invalid checkout requests.
// NOTE: Behavior is intentional — do not change without reviewing billing logic
const getMaxLicenseLimitForPlan = (plan: string): number => {
  switch (plan) {
    case 'trial_enterprise':
      return 10;
    case 'starter':
      return 2;
    case 'team':
    case 'enterprise':
      return 100;
    default:
      return 0;
  }
};

/**
 * Get the effective license limit to display and use in calculations.
 * - Starter: always 2 (fixed)
 * - Other plans: use user_limit if available, fall back to user_count, never show 0
 */
// BILLING SEMANTICS:
// - user_limit = BILLABLE / purchased seats (used for pricing/checkout)
// - user_count = DISPLAY ONLY / active users (used for UI and downgrade safety checks)
const getEffectiveLicenseLimit = (summary: BillingSummary): number => {
  const plan = summary.plan;

  // Starter is fixed at 2
  if (plan === 'starter') return 2;

  // If backend provides a positive user_limit, trust it
  const limit = summary.user_limit;
  if (typeof limit === 'number' && limit > 0) return limit;

  // Otherwise fall back to user_count if available (>0)
  const count = summary.user_count;
  if (typeof count === 'number' && count > 0) return count;

  // Final fallback: 1 (never show 0 for paid/trial plans)
  return 1;
};
/**
 * Get plan description (English only)
 */
function getPlanDescription(plan: string): string {
  switch (plan) {
    case 'starter':
      return 'Free plan for up to 2 users.';
    case 'team':
      return 'Ideal for teams that need travels and optional planning.';
    case 'enterprise':
      return 'All features included, recommended for larger organizations.';
    case 'trial_enterprise':
      return 'Trial period with all features enabled.';
    default:
      return '';
  }
}

/**
 * Get compact feature summary for current plan (English only)
 */
function getFeatureSummary(
  currentPlan: string,
  features: { timesheets: boolean; expenses: boolean; travels: boolean; planning: boolean; ai: FeatureFlagValue | boolean }
): { included: string; addons: string } {
  const { timesheets, expenses, travels, planning } = features;
  const ai = resolveFeatureEnabled(features.ai);
  
  // Starter plan: only enabled features
  if (currentPlan === 'starter') {
    const enabledFeatures = [];
    if (timesheets) enabledFeatures.push('Timesheets');
    if (expenses) enabledFeatures.push('Expenses');
    
    return {
      included: `Includes: ${enabledFeatures.join(', ')}.`,
      addons: 'No add-ons available.',
    };
  }
  
  // Enterprise/Trial: all features included
  if (currentPlan === 'enterprise' || currentPlan === 'trial_enterprise') {
    return {
      included: 'Includes: Timesheets, Expenses, Travels, Planning, AI Assistant.',
      addons: 'No add-ons required (all included).',
    };
  }
  
  // Team plan: base features + available add-ons
  const baseFeatures: string[] = ['Timesheets'];
  if (expenses) baseFeatures.push('Expenses');
  if (travels) baseFeatures.push('Travels');
  
  const availableAddons: string[] = [];
  if (!planning) availableAddons.push('Planning');
  if (!ai) availableAddons.push('AI Assistant');
  
  // Add enabled add-ons to base features
  if (planning) baseFeatures.push('Planning');
  if (ai) baseFeatures.push('AI Assistant');
  
  return {
    included: `Includes: ${baseFeatures.join(', ')}.`,
    addons: availableAddons.length > 0 
      ? `Available add-ons: ${availableAddons.join(', ')}.`
      : 'All add-ons active.',
  };
}

/**
 * BillingPage - Main billing management interface
 * 
 * Displays current plan, users, addons, pricing, and checkout flow
 * All pricing data comes from backend (no frontend calculations)
 */
const BillingPage: React.FC = () => {
  const { 
    billingSummary, 
    tenantAiEnabled,
    loading, 
    initializing,
    error, 
    openCheckoutForPlan,
    openCheckoutForAddon,
    openCheckoutForLicenses,
    toggleAddon,
    updateTenantAiToggle,
    requestDowngrade,
    cancelDowngrade,
    refreshSummary 
  } = useBilling();
  const { showError, showWarning, showInfo, showSuccess } = useNotification();

  // Downgrade confirmation dialog state
  const [confirmDialogOpen, setConfirmDialogOpen] = useState(false);
  const [targetDowngradePlan, setTargetDowngradePlan] = useState<'starter' | 'team' | null>(null);

  // Trial exit confirmation dialog state (separate from downgrade)
  const [trialExitDialogOpen, setTrialExitDialogOpen] = useState(false);
  const [targetTrialExitPlan, setTargetTrialExitPlan] = useState<'starter' | 'team' | 'enterprise' | null>(null);

  // License increase dialog state
  const [licenseDialogOpen, setLicenseDialogOpen] = useState(false);
  const [licenseIncrement, setLicenseIncrement] = useState<number>(1);
  const [updatingTenantAi, setUpdatingTenantAi] = useState(false);

  console.log('[BillingPage] 🎨 RENDER - billingSummary:', billingSummary, 'loading:', loading, 'initializing:', initializing);

  // CRITICAL: Refresh billing summary on every page render
  // This ensures user_count is ALWAYS fresh (e.g., after deleting users)
  useEffect(() => {
    console.log('[BillingPage] 🔄 Refreshing billing summary on mount/navigation');
    refreshSummary();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []); // Empty deps = run only on mount

  // ONE-WAY ENFORCEMENT: Billing OFF → Tenant AI OFF
  // NOTE: AI enforcement applies ONLY to Team plan. Enterprise includes AI by default.
  // When billing add-on is disabled, automatically force tenant AI toggle OFF
  // IMPORTANT: This protects billing compliance — tenant AI toggle must never enable AI without billing entitlement.
  // DO NOT REFACTOR: Billing add-on (entitlement) and tenant AI toggle (preference) are intentionally separate.
  useEffect(() => {
    if (!billingSummary) return;

    // Only Team uses paid add-on entitlement for AI.
    if (billingSummary.plan !== 'team') return;
    
    const billingAiEnabled = Boolean(billingSummary.addons?.ai && billingSummary.addons.ai > 0);
    
    // If billing addon is OFF but tenant AI is ON, force tenant AI OFF silently
    if (!billingAiEnabled && tenantAiEnabled) {
      console.log('[BillingPage] 🔒 One-way enforcement: Billing AI OFF → forcing tenant AI OFF');
      updateTenantAiToggle(false).catch(err => {
        console.error('[BillingPage] Failed to enforce one-way sync:', err);
      });
    }
  }, [billingSummary?.plan, billingSummary?.addons?.ai, tenantAiEnabled, updateTenantAiToggle]);

  // Handle addon toggle with plan-specific rules (PHASE 3 - Frontend validation)
  const handleToggleAddon = async (addon: 'planning' | 'ai') => {
    if (!billingSummary) return;

    // BUSINESS RULE: Starter cannot buy/enable add-ons.
    // DO NOT REFACTOR: UI must avoid calling the API for disallowed actions.

    // Starter: No add-ons allowed
    if (billingSummary.plan === 'starter') {
      showWarning(
        "Add-ons are not available on the Starter plan. Upgrade to Team or Enterprise to enable them."
      );
      return; // IMPORTANT: do not call API
    }

    // Enterprise/Trial: All features included, no add-ons needed
    // INTENTIONAL: trial_enterprise behaves like enterprise for feature availability (no separate add-on purchases).
    if (billingSummary.plan === 'enterprise' || billingSummary.plan === 'trial_enterprise') {
      showInfo("All features are already included in your current plan.");
      return; // IMPORTANT: do not call API
    }

    // Team: Check if addon is currently enabled (based on BILLING ENTITLEMENT ONLY)
    // BILLING RULE: For AI, billingSummary.addons.ai reflects paid entitlement; tenantAiEnabled is a separate tenant preference.
    // DO NOT REFACTOR: These fields intentionally do not mirror each other.
    const isCurrentlyEnabled = addon === 'planning' 
      ? Boolean(billingSummary.features?.planning) 
      : Boolean(billingSummary.addons?.ai && billingSummary.addons.ai > 0);

    if (isCurrentlyEnabled) {
      // DISABLING: No payment needed, just toggle off
      try {
        await toggleAddon(addon);
        showSuccess(`${addon === 'planning' ? 'Planning' : 'AI'} add-on disabled successfully.`);
      } catch (err: any) {
        showError(err?.message || "Failed to disable add-on.");
      }
    } else {
      // ENABLING: Requires payment, open checkout
      try {
        await openCheckoutForAddon(addon);
      } catch (err: any) {
        showError(err?.message || "Failed to open checkout.");
      }
    }
  };

  const handleTenantAiToggle = async (nextValue: boolean) => {
    if (!billingSummary) return;

    // IMPORTANT: This toggle is TENANT-LEVEL preference.
    // BILLING RULE: It can only be used if the tenant is entitled via billing (AI add-on or Enterprise/Trial).

    if (!aiEntitled) {
      showInfo('Requires AI add-on in billing plan.');
      return;
    }

    setUpdatingTenantAi(true);
    try {
      await updateTenantAiToggle(nextValue);
    } catch (error) {
      console.error('[BillingPage] Failed to update tenant AI toggle', error);
    } finally {
      setUpdatingTenantAi(false);
    }
  };

  // Handle plan downgrade (show confirmation dialog first)
  const handleDowngrade = async (plan: 'starter' | 'team') => {
    if (!billingSummary) return;

    // SPECIAL CASE: Trial → Paid Plan (immediate conversion)
    // BUSINESS RULE: Trial exits are handled differently from paid-plan downgrades (immediate vs scheduled).
    if (billingSummary.is_trial) {
      setTargetTrialExitPlan(plan);
      setTrialExitDialogOpen(true);
      return;
    }

    // NORMAL CASE: Paid Plan → Paid Plan (scheduled downgrade)
    setTargetDowngradePlan(plan);
    setConfirmDialogOpen(true);
  };

  // Confirm downgrade after dialog approval
  const confirmDowngrade = async () => {
    if (!targetDowngradePlan) return;

    setConfirmDialogOpen(false);
    
    try {
      await requestDowngrade(targetDowngradePlan);
    } catch (err: any) {
      showError(err?.message || "Failed to schedule downgrade.");
    } finally {
      setTargetDowngradePlan(null);
    }
  };

  // Confirm trial exit (immediate conversion with payment for paid plans)
  const confirmTrialExit = async () => {
    if (!targetTrialExitPlan) return;

    setTrialExitDialogOpen(false);
    
    try {
      // Trial → Starter: Free downgrade (no payment)
      if (targetTrialExitPlan === 'starter') {
        await requestDowngrade(targetTrialExitPlan);
        // No showInfo needed - requestDowngrade already shows success/error message
      } 
      // Trial → Team/Enterprise: Paid upgrade (requires checkout)
      else {
        await openCheckoutForPlan(targetTrialExitPlan);
      }
    } catch (err: any) {
      showError(err?.message || "Failed to exit trial.");
    } finally {
      setTargetTrialExitPlan(null);
    }
  };

  // Cancel downgrade confirmation dialog
  const handleDialogClose = () => {
    setConfirmDialogOpen(false);
    setTargetDowngradePlan(null);
  };

  // Cancel trial exit dialog
  const handleTrialExitDialogClose = () => {
    setTrialExitDialogOpen(false);
    setTargetTrialExitPlan(null);
  };

  // Handle cancellation of scheduled downgrade
  const handleCancelDowngrade = async () => {
    try {
      await cancelDowngrade();
    } catch (err: any) {
      showError(err?.message || "Failed to cancel downgrade.");
    }
  };

  // Handle opening Stripe Customer Portal
  const handleOpenPortal = async () => {
    try {
      const portalUrl = await getCustomerPortalUrl();
      // Redirect browser to Stripe portal
      window.location.href = portalUrl;
    } catch (err: any) {
      showError(err?.message || "Failed to open customer portal.");
    }
  };

  if (initializing) {
    console.log('[BillingPage] ⏳ Initializing billing state - showing spinner');
    return (
      <Box sx={{ display: 'flex', justifyContent: 'center', alignItems: 'center', height: '100vh' }}>
        <CircularProgress />
      </Box>
    );
  }

  // NOTE: Errors are shown via NotificationContext toast (showError)
  // Do not render error page here - just log and continue showing the UI
  if (error) {
    console.error('[BillingPage] ❌ Error logged (shown via toast):', error);
    // Continue to render normal UI instead of error page
  }

  if (!billingSummary) {
    console.warn('[BillingPage] ⚠️ No billing summary available');
    return (
      <Container maxWidth="lg" sx={{ mt: 4 }}>
        <Alert severity="warning">No billing information available</Alert>
      </Container>
    );
  }

  const currentPlan = billingSummary.plan;
  const addons = billingSummary.addons || { planning: 0, ai: 0 }; // Fallback to zero pricing
  const requiresUpgrade = billingSummary.requires_upgrade;
  const defaultFeatures: BillingSummary['features'] = {
    timesheets: false,
    expenses: false,
    travels: false,
    planning: false,
    ai: { enabled: false },
  };
  const features: BillingSummary['features'] = billingSummary.features ?? defaultFeatures;
  const planningFeatureEnabled = Boolean(features.planning);
  // AI entitlement rule (DO NOT broaden beyond this):
  // - Enterprise + Enterprise Trial: always entitled
  // - Team: entitled only if AI add-on is purchased (addons.ai > 0)
  // - Starter: never entitled
  const aiEntitled =
    currentPlan === 'enterprise' ||
    currentPlan === 'trial_enterprise' ||
    (currentPlan === 'team' && addons.ai > 0);

  // BILLING SEMANTICS: aiEntitled is billing entitlement; tenantAiEnabled is tenant preference.
  // INTENTIONAL: Final AI availability is entitlement AND tenant toggle (see one-way enforcement effect).

  console.log('[BillingPage] 📊 Current state - plan:', currentPlan, 'addons:', addons, 'requiresUpgrade:', requiresUpgrade);

  return (
    <Box sx={{ 
      p: 0,
      width: '100%',
      maxWidth: '100%',
      height: '100vh',
      display: 'flex',
      flexDirection: 'column',
      overflow: 'hidden',
      bgcolor: '#f5f5f5'
    }}>
      {/* Header - Sticky */}
      <Card 
        sx={{ 
          mb: 0,
          background: 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)',
          color: 'white',
          borderRadius: 0,
          boxShadow: 'none',
          borderBottom: '2px solid rgba(255,255,255,0.2)',
          position: 'sticky',
          top: 0,
          zIndex: 100,
          flexShrink: 0
        }}
      >
        <CardContent sx={{ p: { xs: 0.75, sm: 1 }, '&:last-child': { pb: { xs: 0.75, sm: 1 } } }}>
          <Box sx={{ display: 'flex', alignItems: 'center', justifyContent: 'space-between', gap: 1, px: 2 }}>
            <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
              <BillingIcon sx={{ fontSize: { xs: 24, sm: 28 } }} />
              <Typography 
                variant="h6" 
                sx={{ 
                  fontWeight: 600,
                  fontSize: { xs: '1.1rem', sm: '1.25rem' }
                }}
              >
                Billing & Subscription
              </Typography>
            </Box>
            <Box sx={{ display: 'flex', gap: 1 }}>
              <Button
                onClick={handleOpenPortal}
                startIcon={<PortalIcon />}
                sx={{ 
                  color: 'white',
                  '&:hover': { 
                    backgroundColor: 'rgba(255,255,255,0.1)' 
                  },
                  textTransform: 'none',
                  display: { xs: 'none', sm: 'flex' }
                }}
                size="small"
              >
                Manage Subscription
              </Button>
              <Button
                onClick={() => {
                  console.log('[BillingPage] 🔄 Manual refresh triggered');
                  refreshSummary();
                }}
                startIcon={<RefreshIcon />}
                sx={{ 
                  color: 'white',
                  '&:hover': { 
                    backgroundColor: 'rgba(255,255,255,0.1)' 
                  },
                  textTransform: 'none'
                }}
                size="small"
              >
                Refresh
              </Button>
            </Box>
          </Box>
        </CardContent>
      </Card>

      {/* Content - Scrollable */}
      <Box sx={{ flex: 1, overflow: 'auto' }}>
        <Container maxWidth="xl" sx={{ py: 3 }}>
          {/* Trial Banner */}
          {(() => {
            const daysLeft = getTrialDaysLeft(billingSummary);
            if (daysLeft === null) return null;

            let message = '';
            if (daysLeft === 0) {
              message = 'Your trial ends today. Upgrade now to keep all features (travels, planning and AI).';
            } else if (daysLeft === 1) {
              message = 'Your trial ends in 1 day. Upgrade now to keep all features (travels, planning and AI).';
            } else {
              message = `Your trial ends in ${daysLeft} days. Upgrade now to keep all features (travels, planning and AI).`;
            }

            return (
              <Box mb={3}>
                <Alert
                  severity="warning"
                  variant="outlined"
                  action={
                    <Button
                      color="inherit"
                      size="small"
                      onClick={() => {
                        const section = document.getElementById('billing-plans-section');
                        if (section) {
                          section.scrollIntoView({ behavior: 'smooth' });
                        }
                      }}
                    >
                      Upgrade now
                    </Button>
                  }
                >
                  <AlertTitle>Trial active</AlertTitle>
                  {message}
                </Alert>
              </Box>
            );
          })()}

          {/* Pending Downgrade Banner */}
          {billingSummary.pending_downgrade && !billingSummary.is_trial && billingSummary.subscription_state !== 'expired' && (
            <Box mb={3}>
              <Alert
                severity="info"
                variant="outlined"
                icon={<InfoIcon />}
                action={
                  billingSummary.can_cancel_downgrade ? (
                    <Button 
                      color="inherit" 
                      size="small" 
                      onClick={handleCancelDowngrade}
                    >
                      Cancel Downgrade
                    </Button>
                  ) : (
                    <Tooltip title="Cannot cancel within 24 hours of renewal">
                      <span>
                        <Button color="inherit" size="small" disabled>
                          Cancel Downgrade
                        </Button>
                      </span>
                    </Tooltip>
                  )
                }
              >
                <AlertTitle>Downgrade Scheduled</AlertTitle>
                Your plan will change to <strong>{billingSummary.pending_downgrade.target_plan.charAt(0).toUpperCase() + billingSummary.pending_downgrade.target_plan.slice(1)}</strong> on{' '}
                {billingSummary.pending_downgrade.effective_at 
                  ? new Date(billingSummary.pending_downgrade.effective_at).toLocaleDateString('en-GB', { 
                      day: '2-digit',
                      month: 'short', 
                      year: 'numeric' 
                    })
                  : 'next renewal date'}.
                <br />
                <strong>All features of your current {currentPlan} plan remain active until that date.</strong>
              </Alert>
            </Box>
          )}

          {/* Upgrade Warning */}
          {requiresUpgrade && (
            <Alert severity="warning" sx={{ mb: 3 }}>
              You have exceeded the limits of your current plan. Please upgrade to continue using all features.
            </Alert>
          )}

          {/* Current Plan Summary */}
          <Card sx={{ mb: 3, background: 'linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%)' }}>
            <CardContent>
              <Box sx={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', mb: 2 }}>
                <Typography variant="overline" color="text.secondary" sx={{ fontWeight: 600 }}>
                  Current plan
                </Typography>
              </Box>
              
              <Box sx={{ display: 'flex', alignItems: 'center', gap: 2, mt: 1, mb: 2 }}>
                <Typography variant="h4" sx={{ fontWeight: 700, textTransform: 'capitalize' }}>
                  {currentPlan === 'trial_enterprise' ? 'Enterprise Trial' : currentPlan}
                </Typography>
                
                {billingSummary.is_trial && (() => {
                  const daysLeft = getTrialDaysLeft(billingSummary);
                  return daysLeft !== null ? (
                    <Chip
                      label={`${daysLeft} ${daysLeft === 1 ? 'day' : 'days'} remaining`}
                      color="warning"
                      size="small"
                      sx={{ fontWeight: 600 }}
                    />
                  ) : null;
                })()}
                
                {!billingSummary.is_trial && (
                  <Chip
                    label="Active subscription"
                    color="success"
                    size="small"
                    sx={{ fontWeight: 600 }}
                  />
                )}
              </Box>

              <Grid container spacing={2}>
                {/* Users */}
                <Grid item xs={12} sm={4}>
                  {(() => {
                    // BILLING RULE: effectiveLimit is the license limit used for checkout (derived from user_limit).
                    // DISPLAY ONLY: user_count is shown for visibility but must not be treated as billable seats.
                    const effectiveLimit = getEffectiveLicenseLimit(billingSummary);
                    const maxLimit = getMaxLicenseLimitForPlan(billingSummary.plan);
                    const canIncrease = billingSummary.plan !== 'starter' && effectiveLimit < maxLimit;

                    return (
                      <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
                        <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                          <UsersIcon color="primary" />
                          <Box>
                            <Typography variant="caption" color="text.secondary">
                              Licenses
                            </Typography>

                            <Typography variant="h6" sx={{ fontWeight: 400 }}>
                              {effectiveLimit} {effectiveLimit === 1 ? 'license' : 'licenses'}
                            </Typography>

                            <Typography
                              variant="caption"
                              color="text.secondary"
                              sx={{ display: 'block', mt: 0.5 }}
                            >
                              {/* DISPLAY ONLY: Active users can differ from billable licenses (user_limit). */}
                              {billingSummary.user_count} {billingSummary.user_count === 1 ? 'active user' : 'active users'}
                            </Typography>
                          </Box>
                        </Box>

                        {billingSummary.plan !== 'starter' && (
                          <Button
                            size="small"
                            variant="text"
                            disabled={!canIncrease}
                            onClick={() => setLicenseDialogOpen(true)}
                            sx={{ textTransform: 'none', alignSelf: 'flex-start' }}
                          >
                            + Increase Licenses
                          </Button>
                        )}
                      </Box>
                    );
                  })()}
                </Grid>

                {/* Price */}
                <Grid item xs={12} sm={4}>
                  <Box>
                    <Typography variant="caption" color="text.secondary">
                      Estimated monthly total
                    </Typography>
                    <Typography variant="h6" sx={{ fontWeight: 600, color: 'primary.main' }}>
                      {billingSummary.total === 0 ? 'Free' : `€${billingSummary.total}/month`}
                    </Typography>
                    {billingSummary.is_trial && billingSummary.trial?.ends_at && (
                      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                        Trial ends on {new Date(billingSummary.trial.ends_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}
                      </Typography>
                    )}
                    {!billingSummary.is_trial && billingSummary.subscription?.next_renewal_at && (
                      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                        Next renewal: {new Date(billingSummary.subscription.next_renewal_at).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}
                      </Typography>
                    )}
                    {!billingSummary.is_trial && billingSummary.subscription?.subscription_start_date && (
                      <Typography variant="caption" color="text.secondary" sx={{ display: 'block', mt: 0.5 }}>
                        Subscription started: {new Date(billingSummary.subscription.subscription_start_date).toLocaleDateString('en-GB', { day: '2-digit', month: 'short', year: 'numeric' })}
                      </Typography>
                    )}
                  </Box>
                </Grid>

                {/* Info Icon with Tooltip */}
                <Grid item xs={12} sm={4}>
                  <Tooltip
                    title={getPlanDescription(currentPlan)}
                    arrow
                  >
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 0.5, cursor: 'help' }}>
                      <InfoIcon fontSize="small" color="action" />
                      <Typography variant="caption" color="text.secondary">
                        Plan details
                      </Typography>
                    </Box>
                  </Tooltip>
                </Grid>
              </Grid>

              <Divider sx={{ my: 2 }} />

              <Typography variant="body2" color="text.secondary" sx={{ fontStyle: 'italic' }}>
                {getPlanDescription(currentPlan)}
              </Typography>

              {/* Compact Feature Summary */}
              <Box sx={{ mt: 2, p: 2, bgcolor: 'rgba(255,255,255,0.6)', borderRadius: 1 }}>
                {(() => {
                  const summary = getFeatureSummary(currentPlan, features);
                  return (
                    <>
                      <Typography variant="body2" sx={{ fontWeight: 500 }}>
                        {summary.included}
                      </Typography>
                      <Typography variant="body2" sx={{ fontWeight: 500, mt: 0.5 }}>
                        {summary.addons}
                      </Typography>
                    </>
                  );
                })()}
              </Box>
            </CardContent>
          </Card>

          <Grid container spacing={3} id="billing-plans-section">
            {/* Available Plans */}
            <Grid item xs={12}>
              <Typography variant="h6" sx={{ mb: 2, fontWeight: 600 }}>
                Available Plans
              </Typography>
              <Grid container spacing={2}>
                {(['starter', 'team', 'enterprise'] as const).map((planName) => {
                  // IMPORTANT: When subscription is not active (e.g. expired/read-only after trial),
                  // the UI must NOT lock the tenant into the backend's persisted plan.
                  // Allow selecting ANY plan (including the same plan label) via checkout.
                  const subscriptionState = billingSummary?.subscription_state;
                  const isExpiredTenant = subscriptionState === 'expired';
                  const isNonActiveState =
                    billingSummary?.read_only === true ||
                    (subscriptionState != null && !['active', 'trial'].includes(subscriptionState));

                  const isCurrentPlan = !isNonActiveState && planName === currentPlan;
                  
                  // Determine if this is a downgrade (current plan is higher tier)
                  // For Trial, treat Team/Enterprise as UPGRADE (higher tier than trial in terms of features, even though both are level 3)
                  // DO NOT REFACTOR: trial_enterprise is a virtual plan; hierarchy keeps UI decisions consistent with backend trial behavior.
                  const planHierarchy = { starter: 1, team: 2, enterprise: 3, trial_enterprise: 3 };
                  const currentLevel = planHierarchy[currentPlan as keyof typeof planHierarchy];
                  const targetLevel = planHierarchy[planName];
                  const isDowngrade = targetLevel < currentLevel;

                  // TRIAL OVERRIDES tier comparisons:
                  // From a trial state, ANY plan selection must use checkout/start (immediate trial exit/payment).
                  const isTrialTenant =
                    billingSummary?.subscription_state === 'trial' ||
                    billingSummary?.is_trial === true ||
                    currentPlan === 'trial_enterprise';

                  // Pending downgrade guard (paid tenants): block all plan changes.
                  const shouldBlockPlanChanges = !isTrialTenant && !isExpiredTenant && Boolean(billingSummary?.pending_downgrade);
                  
                  // Check if there's a pending downgrade to this plan
                  const hasPendingDowngrade = billingSummary?.pending_downgrade?.target_plan === planName;
                  
                  // Downgrades MUST NOT go through checkout.
                  // Stripe checkout is upgrade-oriented and can attempt to create a €0.00 payment for downgrades.
                  // Use the existing schedule-downgrade flow for lower-tier selections, even if the tenant is currently read-only.
                  // Starter path (restore previous behavior):
                  // - Trial → Starter uses the existing downgrade handler (backend applies immediate trial conversion).
                  // - Paid → Starter/Team uses schedule-downgrade (deferred) and must not open checkout.
                  const isTrialToStarter = isTrialTenant && planName === 'starter' && currentPlan !== 'starter';

                  const isPaidDowngrade =
                    !isTrialTenant &&
                    !isExpiredTenant &&
                    isDowngrade &&
                    (planName === 'starter' || planName === 'team');

                  // EXPIRED REACTIVATION:
                  // - Expired tenants should not schedule downgrades (no next renewal).
                  // - Starter activation is handled via schedule-downgrade but applies immediately on backend.
                  const isExpiredToStarter = isExpiredTenant && planName === 'starter';

                  const canShowDowngradeButton =
                    !shouldBlockPlanChanges &&
                    (isTrialToStarter || isPaidDowngrade || isExpiredToStarter);
                  
                  return (
                    <Grid item xs={12} md={4} key={planName}>
                      <PlanCard
                        plan={planName}
                        isCurrentPlan={isCurrentPlan}
                        onUpgrade={() => {
                          if (shouldBlockPlanChanges) {
                            showInfo('A plan change is already scheduled. Please contact support to modify it.');
                            return;
                          }
                          openCheckoutForPlan(planName);
                        }}
                        onDowngrade={
                          canShowDowngradeButton
                            ? () => {
                                // Starter supports max 2 active users.
                                // Avoid calling schedule-downgrade when it would be rejected by backend;
                                // show the same message the backend returns.
                                if (planName === 'starter' && (billingSummary?.user_count ?? 0) > 2) {
                                  showError(
                                    `Cannot convert to Starter plan. You have ${billingSummary?.user_count} active users, but Starter supports only 2. Please reduce users first or select another plan.`
                                  );
                                  return;
                                }

                                handleDowngrade(planName as 'starter' | 'team');
                              }
                            : undefined
                        }
                        canDowngrade={canShowDowngradeButton}
                        hasPendingDowngrade={hasPendingDowngrade}
                        downgradeEffectiveDate={hasPendingDowngrade ? billingSummary?.pending_downgrade?.effective_at : null}
                      />
                    </Grid>
                  );
                })}
              </Grid>
            </Grid>

            {/* Addons Section - Visible for all plans with appropriate states */}
            <Grid item xs={12}>
              <Typography variant="h6" sx={{ mb: 2, fontWeight: 600 }}>
                Add-ons
              </Typography>
              
              {/* Enterprise/Trial: Show info message */}
              {/* BILLING RULE: Enterprise and trial_enterprise include add-ons; toggles are informational only. */}
              {(currentPlan === 'enterprise' || currentPlan === 'trial_enterprise') ? (
                <Card>
                  <CardContent>
                    <Typography variant="body1" color="text.secondary">
                      All add-ons are already included in your Enterprise plan.
                    </Typography>
                  </CardContent>
                </Card>
              ) : (
                <Grid container spacing={2}>
                  {/* Show both Planning and AI for Starter and Team */}
                  <Grid item xs={12} md={6}>
                    <AddonToggle
                      addon="planning"
                      enabled={planningFeatureEnabled}
                      onToggle={() => handleToggleAddon('planning')}
                      disabled={currentPlan === 'starter'}
                    />
                  </Grid>
                  <Grid item xs={12} md={6}>
                    <AddonToggle
                      addon="ai"
                      // BILLING RULE: This reflects paid entitlement state (billingSummary.addons.ai), not tenant preference.
                      enabled={billingSummary.addons.ai > 0}
                      onToggle={() => handleToggleAddon('ai')}
                      disabled={currentPlan === 'starter'}
                    />
                  </Grid>
                </Grid>
              )}
            </Grid>

            {/* Tenant Settings */}
            <Grid item xs={12}>
              <Typography variant="h6" sx={{ mb: 2, fontWeight: 600 }}>
                Tenant Settings
              </Typography>
              <Card>
                <CardContent>
                  {/* IMPORTANT: Tenant AI toggle is separate from billing add-on purchase (entitlement vs preference). */}
                  <Box
                    sx={{
                      display: 'flex',
                      flexDirection: { xs: 'column', sm: 'row' },
                      justifyContent: 'space-between',
                      alignItems: { xs: 'flex-start', sm: 'center' },
                      gap: 2,
                    }}
                  >
                    <Box>
                      <Typography variant="subtitle1" sx={{ fontWeight: 600 }}>
                        AI Suggestions
                      </Typography>
                      <Typography variant="body2" color="text.secondary">
                        Enable or disable AI-powered planning suggestions for this tenant.
                      </Typography>
                      <Typography
                        variant="caption"
                        color={aiEntitled ? 'text.secondary' : 'warning.main'}
                        sx={{ mt: 1, display: 'block', fontWeight: 500 }}
                      >
                        Requires AI add-on in billing plan
                      </Typography>
                    </Box>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      {tenantAiEnabled && (
                        <Chip label="Active" size="small" color="secondary" sx={{ fontWeight: 600 }} />
                      )}
                      {aiEntitled ? (
                        <Switch
                          checked={tenantAiEnabled}
                          onChange={(event) => handleTenantAiToggle(event.target.checked)}
                          disabled={updatingTenantAi}
                          color="secondary"
                        />
                      ) : (
                        <Tooltip title="Purchase the AI add-on in Billing to unlock this toggle." arrow>
                          <Box>
                            <Switch checked={false} disabled color="secondary" />
                          </Box>
                        </Tooltip>
                      )}
                    </Box>
                  </Box>
                </CardContent>
              </Card>
            </Grid>

            {/* Pricing Summary */}
            <Grid item xs={12}>
              <PricingSummary
                // BILLING SEMANTICS: baseSubtotal/total are computed server-side; frontend must not recalculate pricing.
                // DISPLAY ONLY: userCount may differ from userLimit (billable licenses).
                baseSubtotal={billingSummary.base_subtotal}
                total={billingSummary.total}
                userLimit={billingSummary.user_limit}
                userCount={billingSummary.user_count}
                pricePerUser={billingSummary.total > 0 ? billingSummary.base_subtotal / (billingSummary.user_limit || 1) : 0}
                addons={billingSummary.addons}
                nextRenewalAt={billingSummary.subscription?.next_renewal_at}
                isTrial={billingSummary.is_trial}
                trialEndsAt={billingSummary.trial?.ends_at}
              />
            </Grid>
          </Grid>
        </Container>
      </Box>

      {/* Checkout Modal */}
      <CheckoutModal />

      {/* Downgrade Confirmation Dialog (for paid plan downgrades) */}
      <ConfirmDowngradeDialog
        open={confirmDialogOpen}
        onClose={handleDialogClose}
        onConfirm={confirmDowngrade}
        currentPlan={billingSummary?.plan || ''}
        targetPlan={targetDowngradePlan || ''}
        effectiveDate={billingSummary?.subscription?.next_renewal_at || null}
      />

      {/* Trial Exit Confirmation Dialog (for trial → paid conversions) */}
      <ConfirmTrialExitDialog
        open={trialExitDialogOpen}
        onClose={handleTrialExitDialogClose}
        onConfirm={confirmTrialExit}
        targetPlan={targetTrialExitPlan || ''}
        trialEndsAt={billingSummary?.trial?.ends_at || null}
      />

      {/* License Increase Dialog */}
      <Dialog open={licenseDialogOpen} onClose={() => setLicenseDialogOpen(false)} maxWidth="sm" fullWidth>
        <DialogTitle>Increase Licenses</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Add more licenses to your {billingSummary?.plan} plan.
          </Typography>
          <Typography variant="body2" sx={{ mb: 2 }}>
            Current limit: <strong>{billingSummary ? getEffectiveLicenseLimit(billingSummary) : 0} users</strong>
          </Typography>
          {/* BILLING RULE: Input clamps to plan max to keep checkout requests valid (no server-side assumptions here). */}
          <TextField
            autoFocus
            margin="dense"
            label="Number of licenses to add"
            type="number"
            fullWidth
            variant="outlined"
            value={licenseIncrement}
            onChange={(e) => {
              if (!billingSummary) return;
              const effectiveLimit = getEffectiveLicenseLimit(billingSummary);
              const maxLimit = getMaxLicenseLimitForPlan(billingSummary.plan);
              const maxIncrement = Math.max(1, maxLimit - effectiveLimit);
              setLicenseIncrement(
                Math.max(1, Math.min(maxIncrement, parseInt(e.target.value) || 1))
              );
            }}
            inputProps={{
              min: 1,
              max: billingSummary ? (() => {
                const effectiveLimit = getEffectiveLicenseLimit(billingSummary);
                const maxLimit = getMaxLicenseLimitForPlan(billingSummary.plan);
                return Math.max(1, maxLimit - effectiveLimit);
              })() : 1,
            }}
            helperText={billingSummary ? (() => {
              const effectiveLimit = getEffectiveLicenseLimit(billingSummary);
              const maxLimit = getMaxLicenseLimitForPlan(billingSummary.plan);
              return `New limit will be: ${effectiveLimit + licenseIncrement} licenses (max ${maxLimit})`;
            })() : ''}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setLicenseDialogOpen(false)}>Cancel</Button>
          <Button
            onClick={async () => {
              if (!billingSummary) return;
              const effectiveLimit = getEffectiveLicenseLimit(billingSummary);
              const maxLimit = getMaxLicenseLimitForPlan(billingSummary.plan);
              const newUserLimit = Math.min(maxLimit, effectiveLimit + licenseIncrement);

              // Enterprise Trial: license increments are free and must never trigger checkout.
              // Checkout is ONLY for paid plans.
              if (billingSummary.plan === 'trial_enterprise' || (billingSummary.plan === 'enterprise' && billingSummary.is_trial)) {
                try {
                  await api.post('/api/billing/licenses/increase', {
                    increment: licenseIncrement,
                  });

                  setLicenseDialogOpen(false);
                  setLicenseIncrement(1);
                  await refreshSummary();
                  return;
                } catch (err: any) {
                  const message = err?.response?.data?.message || 'Failed to increase licenses.';
                  showError(message);
                  return;
                }
              }
              
              // IMPORTANT: Licenses checkout uses NEW TOTAL user_limit (billable seats), not an increment amount.
              setLicenseDialogOpen(false);
              setLicenseIncrement(1);
              openCheckoutForLicenses(newUserLimit);
            }}
            variant="contained"
          >
            Continue to Checkout
          </Button>
        </DialogActions>
      </Dialog>
    </Box>
  );
};

export default BillingPage;
