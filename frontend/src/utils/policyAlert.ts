import type { TenantContext } from '../components/Auth/AuthContext';

export type PolicyAlertModel = {
  severity: 'warning' | 'info';
  title: string;
  message: string;
  cta?: {
    label: string;
    to: string;
  };
};

export const POLICY_ALERT_STRINGS = {
  warningTitle: 'Policy Alert',
  warningMessage:
    'Your tenant is set to US region, but no state is configured. Set the state to apply the correct overtime policy.',
  ctaLabel: 'Set State',
  ctaTo: '/billing',
  infoTitle: 'Policy',
  infoMessagePrefix: 'Policy active:',
} as const;

const normalizeUpper = (value: unknown): string => {
  if (typeof value !== 'string') return '';
  return value.trim().toUpperCase();
};

const derivePolicyKey = (region: string, state: string): string | null => {
  if (region !== 'US') return null;
  if (state === 'CA') return 'US-CA';
  if (state === 'NY') return 'US-NY';
  return 'US-FLSA';
};

export const getPolicyAlertModel = (tenantContext: TenantContext | null): PolicyAlertModel | null => {
  if (!tenantContext) return null;

  const region = normalizeUpper(tenantContext.region);
  if (region !== 'US') return null;

  const state = normalizeUpper(tenantContext.state);

  if (state === '') {
    return {
      severity: 'warning',
      title: POLICY_ALERT_STRINGS.warningTitle,
      message: POLICY_ALERT_STRINGS.warningMessage,
      cta: {
        label: POLICY_ALERT_STRINGS.ctaLabel,
        to: POLICY_ALERT_STRINGS.ctaTo,
      },
    };
  }

  const policyKey =
    typeof tenantContext.policy_key === 'string' && tenantContext.policy_key.trim() !== ''
      ? tenantContext.policy_key
      : derivePolicyKey(region, state);

  if (!policyKey) return null;

  return {
    severity: 'info',
    title: POLICY_ALERT_STRINGS.infoTitle,
    message: `${POLICY_ALERT_STRINGS.infoMessagePrefix} ${policyKey}`,
  };
};
