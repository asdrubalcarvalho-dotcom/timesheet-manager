# Frontend Billing Tests - Task 8

## 📋 Test Coverage Summary

This document provides manual testing procedures for Frontend Phase 1.5 (Billing Integration).

**Components Under Test:**
- BillingContext (state management)
- FeatureContext (feature flags)
- BillingPage UI (plans, addons, pricing)
- RequireFeature guards (module access control)
- SideMenu integration (dynamic visibility)

---

## 🧪 Test Scenarios

### Test 1: BillingPage Rendering for Each Plan

**Objective:** Verify BillingPage displays correctly for Starter, Team, and Enterprise plans.

**Prerequisites:**
- Backend Phase 1 complete (billing endpoints working)
- Tenant configured with test data

**Test Steps:**

#### 1.1 Starter Plan Display
1. Mock BillingContext to return:
   ```json
   {
     "plan": "starter",
     "users": 3,
     "modules_enabled": ["timesheets", "expenses"],
     "addons": [],
     "base_subtotal": 35.00,
     "total": 35.00,
     "requires_upgrade": false
   }
   ```
2. Navigate to `/billing`
3. **Expected Results:**
   - Current Plan section shows "Starter" card with green theme
   - Pricing shows "€35/month" (flat rate)
   - Available Plans shows Team and Enterprise cards
   - Addons section shows Planning and AI toggles (both OFF)
   - Pricing Summary shows:
     - Base Plan: €35.00
     - Add-ons: €0.00 (hidden if zero)
     - Total Monthly: €35.00

#### 1.2 Team Plan Display
1. Mock BillingContext to return:
   ```json
   {
     "plan": "team",
     "users": 5,
     "modules_enabled": ["timesheets", "expenses", "travels"],
     "addons": [],
     "base_subtotal": 175.00,
     "total": 175.00,
     "requires_upgrade": false
   }
   ```
2. Navigate to `/billing`
3. **Expected Results:**
   - Current Plan shows "Team" card with blue theme
   - Pricing shows "€175/month" (€35/user × 5 users)
   - Available Plans shows Starter and Enterprise
   - Travels module enabled (check sidebar)
   - Pricing Summary: Total = €175.00

#### 1.3 Enterprise Plan Display
1. Mock BillingContext to return:
   ```json
   {
     "plan": "enterprise",
     "users": 10,
     "modules_enabled": ["timesheets", "expenses", "travels"],
     "addons": [],
     "base_subtotal": 350.00,
     "total": 350.00,
     "requires_upgrade": false
   }
   ```
2. Navigate to `/billing`
3. **Expected Results:**
   - Current Plan shows "Enterprise" card with purple theme
   - Pricing shows "€350/month" (€35/user × 10 users)
   - Available Plans shows Starter and Team
   - Enterprise features listed (SLA, 24/7 support, custom branding)
   - Pricing Summary: Total = €350.00

---

### Test 2: Addon Toggles Update Totals Correctly

**Objective:** Verify Planning and AI addon toggles correctly update pricing (+18% each).

**Prerequisites:**
- Team or Enterprise plan (addons not available on Starter)

**Test Steps:**

#### 2.1 Enable Planning Addon
1. Start with Team plan, 5 users, no addons (€175 base)
2. Click Planning addon toggle to ON
3. **Expected Results:**
   - Toggle switches to ON (orange theme)
   - Planning addon features displayed (Gantt charts, Task dependencies, etc.)
   - Backend called: `POST /api/billing/toggle-addon` with `{"addon": "planning"}`
   - Pricing Summary updates:
     - Base Plan: €175.00
     - Add-ons: €31.50 (18% of €175)
     - Total Monthly: €206.50
   - Sidebar shows "Planning" menu item (was hidden before)

