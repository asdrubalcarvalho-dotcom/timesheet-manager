import dayjs from 'dayjs';
import type { Timesheet } from '../types';

export type CaDailyOt2Candidate = {
  date: string; // YYYY-MM-DD
  ot2Hours: number;
};

/**
 * FRONTEND-ONLY UI HEURISTIC (read-only):
 *
 * We do NOT have per-day OT(2.0x) breakdown from GET /api/timesheets/summary.
 * So, for US-CA tenants only, we derive *candidate* OT(2.0x) days using only
 * timesheets already loaded by the UI (GET /api/timesheets):
 * - Group by technician + day (YYYY-MM-DD)
 * - Sum hours_worked
 * - Flag days where total > 12, with ot2Hours = max(0, total - 12)
 *
 * This is NOT used for payroll/billing totals and does NOT change engine logic.
 */
export function computeCaDailyOt2Candidates(
  entries: Array<Pick<Timesheet, 'date' | 'hours_worked' | 'technician_id'>>,
  weekStartDate: string
): CaDailyOt2Candidate[] {
  if (!Array.isArray(entries) || entries.length === 0) return [];
  if (!weekStartDate || !dayjs(weekStartDate, 'YYYY-MM-DD', true).isValid()) return [];

  const start = dayjs(weekStartDate, 'YYYY-MM-DD');
  const endExclusive = start.add(7, 'day');

  // Sum total hours per technician/day.
  const perTechDay = new Map<string, { date: string; totalHours: number }>();

  for (const entry of entries) {
    const date = typeof entry.date === 'string' ? entry.date : '';
    if (!date) continue;

    const dateObj = dayjs(date, 'YYYY-MM-DD', true);
    if (!dateObj.isValid()) continue;
    if (dateObj.isBefore(start) || !dateObj.isBefore(endExclusive)) continue;

    const technicianId = Number(entry.technician_id);
    if (!Number.isFinite(technicianId)) continue;

    const hours = typeof entry.hours_worked === 'number' ? entry.hours_worked : Number(entry.hours_worked);
    const normalizedHours = Number.isFinite(hours) ? hours : 0;

    const key = `${technicianId}-${dateObj.format('YYYY-MM-DD')}`;
    const existing = perTechDay.get(key);

    if (existing) {
      existing.totalHours += normalizedHours;
    } else {
      perTechDay.set(key, { date: dateObj.format('YYYY-MM-DD'), totalHours: normalizedHours });
    }
  }

  // Aggregate OT2 candidates by date (sum across technicians).
  const perDateOt2 = new Map<string, number>();

  perTechDay.forEach(({ date, totalHours }) => {
    const ot2 = Math.max(0, totalHours - 12);
    if (ot2 <= 0) return;

    perDateOt2.set(date, (perDateOt2.get(date) ?? 0) + ot2);
  });

  return Array.from(perDateOt2.entries())
    .map(([date, ot2Hours]) => ({
      date,
      ot2Hours: Math.round(ot2Hours * 100) / 100,
    }))
    .sort((a, b) => a.date.localeCompare(b.date));
}
