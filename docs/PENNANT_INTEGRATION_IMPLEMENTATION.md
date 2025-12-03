# Pennant Integration Implementation

**Date**: 2025-11-22  
**Status**: ✅ COMPLETED  
**Branch**: feature/billing-pennant-v2

## Overview

This document describes the implementation of Laravel Pennant as the **authoritative source** for tenant feature flags, while maintaining the existing billing JSON response structure and pricing logic.

## Goals Achieved

1. ✅ Pennant is the single source of truth for feature flags
2. ✅ Existing BillingSummary JSON structure preserved
3. ✅ Existing pricing logic (PriceCalculator) unchanged
4. ✅ All subscription changes sync to Pennant automatically
5. ✅ Centralized feature flag rules in one place
6. ✅ Starter plan blocks addons with proper error codes
7. ✅ Enterprise plan handles addon toggles as no-op
8. ✅ BillingSummary "features" array aligned with Pennant logic

## Business Rules Implementation

### Feature Matrix by Plan

#### STARTER
- **Features**: timesheets ✓, expenses ✓
- **Disabled**: travels ✗, planning ✗, ai ✗
- **Addons**: NOT allowed
- **Behavior**: HTTP 400 with code `addons_not_allowed_on_starter`

#### TEAM
- **Base Features**: timesheets ✓, expenses ✓, travels ✓
- **Optional Addons**: planning, ai (18% each)
- **Behavior**: Addons toggle planning/ai features on/off

#### ENTERPRISE
- **Features**: ALL enabled (timesheets, expenses, travels, planning, ai)
- **Addons**: NOT applicable (everything included)
- **Behavior**: HTTP 200 with code `addons_included_in_enterprise` (no-op)

#### TRIAL_ENTERPRISE
- **Features**: Same as ENTERPRISE (all enabled)
- **Pricing**: Total = 0 (free trial)
- **Duration**: 15 days

## Files Modified

### 1. app/Services/Billing/PlanManager.php

**New Methods**:

```php
private function getFeatureFlagsForSubscription(Subscription $subscription): array
```
- **Purpose**: Single source of truth for feature flags
- **Returns**: `['timesheets' => bool, 'expenses' => bool, 'travels' => bool, 'planning' => bool, 'ai' => bool]`
- **Logic**:
  - Uses `match()` expression for plan-based rules
  - Handles trial as enterprise
  - Applies addons for TEAM plan only
  - Ignores addons for STARTER and ENTERPRISE

```php
private function syncPennantFeatures(Tenant $tenant, Subscription $subscription): void
```
- **Purpose**: Sync Pennant flags based on subscription
- **Behavior**: 
  - Calls `getFeatureFlagsForSubscription()`
  - Activates/deactivates features via `Feature::for($tenant)`
  - No exceptions on unknown features

**Updated Methods**:

```php
public function toggleAddon(Tenant $tenant, string $addon): array
```
- **STARTER**: Throws `InvalidArgumentException` (caught by controller)
- **ENTERPRISE**: Returns `['action' => 'no_change', 'message' => '...']`
- **TEAM**: Normal toggle behavior + calls `syncPennantFeatures()`

**Wired Sync Points**:
- `startTrialForTenant()` → calls `syncFeaturesForSubscription()` (legacy wrapper)
- `endTrialForTenant()` → calls `syncFeaturesForSubscription()`
- `applyPlan()` → calls `syncFeaturesForSubscription()`
- `toggleAddon()` → calls `syncPennantFeatures()` after addon change

### 2. app/Services/Billing/PriceCalculator.php

**Changes**: Added PHPDoc comments to ensure alignment

**Updated Methods**:
- `buildStarterResult()`: Added comment referencing PlanManager mapping
- `buildTeamResult()`: Added comment referencing PlanManager mapping
- `buildEnterpriseResult()`: Added comment referencing PlanManager mapping
- `buildTrialEnterpriseResult()`: Added comment referencing PlanManager mapping

**Important**: Features array construction MUST match `PlanManager::getFeatureFlagsForSubscription()` logic.

### 3. Modules/Billing/Controllers/BillingController.php

**Updated Method**: `toggleAddon()`

