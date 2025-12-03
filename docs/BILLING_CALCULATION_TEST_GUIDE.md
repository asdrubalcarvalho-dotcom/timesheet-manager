# Billing Calculation Test Guide

## 🎯 Purpose

This guide provides comprehensive testing procedures for the **billing add-on calculation fix** that resolved the critical compounding bug where AI add-ons were incorrectly calculated as `(base + planning) × 0.18` instead of `base × 0.18`.

---

## 🐛 Bug Summary

**Issue**: Second add-on (AI) was being calculated with compounding logic.

**Example (2 users, Team plan, both add-ons):**
- ❌ **WRONG**: AI = (88 + 15.84) × 0.18 = **€18.69** → Total = €122.53
- ✅ **CORRECT**: AI = 88 × 0.18 = **€15.84** → Total = €119.68

**Files Fixed**:
- `backend/app/Services/Billing/PriceCalculator.php` (line 213)
- `backend/app/Modules/Billing/Controllers/BillingController.php` (lines 360-365)

---

## 🧪 Automated Test Suite

### Backend Unit Tests

**File**: `backend/tests/Unit/Services/Billing/PriceCalculatorTest.php`

**Test Cases**:
1. `test_team_plan_no_addons()` - Base calculation only
2. `test_team_plan_only_planning_addon()` - Single add-on (Planning)
3. `test_team_plan_only_ai_addon()` - Single add-on (AI)
4. `test_team_plan_both_addons_not_compounded()` - **CRITICAL** non-compounding test
5. `test_enterprise_plan_no_addons()` - Enterprise pricing validation
6. `test_addon_scales_linearly_with_user_count()` - Linear scaling (1, 5, 10 users)

**Run Command**:
```bash
# Run all price calculator tests
docker-compose exec app php artisan test --filter=PriceCalculatorTest

# Run specific test
docker-compose exec app php artisan test --filter=test_team_plan_both_addons_not_compounded
```

**Expected Output**:
```
PASS  Tests\Unit\Services\Billing\PriceCalculatorTest
✓ team plan no addons
✓ team plan only planning addon
✓ team plan only ai addon
✓ team plan both addons not compounded  ← CRITICAL TEST
✓ enterprise plan no addons
✓ addon scales linearly with user count

Tests:    6 passed (37 assertions)
Duration: 0.15s
```

---

### Backend Integration Tests

**File**: `backend/tests/Feature/Modules/Billing/BillingCheckoutIntegrationTest.php`

**Test Cases**:
1. `test_summary_endpoint_returns_correct_amounts_for_team_with_planning()` - API validation
2. `test_summary_endpoint_both_addons_not_compounded()` - **CRITICAL** API response test
3. `test_checkout_start_creates_correct_stripe_amount()` - Stripe integration
4. `test_checkout_start_for_addon_calculates_from_base_price()` - Dynamic addon pricing
5. `test_toggle_addon_recalculates_summary_correctly()` - Full workflow

**Run Command**:
```bash
# Run all integration tests
docker-compose exec app php artisan test --filter=BillingCheckoutIntegrationTest

# Run with verbose output
docker-compose exec app php artisan test --filter=BillingCheckoutIntegrationTest --testdox
```

**Expected Output**:
```
PASS  Tests\Feature\Modules\Billing\BillingCheckoutIntegrationTest
✓ summary endpoint returns correct amounts for team with planning
✓ summary endpoint both addons not compounded  ← CRITICAL TEST
✓ checkout start creates correct stripe amount
✓ checkout start for addon calculates from base price
✓ toggle addon recalculates summary correctly

Tests:    5 passed (23 assertions)
Duration: 0.42s
```

---

### Frontend Unit Tests

**File**: `frontend/src/utils/__tests__/calculateBillingSummary.test.ts`

**Test Cases**:
1. `should calculate correctly with no add-ons` - Case A
2. `should calculate correctly with only Planning add-on` - Case B
3. `should calculate correctly with only AI add-on` - Case C
4. `should calculate both add-ons from base price (NOT compounded)` - **CRITICAL** Case D
5. `should scale linearly with different user counts` - 1, 5, 10 users
6. `should not change base price when toggling add-ons` - Isolation test
7. `should handle 0 users correctly` - Edge case
8. `should calculate Enterprise plan correctly` - Alternative plan

**Run Command**:
```bash
# Install dependencies (if not done yet)
cd frontend
npm install

# Run tests
npm test calculateBillingSummary

# Run with coverage
npm test -- --coverage --collectCoverageFrom=src/utils/calculateBillingSummary.ts
```