#### 2.2 Enable AI Addon
1. With Planning already enabled (€206.50 total)
2. Click AI addon toggle to ON
3. **Expected Results:**
   - Toggle switches to ON (purple theme)
   - AI addon features displayed (Smart suggestions, Pattern analysis, etc.)
   - Backend called: `POST /api/billing/toggle-addon` with `{"addon": "ai"}`
   - Pricing Summary updates:
     - Base Plan: €175.00
     - Add-ons: €63.00 (18% + 18% = 36% of €175)
     - Total Monthly: €238.00
   - Sidebar shows "AI Insights" menu item

#### 2.3 Disable Planning Addon
1. With both addons enabled (€238.00 total)
2. Click Planning toggle to OFF
3. **Expected Results:**
   - Toggle switches to OFF
   - Planning features hidden
   - Backend called: `POST /api/billing/toggle-addon` with `{"addon": "planning"}`
   - Pricing Summary updates:
     - Base Plan: €175.00
     - Add-ons: €31.50 (only AI 18%)
     - Total Monthly: €206.50
   - Sidebar hides "Planning" menu item

#### 2.4 Verify Calculation Accuracy
1. Test with different user counts:
   - 1 user (€35): Planning = €6.30, AI = €6.30, Both = €12.60
   - 10 users (€350): Planning = €63.00, AI = €63.00, Both = €126.00
   - 20 users (€700): Planning = €126.00, AI = €126.00, Both = €252.00
2. **Expected Results:**
   - All calculations match backend response
   - No rounding errors
   - Frontend never calculates prices (reads from backend only)

---

### Test 3: RequireFeature Prevents Access to Locked Modules

**Objective:** Verify RequireFeature guards block access to disabled modules.

**Prerequisites:**
- Starter plan (no Travels, Planning, AI modules)

**Test Steps:**

#### 3.1 Travels Module Access (Starter Plan)
1. Configure plan: Starter, modules_enabled: `["timesheets", "expenses"]`
2. Try to access `/travels` route
3. **Expected Results:**
   - LockedModuleBanner displayed (full screen)
   - Shows Travels icon with lock badge (blue theme)
   - Title: "Travels Module"
   - Description: "Track business trips, travel segments, and related expenses"
   - Benefits list displayed (4 items)
   - Shows "Team or Enterprise plan required"
   - "Upgrade Your Plan" button visible
   - Click button → redirects to `/billing`

#### 3.2 Planning Module Access (No Addon)
1. Configure plan: Team, modules_enabled: `["timesheets", "expenses", "travels"]` (no planning)
2. Try to access `/planning` route
3. **Expected Results:**
   - LockedModuleBanner displayed (orange theme)
   - Title: "Planning Module"
   - Description: "Advanced project planning with Gantt charts..."
   - Shows "Available as +18% addon"
   - Click upgrade → redirects to `/billing`

#### 3.3 AI Module Access (No Addon)
1. Configure plan: Team, modules_enabled: `["timesheets", "expenses", "travels"]` (no AI)
2. Try to access `/ai-insights` route
3. **Expected Results:**
   - LockedModuleBanner displayed (purple theme)
   - Title: "AI Assistant"
   - Description: "AI-powered insights, predictions..."
   - Shows "Available as +18% addon"
   - Click upgrade → redirects to `/billing`

#### 3.4 Verify Sidebar Hides Locked Modules
1. With Starter plan (no modules)
2. Check SideMenu
3. **Expected Results:**
   - "Travels" menu item hidden
   - "Planning" menu item hidden
   - "AI Insights" menu item hidden
   - Only base modules visible (Timesheets, Expenses, Dashboard, Approvals)

---

### Test 4: Plan Upgrade Flow (Starter → Team)

**Objective:** Verify upgrading from Starter to Team unlocks modules and updates UI.

**Prerequisites:**
- Start with Starter plan
- Backend upgrade endpoint working

**Test Steps:**

#### 4.1 Initial State (Starter Plan)
1. Login with Starter plan tenant
2. Navigate to `/billing`
3. **Verify:**
   - Current Plan: Starter (€35/month)
   - Sidebar: No Travels/Planning/AI items
   - `/travels` → Shows LockedModuleBanner

