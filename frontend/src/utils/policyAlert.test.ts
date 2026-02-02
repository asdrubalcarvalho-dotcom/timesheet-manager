import { describe, it, expect } from 'vitest';
import { getPolicyAlertModel, POLICY_ALERT_STRINGS } from './policyAlert';
import type { TenantContext } from '../types/tenant';

describe('getPolicyAlertModel', () => {
  it('returns warning when US region has no state', () => {
    const tenantContext: TenantContext = {
      region: 'US',
        state: undefined,
      policy_key: 'US-FLSA',
      timezone: 'America/Los_Angeles',
      locale: 'en-US',
      date_format: 'MM/DD/YYYY',
      currency: 'USD',
      currency_symbol: '$',
    };

    const model = getPolicyAlertModel(tenantContext);
    expect(model).not.toBeNull();
    expect(model?.severity).toBe('warning');
    expect(model?.cta?.label).toBe(POLICY_ALERT_STRINGS.ctaLabel);
  });

  it('returns info when US region has CA state', () => {
    const tenantContext: TenantContext = {
      region: 'US',
      state: 'CA',
      policy_key: 'US-CA',
      timezone: 'America/Los_Angeles',
      locale: 'en-US',
      date_format: 'MM/DD/YYYY',
      currency: 'USD',
      currency_symbol: '$',
    };

    const model = getPolicyAlertModel(tenantContext);
    expect(model).not.toBeNull();
    expect(model?.severity).toBe('info');
    expect(model?.message).toContain('US-CA');
  });

  it('returns null for non-US region', () => {
    const tenantContext: TenantContext = {
      region: 'EU',
      state: undefined,
      policy_key: 'NON-US',
      timezone: 'Europe/Lisbon',
      locale: 'pt-PT',
      date_format: 'DD/MM/YYYY',
      currency: 'EUR',
      currency_symbol: '€',
    };

    expect(getPolicyAlertModel(tenantContext)).toBeNull();
  });
});
