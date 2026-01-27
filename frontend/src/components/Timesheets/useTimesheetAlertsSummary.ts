import { useMemo } from 'react';

export type TimesheetAlertsSummary = {
  aiAlertsCount: number;
  insightsAlertsCount: number;
};

type Params = {
  aiAlertsCount: number;
  hasPolicyAlert: boolean;
  hasCaOt2Alert: boolean;
};

export const useTimesheetAlertsSummary = ({ aiAlertsCount, hasPolicyAlert, hasCaOt2Alert }: Params): TimesheetAlertsSummary => {
  return useMemo(() => {
    const normalizedAi = Number.isFinite(aiAlertsCount) ? aiAlertsCount : 0;

    let insightsCount = 0;
    if (hasPolicyAlert) insightsCount += 1;
    if (hasCaOt2Alert) insightsCount += 1;

    return {
      aiAlertsCount: normalizedAi,
      insightsAlertsCount: insightsCount,
    };
  }, [aiAlertsCount, hasPolicyAlert, hasCaOt2Alert]);
};