**Expected Output**:
```
PASS  src/utils/__tests__/calculateBillingSummary.test.ts
  calculateBillingSummary
    ✓ should calculate correctly with no add-ons (3 ms)
    ✓ should calculate correctly with only Planning add-on (1 ms)
    ✓ should calculate correctly with only AI add-on (1 ms)
    ✓ should calculate both add-ons from base price (NOT compounded) (2 ms)  ← CRITICAL
    ✓ should scale linearly with different user counts (2 ms)
    ✓ should not change base price when toggling add-ons (1 ms)
    ✓ should handle 0 users correctly (1 ms)
    ✓ should calculate Enterprise plan correctly (1 ms)

Test Suites: 1 passed, 1 total
Tests:       8 passed, 8 total
```

---

## 🧾 Manual QA Checklist

### Prerequisites
- ✅ Tenant registered and verified
- ✅ At least 2 active users (technicians) in tenant
- ✅ Test Stripe mode enabled (`PAYMENTS_DRIVER=stripe`, `.env.test`)
- ✅ Access to Stripe Dashboard (Test mode)

### Test Scenarios

#### Scenario 1: Trial → Team Upgrade (Both Add-ons)
**Objective**: Verify checkout shows correct total with non-compounding add-ons.

1. **Login** as admin on Trial plan
2. Navigate to **Billing** page
3. Verify current plan shows "Trial - Enterprise"
4. Click **"Upgrade to Team"** button
5. In "End Trial Early?" dialog:
   - Verify warning text mentions switching to paid plan
   - Click **"Yes, Switch to Team Plan"**
6. In Stripe checkout modal:
   - **CRITICAL**: Verify total = €119.68 (NOT €122.53)
   - Verify line items:
     - Team Plan (2 users): €88.00
     - Planning Add-on: €15.84
     - AI Add-on: €15.84 ← Must be same as Planning
   - Total: €119.68
7. Use test card: `4242 4242 4242 4242`, any future date, any CVC
8. Complete checkout
9. Verify success redirect to Billing page
10. Verify plan shows "Team" with both add-ons enabled

**Expected Results**:
- ✅ Both add-ons have **identical prices** (€15.84)
- ✅ AI is **NOT** €18.69 (wrong compounded value)
- ✅ Total matches backend calculation

---

#### Scenario 2: API Response Validation
**Objective**: Verify `/api/billing/summary` returns correct calculations.

1. **Login** and get auth token
2. Open browser DevTools → Network tab
3. Navigate to Billing page
4. Find `GET /api/billing/summary` request
5. Inspect response JSON:

**Expected Response (2 users, both add-ons)**:
```json
{
  "plan": "team",
  "user_limit": null,
  "active_users": 2,
  "price_per_user": 44.00,
  "base_subtotal": 88.00,
  "addons": {
    "planning": {
      "enabled": true,
      "price": 15.84  ← CRITICAL
    },
    "ai": {
      "enabled": true,
      "price": 15.84  ← CRITICAL (same as planning)
    }
  },
  "addons_total": 31.68,
  "total_monthly": 119.68
}
```

**Validation**:
- ✅ `addons.planning.price` = `addons.ai.price` (both 15.84)
- ✅ `addons_total` = 31.68 (15.84 × 2)
- ✅ `total_monthly` = 119.68 (88 + 31.68)

---

#### Scenario 3: Toggle Add-on (AI Only)
**Objective**: Verify enabling single add-on calculates from base price.

1. Start with **Team plan, Planning enabled** (€103.84 total)
2. Navigate to Billing page
3. Click **"Enable AI Add-on"** button
4. In checkout modal:
   - Verify amount = €15.84 (NOT €18.69)
   - This is 18% of base (€88), not 18% of (€88 + €15.84)
5. Complete checkout
6. Verify new total = €119.68

**Expected Results**:
- ✅ AI addon price = €15.84 (same as Planning)
- ✅ New total = €119.68 (NOT €122.53)

---

#### Scenario 4: Different User Counts
**Objective**: Verify linear scaling across different tenant sizes.

| Users | Base | Planning | AI | Total |
|-------|------|----------|----|----|
| 1 | €44.00 | €7.92 | €7.92 | €59.84 |
| 2 | €88.00 | €15.84 | €15.84 | €119.68 |
| 5 | €220.00 | €39.60 | €39.60 | €299.20 |
| 10 | €440.00 | €79.20 | €79.20 | €598.40 |

**Test Procedure**:
1. Create test tenant with X active users
2. Upgrade to Team with both add-ons
3. Verify checkout total matches table
4. **CRITICAL**: Verify Planning = AI for all user counts

---

#### Scenario 5: Stripe Dashboard Verification
**Objective**: Confirm actual Stripe charges match internal calculations.