#### 4.2 Upgrade to Team Plan
1. On BillingPage, click "Upgrade to Team" button
2. **Expected Results:**
   - Backend called: `POST /api/billing/upgrade-plan` with `{"plan": "team"}`
   - BillingContext.refreshSummary() called automatically
   - UI updates:
     - Current Plan changes to Team (blue theme)
     - Pricing updates to €35 × users
     - Available Plans section removes Team, shows Starter and Enterprise

#### 4.3 Verify Modules Unlocked
1. Check sidebar after upgrade
2. **Expected Results:**
   - "Travels" menu item now visible
   - Can access `/travels` (no LockedModuleBanner)
   - TravelsList component renders
3. Try adding Planning addon
4. **Expected Results:**
   - "Planning" menu item appears
   - Can access `/planning`
   - PlanningGantt component renders

#### 4.4 Verify Pricing Updates
1. With Team plan (5 users)
2. Enable Planning addon
3. **Expected Results:**
   - Base: €175 (€35 × 5)
   - Planning: +€31.50 (18%)
   - Total: €206.50
4. Add AI addon
5. **Expected Results:**
   - Base: €175
   - Addons: €63.00 (36%)
   - Total: €238.00

---

### Test 5: Checkout Flow (Fake Payment)

**Objective:** Verify checkout dialog workflow with fake credit card.

**Prerequisites:**
- Any plan with changes to confirm

**Test Steps:**

#### 5.1 Start Checkout
1. On BillingPage, click "Proceed to Checkout"
2. **Expected Results:**
   - CheckoutForm dialog opens (fullWidth, maxWidth="sm")
   - Shows "Start Checkout" button
3. Click "Start Checkout"
4. **Expected Results:**
   - Backend called: `POST /api/billing/checkout/start`
   - Dialog shows credit card form
   - Demo mode alert displayed: "Enter any 16-digit card number. No real payment will be processed."

#### 5.2 Fill Payment Form (Valid Data)
1. Enter card details:
   - Card Number: `1234 5678 9012 3456` (auto-formats with spaces)
   - Cardholder Name: `John Doe`
   - Expiry Date: `12/25` (auto-formats MM/YY)
   - CVV: `123` (masked)
2. Click "Pay Now"
3. **Expected Results:**
   - Shows "Processing payment..." with CircularProgress
   - Backend called: `POST /api/billing/checkout/confirm` with `{"card_number": "1234567890123456"}`
   - Success alert: "Payment successful! Your subscription has been updated."
   - Dialog auto-closes after 2 seconds
   - BillingContext.refreshSummary() called
   - BillingPage updates with new data

#### 5.3 Form Validation (Invalid Data)
1. Try card number with less than 16 digits: `1234 5678`
2. Click "Pay Now"
3. **Expected Results:**
   - Error alert: "Card number must be 16 digits"
   - Form stays open

4. Try empty cardholder name
5. **Expected Results:**
   - Error alert: "Cardholder name is required"

6. Try invalid expiry format: `1325` (should be `13/25`)
7. **Expected Results:**
   - Error alert: "Expiry date must be in MM/YY format"

8. Try CVV with 2 digits: `12`
9. **Expected Results:**
   - Error alert: "CVV must be 3 digits"

#### 5.4 Payment Error Handling
1. Mock backend to return error on confirm
2. Fill valid card data and submit
3. **Expected Results:**
   - Shows error alert with backend message
   - "Try Again" button appears
   - Click "Try Again" → returns to form
   - Can retry payment

---

### Test 6: Responsive Design

**Objective:** Verify billing UI works on mobile, tablet, desktop.

**Test Devices:**
- Mobile: 375px width (iPhone SE)
- Tablet: 768px width (iPad)
- Desktop: 1920px width

**Test Steps:**

#### 6.1 BillingPage Layout
1. Resize browser to each breakpoint
2. **Expected Results:**
   - Mobile (xs): Plans stack vertically (Grid xs=12)
   - Tablet (md): Plans in 2 columns (Grid md=6)
   - Desktop: Plans in 3 columns (Grid md=4)
   - Sticky header stays at top on all sizes
   - Pricing cards stack properly

