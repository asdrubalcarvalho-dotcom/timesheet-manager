/**
 * calculateBillingSummary.test.ts
 * 
 * Unit tests for billing calculation logic.
 * 
 * @group billing
 * @group price-calculation
 */

import { calculateBillingSummary } from '../calculateBillingSummary';

describe('calculateBillingSummary', () => {
  /**
   * Test Case A: Team plan, 2 users, NO add-ons
   * 
   * Expected:
   * - base = 44 × 2 = 88.00
   * - addons_total = 0
   * - total = 88.00
   */
  it('should calculate correctly with no add-ons', () => {
    const result = calculateBillingSummary({
      basePerUser: 44,
      userCount: 2,
      addons: {
        planning: false,
        ai: false,
      },
    });

    expect(result.basePrice).toBe(88.0);
    expect(result.addons.planning).toBe(0);
    expect(result.addons.ai).toBe(0);
    expect(result.addonsTotal).toBe(0);
    expect(result.totalMonthly).toBe(88.0);
  });

  /**
   * Test Case B: Team plan, 2 users, ONLY Planning enabled
   * 
   * Expected:
   * - base = 88.00
   * - planning = 88.00 × 0.18 = 15.84
   * - ai = 0
   * - addons_total = 15.84
   * - total = 103.84
   */
  it('should calculate correctly with only Planning add-on', () => {
    const result = calculateBillingSummary({
      basePerUser: 44,
      userCount: 2,
      addons: {
        planning: true,
        ai: false,
      },
    });

    expect(result.basePrice).toBe(88.0);
    expect(result.addons.planning).toBe(15.84);
    expect(result.addons.ai).toBe(0);
    expect(result.addonsTotal).toBe(15.84);
    expect(result.totalMonthly).toBe(103.84);

    // CRITICAL: Planning must be exactly 18% of base
    expect(result.addons.planning).toBe(Math.round(88.0 * 0.18 * 100) / 100);
  });

  /**
   * Test Case C: Team plan, 2 users, ONLY AI enabled
   * 
   * Expected:
   * - base = 88.00
   * - planning = 0
   * - ai = 88.00 × 0.18 = 15.84
   * - addons_total = 15.84
   * - total = 103.84
   */
  it('should calculate correctly with only AI add-on', () => {
    const result = calculateBillingSummary({
      basePerUser: 44,
      userCount: 2,
      addons: {
        planning: false,
        ai: true,
      },
    });

    expect(result.basePrice).toBe(88.0);
    expect(result.addons.planning).toBe(0);
    expect(result.addons.ai).toBe(15.84);
    expect(result.addonsTotal).toBe(15.84);
    expect(result.totalMonthly).toBe(103.84);

    // CRITICAL: AI must be exactly 18% of base
    expect(result.addons.ai).toBe(Math.round(88.0 * 0.18 * 100) / 100);
  });

  /**
   * Test Case D: Team plan, 2 users, BOTH Planning + AI enabled
   * 
   * Expected:
   * - base = 88.00
   * - planning = 88.00 × 0.18 = 15.84
   * - ai = 88.00 × 0.18 = 15.84 (NOT compounded!)
   * - addons_total = 31.68
   * - total = 119.68
   * 
   * CRITICAL TEST: Verifies that AI is NOT calculated as (base + planning) × 0.18
   */
  it('should calculate both add-ons from base price (NOT compounded)', () => {
    const result = calculateBillingSummary({
      basePerUser: 44,
      userCount: 2,
      addons: {
        planning: true,
        ai: true,
      },
    });

    const basePrice = 88.0;
    const expectedPlanning = 15.84;
    const expectedAi = 15.84; // Same as planning (NOT compounded)
    const expectedTotal = 119.68;

    expect(result.basePrice).toBe(basePrice);
    expect(result.addons.planning).toBe(expectedPlanning);
    expect(result.addons.ai).toBe(expectedAi);
    expect(result.addonsTotal).toBe(31.68);
    expect(result.totalMonthly).toBe(expectedTotal);

    // CRITICAL: Both add-ons must have IDENTICAL prices (proving non-compounding)
    expect(result.addons.planning).toBe(result.addons.ai);

    // CRITICAL: Verify AI is NOT (base + planning) × 0.18
    const wrongCompoundedAi = Math.round((basePrice + expectedPlanning) * 0.18 * 100) / 100; // 18.69 (WRONG!)
    expect(result.addons.ai).not.toBe(wrongCompoundedAi);
    expect(result.addons.ai).toBe(15.84); // Correct value
  });

  /**
   * Test: Different user counts to verify linear scaling
   */
  it('should scale linearly with different user counts', () => {
    const testCases = [
      {
        users: 1,
        expectedBase: 44.0,
        expectedPlanning: 7.92,
        expectedAi: 7.92,
        expectedTotal: 59.84,
      },
      {
        users: 5,
        expectedBase: 220.0,
        expectedPlanning: 39.6,
        expectedAi: 39.6,
        expectedTotal: 299.2,
      },
      {
        users: 10,
        expectedBase: 440.0,
        expectedPlanning: 79.2,
        expectedAi: 79.2,
        expectedTotal: 598.4,
      },
    ];

    testCases.forEach((testCase) => {
      const result = calculateBillingSummary({
        basePerUser: 44,
        userCount: testCase.users,
        addons: {
          planning: true,
          ai: true,
        },
      });

      expect(result.basePrice).toBe(testCase.expectedBase);
      expect(result.addons.planning).toBe(testCase.expectedPlanning);
      expect(result.addons.ai).toBe(testCase.expectedAi);
      expect(result.totalMonthly).toBe(testCase.expectedTotal);

      // Verify both add-ons are equal (non-compounded)
      expect(result.addons.planning).toBe(result.addons.ai);
    });
  });

  /**
   * Test: Toggling add-ons does NOT change base price
   */
  it('should not change base price when toggling add-ons', () => {
    const baseConfig = {
      basePerUser: 44,
      userCount: 2,
    };

    const noAddons = calculateBillingSummary({
      ...baseConfig,
      addons: { planning: false, ai: false },
    });

    const withPlanning = calculateBillingSummary({
      ...baseConfig,
      addons: { planning: true, ai: false },
    });

    const withBoth = calculateBillingSummary({
      ...baseConfig,
      addons: { planning: true, ai: true },
    });

    // Base price must remain constant regardless of add-ons
    expect(noAddons.basePrice).toBe(88.0);
    expect(withPlanning.basePrice).toBe(88.0);
    expect(withBoth.basePrice).toBe(88.0);
  });

  /**
   * Test: Edge case - 0 users
   */
  it('should handle 0 users correctly', () => {
    const result = calculateBillingSummary({
      basePerUser: 44,
      userCount: 0,
      addons: {
        planning: true,
        ai: true,
      },
    });

    expect(result.basePrice).toBe(0);
    expect(result.addons.planning).toBe(0);
    expect(result.addons.ai).toBe(0);
    expect(result.totalMonthly).toBe(0);
  });

  /**
   * Test: Enterprise plan (59€/user, no separate add-ons in calc)
   */
  it('should calculate Enterprise plan correctly', () => {
    const result = calculateBillingSummary({
      basePerUser: 59,
      userCount: 3,
      addons: {
        planning: false, // Enterprise includes all features
        ai: false,
      },
    });

    expect(result.basePrice).toBe(177.0); // 59 × 3
    expect(result.addons.planning).toBe(0);
    expect(result.addons.ai).toBe(0);
    expect(result.totalMonthly).toBe(177.0);
  });
});
