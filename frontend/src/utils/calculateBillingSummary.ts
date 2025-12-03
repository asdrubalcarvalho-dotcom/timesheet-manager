/**
 * calculateBillingSummary.ts
 * 
 * Pure function for calculating billing amounts.
 * Used by BillingPage/PricingSummary components.
 * 
 * RULES:
 * - Each add-on is calculated as base_price × addon_percentage
 * - Add-ons are NEVER compounded
 * - Total = base + sum(all addons)
 */

export interface BillingCalculationInput {
  basePerUser: number;
  userCount: number;
  addons: {
    planning: boolean;
    ai: boolean;
  };
}

export interface BillingCalculationResult {
  basePrice: number;
  addons: {
    planning: number;
    ai: number;
  };
  addonsTotal: number;
  totalMonthly: number;
}

const ADDON_PERCENTAGE = 0.18; // 18%

export function calculateBillingSummary(
  input: BillingCalculationInput
): BillingCalculationResult {
  const basePrice = input.basePerUser * input.userCount;

  // Calculate each add-on as percentage of base price ONLY
  const planningPrice = input.addons.planning
    ? Math.round(basePrice * ADDON_PERCENTAGE * 100) / 100
    : 0;

  const aiPrice = input.addons.ai
    ? Math.round(basePrice * ADDON_PERCENTAGE * 100) / 100
    : 0;

  const addonsTotal = planningPrice + aiPrice;
  const totalMonthly = basePrice + addonsTotal;

  return {
    basePrice: Math.round(basePrice * 100) / 100,
    addons: {
      planning: planningPrice,
      ai: aiPrice,
    },
    addonsTotal: Math.round(addonsTotal * 100) / 100,
    totalMonthly: Math.round(totalMonthly * 100) / 100,
  };
}