#### 6.2 CheckoutForm Dialog
1. Open checkout on mobile
2. **Expected Results:**
   - Dialog is fullWidth
   - Form fields stack vertically
   - Buttons are touch-friendly (min 44px height)
   - Expiry/CVV fields on separate rows on mobile

#### 6.3 LockedModuleBanner
1. Try accessing locked module on mobile
2. **Expected Results:**
   - Icon scales down appropriately
   - Benefits list readable
   - Upgrade button full width on mobile
   - All text legible (min 14px font size)

---

## 🔍 Edge Cases & Error Scenarios

### Edge Case 1: No Billing Data
**Scenario:** Backend returns 404 or empty response  
**Expected:** Alert "No billing information available"

### Edge Case 2: Network Error
**Scenario:** API request fails (network offline)  
**Expected:** Error message displayed, can retry

### Edge Case 3: Requires Upgrade Warning
**Scenario:** `requires_upgrade: true` in billing summary  
**Expected:** Yellow warning alert at top: "You have exceeded the limits of your current plan. Please upgrade to continue using all features."

### Edge Case 4: Multiple Simultaneous Addon Toggles
**Scenario:** User rapidly clicks Planning and AI toggles  
**Expected:** Only one request at a time (loading state prevents multiple clicks)

### Edge Case 5: Page Refresh During Checkout
**Scenario:** User refreshes browser mid-checkout  
**Expected:** Dialog closes, billing data refetched, no duplicate charges

---

## ✅ Success Criteria

**All tests pass if:**

1. **BillingPage Rendering:**
   - ✅ Starter/Team/Enterprise plans display correctly
   - ✅ Pricing matches backend data (no frontend calculations)
   - ✅ Current plan highlighted with correct theme color
   - ✅ Upgrade buttons functional

2. **Addon Toggles:**
   - ✅ Planning addon adds exactly +18%
   - ✅ AI addon adds exactly +18%
   - ✅ Both addons together add +36%
   - ✅ Pricing updates immediately after toggle
   - ✅ Sidebar shows/hides module items correctly

3. **Module Guards:**
   - ✅ RequireFeature blocks access to disabled modules
   - ✅ LockedModuleBanner displays with correct theme
   - ✅ Upgrade button redirects to /billing
   - ✅ Enabled modules render without guards

4. **Upgrade Flow:**
   - ✅ Starter → Team unlocks Travels module
   - ✅ Sidebar updates after plan change
   - ✅ Pricing recalculates for new user count
   - ✅ Addons work correctly on new plan

5. **Checkout:**
   - ✅ Fake card form accepts any 16 digits
   - ✅ Validation prevents invalid data
   - ✅ Success flow completes and updates billing
   - ✅ Error handling allows retry

6. **Integration:**
   - ✅ BillingContext fetches data on mount
   - ✅ FeatureContext derives flags from BillingContext
   - ✅ Sidebar reads from FeatureContext
   - ✅ All components use hooks (no direct API calls)

---

## 🐛 Known Issues (To Document)

- TypeScript compile errors for new component imports (cache issue, resolves on rebuild)
- Card number formatting may not work in all browsers (test in Chrome, Firefox, Safari)

---

## 📊 Test Execution Checklist

- [ ] Test 1: BillingPage for Starter/Team/Enterprise
- [ ] Test 2: Addon toggles (+18% each)
- [ ] Test 3: RequireFeature guards
- [ ] Test 4: Starter → Team upgrade flow
- [ ] Test 5: Checkout with fake card
- [ ] Test 6: Responsive design (mobile/tablet/desktop)
- [ ] Edge cases tested
- [ ] All success criteria met

---

## 🎯 Next Steps After Testing

If all tests pass:
1. ✅ Mark Task 8 (frontend-tests) as COMPLETE
2. ✅ Frontend Phase 1.5 is DONE
3. ⏸️ DO NOT proceed to Phase 2 (migration/cleanup) - blocked by .cursorrules

If tests fail:
1. Document failures in this file
2. Fix issues in respective components
3. Re-run tests
4. Update this document with findings
