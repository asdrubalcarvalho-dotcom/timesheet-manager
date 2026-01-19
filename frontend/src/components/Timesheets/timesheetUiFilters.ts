import type { Timesheet } from '../../types';

export type TimesheetScope = 'mine' | 'others' | 'all';
export type TimesheetValidationFilter = 'all' | 'ai_flagged' | 'overcap';

export type ApplyTimesheetUiFiltersOptions = {
  scope: TimesheetScope;
  validationFilter: TimesheetValidationFilter;
  overCapIds: ReadonlySet<number>;
  isOwnedByUser: (timesheet: Timesheet) => boolean;
};

/**
 * Applies user-driven filters/scope for calendar rendering.
 *
 * IMPORTANT: This must be applied AFTER permission scoping.
 * Permission scoping is handled by getVisibleTimesheets() using backend-provided permissions.can_view.
 */
export function applyTimesheetUiFilters(
  policyVisibleTimesheets: Timesheet[],
  options: ApplyTimesheetUiFiltersOptions
): Timesheet[] {
  if (!Array.isArray(policyVisibleTimesheets) || policyVisibleTimesheets.length === 0) {
    return [];
  }

  const { scope, validationFilter, overCapIds, isOwnedByUser } = options;

  let scoped = policyVisibleTimesheets;

  if (scope === 'mine') {
    scoped = scoped.filter((ts) => isOwnedByUser(ts));
  } else if (scope === 'others') {
    scoped = scoped.filter((ts) => !isOwnedByUser(ts));
  }

  if (validationFilter === 'ai_flagged') {
    scoped = scoped.filter((ts) => Boolean(ts.ai_flagged));
  } else if (validationFilter === 'overcap') {
    scoped = scoped.filter((ts) => overCapIds.has(ts.id));
  }

  return scoped;
}
