import { describe, it, expect } from 'vitest';
import { getTrialRemainingLabel } from '../getTrialRemainingLabel';

describe('getTrialRemainingLabel (Option C)', () => {
  const now = '2026-01-01T00:00:00.000Z';

  it('returns "2 days left" at exactly 48h', () => {
    const trialEndsAt = '2026-01-03T00:00:00.000Z'; // +48h
    expect(getTrialRemainingLabel(trialEndsAt, now)).toBe('2 days left');
  });

  it('returns "Ends tomorrow" at exactly 24h', () => {
    const trialEndsAt = '2026-01-02T00:00:00.000Z'; // +24h
    expect(getTrialRemainingLabel(trialEndsAt, now)).toBe('Ends tomorrow');
  });

  it('returns "Ends today" at 23h59m', () => {
    const trialEndsAt = '2026-01-01T23:59:00.000Z';
    expect(getTrialRemainingLabel(trialEndsAt, now)).toBe('Ends today');
  });

  it('returns "Trial expired" when expired by 1 second', () => {
    const trialEndsAt = '2025-12-31T23:59:59.000Z';
    expect(getTrialRemainingLabel(trialEndsAt, now)).toBe('Trial expired');
  });
});
