import { describe, it, expect } from 'vitest';
import { computeCaDailyOt2Candidates } from './computeCaDailyOt2Candidates';
import { getVisibleTimesheets } from './getVisibleTimesheets';

describe('computeCaDailyOt2Candidates', () => {
  it('returns one candidate day when a technician logs 13h total (OT2 = 1h)', () => {
    const entries = [
      { technician_id: 10, date: '2026-01-20', hours_worked: 8 },
      { technician_id: 10, date: '2026-01-20', hours_worked: 5 },
      { technician_id: 10, date: '2026-01-21', hours_worked: 12 },
    ];

    const result = computeCaDailyOt2Candidates(entries as any, '2026-01-19');

    expect(result).toEqual([{ date: '2026-01-20', ot2Hours: 1 }]);
  });

  it('returns empty when daily total is 12h or less', () => {
    const entries = [
      { technician_id: 10, date: '2026-01-20', hours_worked: 12 },
      { technician_id: 10, date: '2026-01-21', hours_worked: 11.99 },
    ];

    expect(computeCaDailyOt2Candidates(entries as any, '2026-01-19')).toEqual([]);
  });

  it('aggregates OT2 per date across multiple technicians', () => {
    const entries = [
      { technician_id: 10, date: '2026-01-20', hours_worked: 13 }, // +1
      { technician_id: 11, date: '2026-01-20', hours_worked: 14 }, // +2
      { technician_id: 11, date: '2026-01-22', hours_worked: 13 }, // +1
    ];

    const result = computeCaDailyOt2Candidates(entries as any, '2026-01-19');

    expect(result).toEqual([
      { date: '2026-01-20', ot2Hours: 3 },
      { date: '2026-01-22', ot2Hours: 1 },
    ]);
  });

  it('returns no candidates when the only >12h entry is non-viewable (permissions.can_view=false)', () => {
    const allTimesheets = [
      { technician_id: 10, date: '2026-01-20', hours_worked: 13, permissions: { can_view: false } },
    ];

    const visibleTimesheets = getVisibleTimesheets(allTimesheets as any);
    const result = computeCaDailyOt2Candidates(visibleTimesheets as any, '2026-01-19');

    expect(result).toEqual([]);
  });

  it('uses the provided weekStartDate as the workweek window (7 days, inclusive of start+6, excludes start+7)', () => {
    // Workweek starts on Sunday.
    const weekStart = '2026-01-18';

    const entries = [
      { technician_id: 10, date: '2026-01-24', hours_worked: 13 }, // Saturday (start+6) => include
      { technician_id: 10, date: '2026-01-25', hours_worked: 13 }, // next Sunday (start+7) => exclude
    ];

    const result = computeCaDailyOt2Candidates(entries as any, weekStart);

    expect(result).toEqual([{ date: '2026-01-24', ot2Hours: 1 }]);
  });
});
