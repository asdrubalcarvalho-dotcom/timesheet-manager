export const weekStartToFirstDay = (weekStart?: string | null): 0 | 1 => {
  const raw = (weekStart ?? '').toString().trim().toLowerCase();

  if (raw === 'sunday') return 0;
  if (raw === 'monday') return 1;

  // Preserve current UI default (Monday-start) unless explicitly set.
  return 1;
};