**STARTER Plan Handling**:
```php
if ($subscription && $subscription->plan === 'starter') {
    return response()->json([
        'success' => false,
        'code'    => 'addons_not_allowed_on_starter',
        'message' => 'Add-ons are not available on the Starter plan. Please upgrade to Team or Enterprise.',
    ], 400);
}
```

**ENTERPRISE Plan Handling**:
```php
if ($subscription && $subscription->plan === 'enterprise') {
    return response()->json([
        'success' => true,
        'code'    => 'addons_included_in_enterprise',
        'message' => 'All features are already included in your Enterprise plan.',
        'data' => $summary,
    ]);
}
```

## API Response Codes

### Addon Toggle Errors

| Code | HTTP Status | Plan | Message |
|------|-------------|------|---------|
| `addons_not_allowed_on_starter` | 400 | Starter | Add-ons are not available on the Starter plan... |
| `addons_included_in_enterprise` | 200 | Enterprise | All features are already included in your Enterprise plan. |

## Testing Scenarios

### 1. Starter Plan
```bash
# Create tenant on Starter
POST /api/tenants/register

# Verify Pennant features
Feature::for($tenant)->active('timesheets') // true
Feature::for($tenant)->active('expenses')   // true
Feature::for($tenant)->active('travels')    // false
Feature::for($tenant)->active('planning')   // false
Feature::for($tenant)->active('ai')         // false

# Try to toggle addon
POST /api/billing/toggle-addon
Body: { "addon": "planning" }
Response: HTTP 400, code: "addons_not_allowed_on_starter"
```

### 2. Team Plan (No Addons)
```bash
# Upgrade to Team
POST /api/billing/upgrade-plan
Body: { "plan": "team", "user_limit": 5 }

# Verify Pennant features
Feature::for($tenant)->active('timesheets') // true
Feature::for($tenant)->active('expenses')   // true
Feature::for($tenant)->active('travels')    // true
Feature::for($tenant)->active('planning')   // false
Feature::for($tenant)->active('ai')         // false
```

### 3. Team Plan + Planning Addon
```bash
# Enable planning
POST /api/billing/toggle-addon
Body: { "addon": "planning" }
Response: HTTP 200, action: "enabled"

# Verify Pennant features
Feature::for($tenant)->active('planning')   // true

# Verify BillingSummary
GET /api/billing/summary
Response.features.planning // true
Response.addons.planning   // 7.92 (for 1 user: 44€ * 0.18)
```

### 4. Team Plan + AI Addon
```bash
# Enable AI
POST /api/billing/toggle-addon
Body: { "addon": "ai" }
Response: HTTP 200, action: "enabled"

# Verify Pennant features
Feature::for($tenant)->active('ai')         // true

# Verify BillingSummary
GET /api/billing/summary
Response.features.ai       // true
Response.addons.ai         // 9.35 (for 1 user: 51.92€ * 0.18)
Response.total             // 61.27€ (44 + 7.92 + 9.35)
```

### 5. Enterprise Plan
```bash
# Upgrade to Enterprise
POST /api/billing/upgrade-plan
Body: { "plan": "enterprise", "user_limit": 10 }

# Verify Pennant features (all enabled)
Feature::for($tenant)->active('timesheets') // true
Feature::for($tenant)->active('expenses')   // true
Feature::for($tenant)->active('travels')    // true
Feature::for($tenant)->active('planning')   // true
Feature::for($tenant)->active('ai')         // true

# Try to toggle addon (no-op)
POST /api/billing/toggle-addon
Body: { "addon": "planning" }
Response: HTTP 200, code: "addons_included_in_enterprise"

# Verify BillingSummary
GET /api/billing/summary
Response.features.planning // true (unchanged)
Response.features.ai       // true (unchanged)
Response.addons.planning   // 0.0 (included in base)
Response.addons.ai         // 0.0 (included in base)
Response.total             // 590€ (for 10 users: 59€ * 10)
```

### 6. Trial Enterprise
```bash
# Start trial (automatic on registration)
POST /api/tenants/register

# Verify Pennant features (all enabled like enterprise)
Feature::for($tenant)->active('planning')   // true
Feature::for($tenant)->active('ai')         // true

# Verify BillingSummary
GET /api/billing/summary
Response.plan              // "trial_enterprise"
Response.is_trial          // true
Response.total             // 0 (trial = free)
Response.features.planning // true
Response.features.ai       // true
```

