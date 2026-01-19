import { describe, expect, it } from 'vitest';
import { weekStartToFirstDay } from './weekStartToFirstDay';

describe('weekStartToFirstDay', () => {
  it('maps sunday to 0', () => {
    expect(weekStartToFirstDay('sunday')).toBe(0);
    expect(weekStartToFirstDay('Sunday')).toBe(0);
    expect(weekStartToFirstDay('  sunday  ')).toBe(0);
  });

  it('maps monday to 1', () => {
    expect(weekStartToFirstDay('monday')).toBe(1);
    expect(weekStartToFirstDay('Monday')).toBe(1);
    expect(weekStartToFirstDay('  monday  ')).toBe(1);
  });

  it('defaults to Monday-start (1) for unknown/missing values', () => {
    expect(weekStartToFirstDay(undefined)).toBe(1);
    expect(weekStartToFirstDay(null)).toBe(1);
    expect(weekStartToFirstDay('')).toBe(1);
    expect(weekStartToFirstDay('tuesday')).toBe(1);
  });
});
