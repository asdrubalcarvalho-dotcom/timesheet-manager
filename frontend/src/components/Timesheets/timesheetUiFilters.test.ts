import { describe, it, expect } from 'vitest';
import { applyTimesheetUiFilters } from './timesheetUiFilters';
import { getVisibleTimesheets } from '../../utils/getVisibleTimesheets';
import { computeCaDailyOt2Candidates } from '../../utils/computeCaDailyOt2Candidates';

describe('timesheet dataset scoping', () => {
  it('applies user filters on top of policy-visible timesheets (policy dataset unchanged)', () => {
    const allTimesheets = [
      { id: 1, technician_id: 10, date: '2026-01-20', hours_worked: 8, ai_flagged: true, permissions: { can_view: true } },
      { id: 2, technician_id: 10, date: '2026-01-20', hours_worked: 5, ai_flagged: false, permissions: { can_view: true } },
      { id: 3, technician_id: 11, date: '2026-01-21', hours_worked: 4, ai_flagged: false, permissions: { can_view: false } },
    ];

    const policyVisible = getVisibleTimesheets(allTimesheets as any);
    expect(policyVisible.map((t: any) => t.id)).toEqual([1, 2]);

    const uiFiltered = applyTimesheetUiFilters(policyVisible as any, {
      scope: 'all',
      validationFilter: 'ai_flagged',
      overCapIds: new Set<number>(),
      isOwnedByUser: () => false,
    });

    expect(uiFiltered.map((t: any) => t.id)).toEqual([1]);

    // Ensure we did not mutate the policy-visible dataset.
    expect(policyVisible.map((t: any) => t.id)).toEqual([1, 2]);
  });

  it('alerts must be computed from policyVisibleTimesheets and not change when UI filters hide the triggering entry', () => {
    const allTimesheets = [
      // Triggers CA OT2 candidate (13h total).
      { id: 10, technician_id: 10, date: '2026-01-20', hours_worked: 13, ai_flagged: false, permissions: { can_view: true } },
    ];

    const weekStartDate = '2026-01-19';

    const policyVisible = getVisibleTimesheets(allTimesheets as any);
    const uiFiltered = applyTimesheetUiFilters(policyVisible as any, {
      scope: 'all',
      validationFilter: 'ai_flagged', // hides the entry
      overCapIds: new Set<number>(),
      isOwnedByUser: () => false,
    });

    const policyCandidates = computeCaDailyOt2Candidates(policyVisible as any, weekStartDate);
    const uiCandidates = computeCaDailyOt2Candidates(uiFiltered as any, weekStartDate);

    expect(policyCandidates.length).toBe(1);
    expect(uiCandidates).toEqual([]);
  });
});
