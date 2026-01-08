import type { BillingSummary } from '../../types/billing';

export type TenantAiState = 'enabled' | 'disabled_by_tenant' | 'available_as_addon' | 'not_available';

type AiFeatureObject = {
  enabled: boolean;
  entitled?: boolean;
  toggle?: boolean;
};

const getAiFeatureObject = (billingSummary: BillingSummary | null): AiFeatureObject | null => {
  const ai = billingSummary?.features?.ai as unknown;

  if (typeof ai === 'object' && ai !== null && 'enabled' in ai) {
    const obj = ai as any;
    return {
      enabled: Boolean(obj.enabled),
      entitled: typeof obj.entitled === 'boolean' ? obj.entitled : undefined,
      toggle: typeof obj.toggle === 'boolean' ? obj.toggle : undefined,
    };
  }

  return null;
};

const inferAiEntitled = (billingSummary: BillingSummary | null): boolean => {
  const plan = billingSummary?.plan;
  const aiFeatureObject = getAiFeatureObject(billingSummary);

  if (typeof aiFeatureObject?.entitled === 'boolean') return aiFeatureObject.entitled;
  if (typeof billingSummary?.entitlements?.ai === 'boolean') return Boolean(billingSummary.entitlements.ai);

  if (plan === 'enterprise' || plan === 'trial_enterprise') return true;
  if (plan === 'team') return (billingSummary?.addons?.ai ?? 0) > 0;
  return false;
};

const inferAiEnabled = (billingSummary: BillingSummary | null, tenantAiEnabled: boolean): boolean => {
  const plan = billingSummary?.plan;
  const aiFeature = billingSummary?.features?.ai as unknown;
  const aiFeatureObject = getAiFeatureObject(billingSummary);

  // Backend may return a fully-resolved enabled flag.
  if (typeof aiFeature === 'boolean') return aiFeature;
  if (aiFeatureObject) return Boolean(aiFeatureObject.enabled);

  // Fallback: treat enterprise/trial enterprise as entitled; use tenant toggle.
  const entitled = inferAiEntitled(billingSummary);
  if (!entitled) return false;
  if (plan === 'enterprise' || plan === 'trial_enterprise') return tenantAiEnabled;
  return tenantAiEnabled;
};

export const getTenantAiState = (
  billingSummary: BillingSummary | null,
  tenantAiEnabled: boolean
): TenantAiState => {
  const plan = billingSummary?.plan;
  const entitled = inferAiEntitled(billingSummary);
  const enabled = inferAiEnabled(billingSummary, tenantAiEnabled);

  if (enabled) return 'enabled';
  if (entitled) return 'disabled_by_tenant';

  if (plan === 'starter') return 'not_available';
  // Team: AI is available as an add-on (not included by default).
  return 'available_as_addon';
};
