const MS_PER_HOUR = 60 * 60 * 1000;
const MS_PER_DAY = 24 * MS_PER_HOUR;

function toUtcMs(value: Date | string): number {
  if (value instanceof Date) {
    return value.getTime();
  }

  const parsed = Date.parse(value);
  if (!Number.isFinite(parsed)) {
    throw new Error('Invalid timestamp');
  }
  return parsed;
}

/**
 * Option C: Human-friendly trial remaining label.
 *
 * Rules derive solely from the absolute `trialEndsAt` timestamp.
 */
export function getTrialRemainingLabel(trialEndsAt: string, now: Date | string): string {
  const trialEndsAtMs = toUtcMs(trialEndsAt);
  const nowMs = toUtcMs(now);

  const diffMs = trialEndsAtMs - nowMs;

  if (diffMs <= 0) return 'Trial expired';
  if (diffMs < MS_PER_DAY) return 'Ends today';
  if (diffMs < 2 * MS_PER_DAY) return 'Ends tomorrow';

  const daysLeft = Math.ceil(diffMs / MS_PER_DAY);
  return `${daysLeft} days left`;
}