1. Complete checkout for Team plan (2 users, both add-ons)
2. Go to [Stripe Dashboard (Test Mode)](https://dashboard.stripe.com/test/payments)
3. Find latest PaymentIntent
4. Verify:
   - Amount = **€119.68** (or 11968 cents)
   - Description = "Team Plan - 2 users + Add-ons"
   - Metadata contains: `tenant_id`, `plan=team`, `addons=planning,ai`

**Expected Results**:
- ✅ Stripe amount matches frontend calculation
- ✅ No €122.53 charges (wrong compounded value)

---

## 📊 Test Coverage Summary

| Test Type | File | Test Count | Critical Tests |
|-----------|------|------------|----------------|
| Backend Unit | `PriceCalculatorTest.php` | 6 | ✅ `test_team_plan_both_addons_not_compounded` |
| Backend Integration | `BillingCheckoutIntegrationTest.php` | 5 | ✅ `test_summary_endpoint_both_addons_not_compounded` |
| Frontend Unit | `calculateBillingSummary.test.ts` | 8 | ✅ `should calculate both add-ons from base price` |
| Manual QA | This checklist | 5 scenarios | ✅ Scenario 1, 3 |

**Total Coverage**: 19 automated tests + 5 manual scenarios = **24 test cases**

---

## 🔍 Debugging Failed Tests

### Backend Test Failures

**Symptom**: `Expected 15.84, got 18.69` in add-on calculation.

**Diagnosis**:
```bash
# Check if PriceCalculator fix is applied
docker-compose exec app grep -A 3 'Calculate AI add-on' app/Services/Billing/PriceCalculator.php
```

**Expected Output**:
```php
// Calculate AI add-on (18% of base price - NOT compounded)
$aiPct = config('billing.addon_percentages.ai', 0.18);
$aiAmount = $baseSubtotal * $aiPct; // ← Must use $baseSubtotal only!
```

**Fix**: If wrong, verify `docker-compose down -v && docker-compose up -d --build` was run after code changes.

---

### Frontend Test Failures

**Symptom**: `Expected 15.84, received 18.69`.

**Diagnosis**:
```bash
# Check calculateBillingSummary implementation
cd frontend
grep -A 5 'const aiPrice' src/utils/calculateBillingSummary.ts
```

**Expected Output**:
```typescript
const aiPrice = input.addons.ai 
  ? Math.round(basePrice * 0.18 * 100) / 100  // ← Must use basePrice only!
  : 0;
```

**Fix**: Verify frontend container was rebuilt: `docker-compose up -d --build frontend`

---

### Manual QA Failures

**Symptom**: Checkout modal shows €122.53 instead of €119.68.

**Root Causes**:
1. **Backend cache**: Run `docker-compose exec app php artisan config:clear`
2. **Frontend build**: Run `docker-compose up -d --build frontend`
3. **Database state**: Verify tenant has exactly 2 active users:
   ```bash
   docker-compose exec database mysql -u timesheet -psecret -e "USE timesheet_<slug>; SELECT COUNT(*) FROM technicians WHERE status='active';"
   ```

---

## 🚀 Quick Test Command Reference

```bash
# Full automated test suite
docker-compose exec app php artisan test --filter=PriceCalculatorTest
docker-compose exec app php artisan test --filter=BillingCheckoutIntegrationTest
cd frontend && npm test calculateBillingSummary

# Single critical test (backend)
docker-compose exec app php artisan test --filter=test_team_plan_both_addons_not_compounded

# Single critical test (frontend)
cd frontend && npm test -- -t "should calculate both add-ons from base price"

# Rebuild everything (if tests fail after code changes)
docker-compose down -v && docker-compose up -d --build
```

---

## ✅ Success Criteria

**Automated Tests**:
- ✅ All backend unit tests pass (6/6)
- ✅ All backend integration tests pass (5/5)
- ✅ All frontend unit tests pass (8/8)
- ✅ No compounding assertions fail

**Manual QA**:
- ✅ Checkout modal shows €119.68 (NOT €122.53)
- ✅ API response has `planning.price = ai.price = 15.84`
- ✅ Stripe Dashboard shows correct charge amount
- ✅ All 4 test scenarios pass with correct totals

**Regression Prevention**:
- ✅ Critical tests explicitly assert AI ≠ (base + planning) × 0.18
- ✅ Tests cover 1, 2, 5, 10 user counts
- ✅ Both backend + frontend have identical calculation logic

---

## 📝 Notes

- **Why 15.84?** = €88 × 0.18 = €15.84 (2 users × €44/user = €88 base)
- **Why NOT 18.69?** = That's the **WRONG** compounded value: (€88 + €15.84) × 0.18 = €18.69
- **Critical Assertion**: In all tests, `planning_price === ai_price` (proves non-compounding)
- **Test Isolation**: Each test uses fresh data, no shared state
- **Docker Caveat**: Always rebuild containers after code changes (`docker-compose up -d --build`)

---

**Document Version**: 1.0  
**Last Updated**: 2025  
**Related Files**:
- `backend/app/Services/Billing/PriceCalculator.php`
- `backend/app/Modules/Billing/Controllers/BillingController.php`
- `backend/tests/Unit/Services/Billing/PriceCalculatorTest.php`
- `backend/tests/Feature/Modules/Billing/BillingCheckoutIntegrationTest.php`
- `frontend/src/utils/calculateBillingSummary.ts`
- `frontend/src/utils/__tests__/calculateBillingSummary.test.ts`
