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
import PlanCard from './PlanCard';
import AddonToggle from './AddonToggle';
import PricingSummary from './PricingSummary';
import CheckoutModal from './CheckoutModal';
import { ConfirmDowngradeDialog } from './ConfirmDowngradeDialog';
import { ConfirmTrialExitDialog } from './ConfirmTrialExitDialog';

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
  features: { timesheets: boolean; expenses: boolean; travels: boolean; planning: boolean; ai: boolean }
): { included: string; addons: string } {
  const { timesheets, expenses, travels, planning, ai } = features;
  
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
    loading, 
    error, 
    openCheckoutForPlan,
    openCheckoutForAddon,
    openCheckoutForLicenses,
    toggleAddon,
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

  console.log('[BillingPage] 🎨 RENDER - billingSummary:', billingSummary, 'loading:', loading);

  // CRITICAL: Refresh billing summary on every page render
  // This ensures user_count is ALWAYS fresh (e.g., after deleting users)
  useEffect(() => {
    console.log('[BillingPage] 🔄 Refreshing billing summary on mount/navigation');
    refreshSummary();
    // eslint-disable-next-line react-hooks/exhaustive-deps
  }, []); // Empty deps = run only on mount

  // Determine effective user limit (trial has no limit, so use user_count)
  const effectiveUserLimit =
    billingSummary?.is_trial && (billingSummary?.user_limit === null || billingSummary?.user_limit === undefined)
      ? billingSummary?.user_count
      : billingSummary?.user_limit;

  const showUserLimit =
    typeof effectiveUserLimit === 'number' && effectiveUserLimit > 0;

  // Handle addon toggle with plan-specific rules (PHASE 3 - Frontend validation)
  const handleToggleAddon = async (addon: 'planning' | 'ai') => {
    if (!billingSummary) return;

    // Starter: No add-ons allowed
    if (billingSummary.plan === 'starter') {
      showWarning(
        "Add-ons are not available on the Starter plan. Upgrade to Team or Enterprise to enable them."
      );
      return; // IMPORTANT: do not call API
    }

    // Enterprise/Trial: All features included, no add-ons needed
    if (billingSummary.plan === 'enterprise' || billingSummary.plan === 'trial_enterprise') {
      showInfo("All features are already included in your current plan.");
      return; // IMPORTANT: do not call API
    }

    // Team: Check if addon is currently enabled
    const isCurrentlyEnabled = addon === 'planning' 
      ? billingSummary.features?.planning 
      : billingSummary.features?.ai;

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

  // Handle plan downgrade (show confirmation dialog first)
  const handleDowngrade = async (plan: 'starter' | 'team') => {
    if (!billingSummary) return;

    // SPECIAL CASE: Trial → Paid Plan (immediate conversion)
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
      // Show success message for scheduled downgrade
      showInfo('Downgrade scheduled for next billing cycle.');
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

  if (loading) {
    console.log('[BillingPage] ⏳ Loading state - showing spinner');
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
  const features = billingSummary.features || {};

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
          {billingSummary.pending_downgrade && !billingSummary.is_trial && (
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
                  <Box sx={{ display: 'flex', flexDirection: 'column', gap: 1 }}>
                    <Box sx={{ display: 'flex', alignItems: 'center', gap: 1 }}>
                      <UsersIcon color="primary" />
                      <Box>
                        <Typography variant="caption" color="text.secondary">
                          Users
                        </Typography>
                        <Typography variant="h6" sx={{ fontWeight: 600 }}>
                          {showUserLimit
                            ? `${billingSummary.user_count} / ${effectiveUserLimit}`
                            : `${billingSummary.user_count} users`}
                        </Typography>
                      </Box>
                    </Box>
                    {(billingSummary.plan === 'team' || billingSummary.plan === 'enterprise') && (
                      <Button
                        size="small"
                        variant="text"
                        onClick={() => setLicenseDialogOpen(true)}
                        sx={{ textTransform: 'none', alignSelf: 'flex-start' }}
                      >
                        + Increase Licenses
                      </Button>
                    )}
                  </Box>
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
                  const isCurrentPlan = planName === currentPlan;
                  
                  // Determine if this is a downgrade (current plan is higher tier)
                  // For Trial, treat Team/Enterprise as UPGRADE (higher tier than trial in terms of features, even though both are level 3)
                  const planHierarchy = { starter: 1, team: 2, enterprise: 3, trial_enterprise: 3 };
                  const currentLevel = planHierarchy[currentPlan as keyof typeof planHierarchy];
                  const targetLevel = planHierarchy[planName];
                  const isDowngrade = targetLevel < currentLevel;
                  
                  // SPECIAL CASE: Trial → Team is actually a downgrade in tier (3→2) BUT it's an UPGRADE in terms of payment
                  // So we need to handle it via the downgrade dialog, which will then call the correct handler
                  const isTrialToLowerTier = billingSummary?.is_trial && (planName === 'starter' || planName === 'team');
                  
                  // Check if there's a pending downgrade to this plan
                  const hasPendingDowngrade = billingSummary?.pending_downgrade?.target_plan === planName;
                  
                  // Only allow downgrade to starter or team (not enterprise)
                  const canActuallyDowngrade = (isDowngrade || isTrialToLowerTier) && (planName === 'starter' || planName === 'team');
                  
                  return (
                    <Grid item xs={12} md={4} key={planName}>
                      <PlanCard
                        plan={planName}
                        isCurrentPlan={isCurrentPlan}
                        onUpgrade={isCurrentPlan ? () => {} : () => openCheckoutForPlan(planName)}
                        onDowngrade={canActuallyDowngrade ? () => handleDowngrade(planName as 'starter' | 'team') : undefined}
                        canDowngrade={canActuallyDowngrade}
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
                      enabled={features.planning || false}
                      onToggle={() => handleToggleAddon('planning')}
                      disabled={currentPlan === 'starter'}
                    />
                  </Grid>
                  <Grid item xs={12} md={6}>
                    <AddonToggle
                      addon="ai"
                      enabled={features.ai || false}
                      onToggle={() => handleToggleAddon('ai')}
                      disabled={currentPlan === 'starter'}
                    />
                  </Grid>
                </Grid>
              )}
            </Grid>

            {/* Pricing Summary */}
            <Grid item xs={12}>
              <PricingSummary
                baseSubtotal={billingSummary.base_subtotal}
                total={billingSummary.total}
                userLimit={billingSummary.user_limit}
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
        <DialogTitle>Increase User Licenses</DialogTitle>
        <DialogContent>
          <Typography variant="body2" color="text.secondary" sx={{ mb: 2 }}>
            Add more user licenses to your {billingSummary?.plan} plan.
          </Typography>
          <Typography variant="body2" sx={{ mb: 2 }}>
            Current limit: <strong>{billingSummary?.user_limit} users</strong>
          </Typography>
          <TextField
            autoFocus
            margin="dense"
            label="Number of licenses to add"
            type="number"
            fullWidth
            variant="outlined"
            value={licenseIncrement}
            onChange={(e) => setLicenseIncrement(Math.max(1, Math.min(100, parseInt(e.target.value) || 1)))}
            inputProps={{ min: 1, max: 100 }}
            helperText={`New limit will be: ${(billingSummary?.user_limit || 0) + licenseIncrement} users`}
          />
        </DialogContent>
        <DialogActions>
          <Button onClick={() => setLicenseDialogOpen(false)}>Cancel</Button>
          <Button
            onClick={() => {
              // Calculate new user_limit
              const newUserLimit = (billingSummary?.user_limit || 0) + licenseIncrement;
              
              // Close license dialog
              setLicenseDialogOpen(false);
              setLicenseIncrement(1);
              
              // Open checkout modal for licenses mode (NOT plan mode)
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