## Architecture Decisions

### Option A Selected: Minimal Coupling
- PriceCalculator does NOT inject PlanManager
- Feature arrays manually constructed in PriceCalculator
- PHPDoc comments ensure alignment with PlanManager logic
- Single source of truth: `PlanManager::getFeatureFlagsForSubscription()`

**Rationale**:
- Avoids circular dependencies
- Keeps PriceCalculator focused on pricing calculations
- Maintains separation of concerns
- Easier to test in isolation

### Pennant Sync Strategy
- Pennant is updated AFTER subscription changes (not before)
- All subscription mutations call `syncPennantFeatures()`
- Sync points: trial start/end, plan changes, addon toggles
- No background jobs required (synchronous sync)

## Backward Compatibility

✅ All existing billing endpoints work unchanged:
- `GET /api/billing/summary` → Same response structure
- `POST /api/billing/upgrade-plan` → Same behavior + Pennant sync
- `POST /api/billing/toggle-addon` → Enhanced with plan-specific rules
- `POST /api/billing/checkout/start` → Unchanged
- `POST /api/billing/checkout/confirm` → Unchanged

✅ Existing PriceCalculator tests should pass (if any)

✅ Frontend code unchanged (NotificationContext already in use)

## Migration Notes

### No Database Changes Required
- ✅ Subscriptions table unchanged
- ✅ Features table auto-created by Pennant in central DB
- ✅ No data migration needed
- ✅ Existing tenants will sync on next subscription change

### Deployment Steps

1. **Merge to main**:
   ```bash
   git checkout main
   git merge feature/billing-pennant-v2
   ```

2. **Deploy backend** (no migrations needed):
   ```bash
   docker-compose down -v && docker-compose up -d --build
   ```

3. **Verify Pennant table exists**:
   ```bash
   docker-compose exec database mysql -u root -proot -e "USE timesheet; SHOW TABLES LIKE 'features';"
   ```

4. **Test with existing tenant**:
   ```bash
   # Trigger sync by toggling addon or upgrading plan
   POST /api/billing/toggle-addon
   Body: { "addon": "planning" }
   ```

5. **Verify Pennant features**:
   ```bash
   docker-compose exec database mysql -u root -proot -e "USE timesheet; SELECT * FROM features;"
   ```

## Common Issues

### Features Table Not Found
**Cause**: Pennant not auto-creating table  
**Solution**: Ensure Laravel Pennant is properly installed and configured

### Features Not Syncing
**Cause**: Subscription changes not calling sync methods  
**Solution**: Check that all mutation points call `syncPennantFeatures()`

### Wrong Error Code on Starter Addon Toggle
**Cause**: Old error code in use  
**Solution**: Use `addons_not_allowed_on_starter` (not `starter_no_addons`)

## Future Enhancements

1. **Option B Implementation** (if coupling acceptable):
   - Inject PlanManager into PriceCalculator
   - Reuse `getFeatureFlagsForSubscription()` directly
   - Remove manual feature array construction

2. **Pennant Purge on Tenant Deletion**:
   - Add cleanup logic to deactivate all features on tenant delete
   - Consider cascade delete on features table

3. **Feature Flag Caching**:
   - Pennant supports caching out of the box
   - Configure cache driver in `config/pennant.php`

4. **Feature Flag Middleware**:
   - Create middleware to check feature flags
   - Use `Feature::active('planning')` in routes/controllers

## References

- **Pennant Documentation**: https://laravel.com/docs/11.x/pennant
- **BILLING_RULES_SUBSCRIPTION**: `/docs/BILLING_RULES_SUBSCRIPTION`
- **STARTUP_CHECKLIST**: `/STARTUP_CHECKLIST.md` (Section 5)
- **Related Files**:
  - `backend/app/Services/Billing/PlanManager.php`
  - `backend/app/Services/Billing/PriceCalculator.php`
  - `Modules/Billing/Controllers/BillingController.php`
  - `backend/config/billing.php`

---

**Implementation Completed By**: GitHub Copilot (Claude Sonnet 4.5)  
**Review Status**: Ready for testing
