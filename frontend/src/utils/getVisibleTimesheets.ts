import type { Timesheet } from '../types';

/**
 * UI visibility source of truth: backend-provided permissions.can_view.
 *
 * Rule:
 * - If permissions.can_view === false => exclude.
 * - Otherwise (true/undefined/missing permissions) => include.
 */
export function getVisibleTimesheets(allTimesheets: Timesheet[]): Timesheet[] {
  if (!Array.isArray(allTimesheets)) {
    return [];
  }

  return allTimesheets.filter((timesheet) => timesheet?.permissions?.can_view !== false);
}
