import { describe, it, expect } from 'vitest';
import { getVisibleTimesheets } from './getVisibleTimesheets';

describe('getVisibleTimesheets', () => {
  it('includes items when permissions.can_view is undefined/true and excludes when false', () => {
    const allTimesheets = [
      { id: 1, permissions: undefined },
      { id: 2, permissions: { can_view: true } },
      { id: 3, permissions: { can_view: false } },
      { id: 4 },
    ];

    const visible = getVisibleTimesheets(allTimesheets as any);

    expect(visible.map((t: any) => t.id)).toEqual([1, 2, 4]);
  });
});
