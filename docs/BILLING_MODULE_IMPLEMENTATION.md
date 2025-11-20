# Billing Module Implementation

**Status:** ✅ Backend Complete (Phase 1)  
**Created:** 2025-11-20  
**Architecture:** Modular Monolith with Feature Flags

---

## 📦 Overview

The **Billing Module** is the first module implemented using the new **Modular Monolith** architecture. It serves as:

1. **Revenue Capability** - Enables SaaS subscription billing via Stripe
2. **Architectural Template** - Establishes pattern for migrating existing modules (Timesheets, Expenses, Travel, Planning)

---

## 🏗️ Architecture

### Directory Structure

```
backend/app/Modules/Billing/
├── Http/
│   ├── Controllers/
│   │   ├── BillingController.php        (Overview, payment methods, invoices)
│   │   ├── SubscriptionController.php   (Add/remove licenses, billing cycle)
│   │   ├── FeatureController.php        (Enable/disable modules, trials)
│   │   └── WebhookController.php        (Stripe webhook events)
│   ├── Middleware/
│   │   └── CheckModuleAccess.php        (Verify tenant has module enabled)
│   └── Requests/
│       └── (Future validation requests)
├── Models/
│   ├── TenantFeature.php                (Feature flags per tenant)
│   └── TenantLicense.php                (License/seat management)
├── Services/
│   ├── FeatureManager.php               (Module access control with caching)
│   └── LicenseManager.php               (License allocation and Stripe integration)
├── Policies/
│   └── TenantFeaturePolicy.php          (Authorization for feature management)
├── Providers/
│   └── BillingServiceProvider.php       (Module registration)
├── routes/
│   └── billing.php                      (API routes for billing operations)
└── database/
    └── migrations/
        ├── 2025_11_20_000001_create_tenant_features_table.php
        └── 2025_11_20_000002_create_tenant_licenses_table.php
```

---

## 🗄️ Database Schema

### `tenant_features` (Central DB)

Stores feature flags for each tenant.

| Column         | Type               | Description                                  |
|----------------|--------------------|----------------------------------------------|
| `id`           | bigint (PK)        | Primary key                                  |
| `tenant_id`    | char(26)           | FK to tenants (ULID)                         |
| `module_name`  | varchar(50)        | Module identifier (e.g., 'planning')         |
| `is_enabled`   | boolean            | Whether module is active                     |
| `expires_at`   | timestamp (null)   | Trial expiration date (null = no trial)      |
| `max_users`    | int (null)         | Max users for this module (null = unlimited) |
| `metadata`     | json (null)        | Additional module-specific settings          |
| `created_by`   | bigint (null)      | User who created feature flag                |
| `updated_by`   | bigint (null)      | User who last updated                        |
| `created_at`   | timestamp          | Creation timestamp                           |
| `updated_at`   | timestamp          | Last update timestamp                        |

**Indexes:**
- Unique: `(tenant_id, module_name)`

**Available Modules:**
- `timesheets` (CORE - cannot be disabled)
- `expenses` (CORE - cannot be disabled)
- `travel`
- `planning`
- `billing`
- `reporting`

---

### `tenant_licenses` (Central DB)

Manages license/seat allocation with Stripe integration.

| Column                    | Type               | Description                                     |
|---------------------------|--------------------|-------------------------------------------------|
| `id`                      | bigint (PK)        | Primary key                                     |
| `tenant_id`               | char(26) (Unique)  | FK to tenants (ULID)                            |
| `purchased_licenses`      | int                | Number of licenses purchased                    |
| `used_licenses`           | int                | Number of active users                          |
| `price_per_license`       | decimal(8,2)       | Price per user/month (e.g., €5.00)              |
| `billing_cycle`           | enum               | `monthly` or `annual`                           |
| `stripe_subscription_id`  | varchar(255) (null)| Stripe subscription ID                          |
| `stripe_price_id`         | varchar(255) (null)| Stripe price ID                                 |
| `trial_ends_at`           | timestamp (null)   | End of trial period                             |
| `auto_upgrade`            | boolean            | Auto-upgrade when at capacity (default: false)  |
| `created_by`              | bigint (null)      | User who created license                        |
| `updated_by`              | bigint (null)      | User who last updated                           |
| `created_at`              | timestamp          | Creation timestamp                              |
| `updated_at`              | timestamp          | Last update timestamp                           |

---

## 🎛️ Services

### FeatureManager

**Purpose:** Centralized feature flag management with caching.

**Key Methods:**

```php
// Check if module is enabled for current tenant
isEnabled(string $module): bool

// Require module (throws 403 if disabled)
requireModule(string $module): void

// Get all enabled modules
getEnabledModules(): array

// Get status of all modules (enabled, trial, expires_at, etc.)
getAllModulesStatus(): array

// Enable a module
enable(string $module): TenantFeature

// Disable a module (throws exception if core module)
disable(string $module): TenantFeature

// Set trial period
setTrial(string $module, int $days): TenantFeature

// Initialize default features for new tenant
initializeDefaultFeatures(string $tenantId, array $modules = []): void
```

**Caching:**
- TTL: 1 hour (3600 seconds)
- Keys: `tenant:{id}:feature:{module}`, `tenant:{id}:enabled_modules`

---

### LicenseManager

**Purpose:** License/seat allocation and Stripe synchronization.

**Key Methods:**

```php
// Get license information
getLicense(): ?TenantLicense

// Check if tenant can add user
canAddUser(): bool

// Get available licenses
availableLicenses(): int

// Add licenses (optionally update Stripe)
addLicenses(int $quantity, bool $updateStripe = true): TenantLicense

// Remove licenses
removeLicenses(int $quantity, bool $updateStripe = true): TenantLicense

// Increment usage when user added
incrementUsage(): void

// Decrement usage when user removed
decrementUsage(): void

// Get license summary
getSummary(): array

// Calculate cost for adding licenses
calculateCost(int $quantity): array

// Initialize license for new tenant
initializeLicense(string $tenantId, int $licenses = 1, ...): TenantLicense
```

---

## 🛤️ API Endpoints

### Billing Overview

```
GET /api/billing
```
**Response:**
```json
{
  "tenant": { "id": "...", "name": "...", "plan": "trial", "status": "active" },
  "license": {
    "purchased": 5,
    "used": 3,
    "available": 2,
    "utilization": 60,
    "is_trialing": true,
    "trial_ends_at": "2025-12-04",
    "monthly_cost": 25.00,
    "annual_cost": 300.00,
    "billing_cycle": "monthly",
    "price_per_license": 5.00,
    "auto_upgrade": false
  },
  "modules": { ... },
  "subscription": { ... }
}
```

---

### Subscription Management

```
POST /api/subscription/add-licenses
Body: { "quantity": 2 }
Permission: manage-billing
```

```
POST /api/subscription/remove-licenses
Body: { "quantity": 1 }
Permission: manage-billing
```

```
POST /api/subscription/preview-cost
Body: { "quantity": 3 }
Response: {
  "quantity": 3,
  "price_per_license": 5.00,
  "monthly_increase": 15.00,
  "annual_increase": 180.00,
  "prorated_amount": 12.50,
  "billing_cycle": "monthly",
  "new_monthly_total": 40.00
}
```

```
POST /api/subscription/switch-billing-cycle
Body: { "billing_cycle": "annual" }
Permission: manage-billing
```

```
POST /api/subscription/cancel
Permission: manage-billing
```

```
POST /api/subscription/resume
Permission: manage-billing
```

---

### Feature Management

```
GET /api/features
Response: {
  "modules": {
    "timesheets": {
      "name": "Timesheets",
      "enabled": true,
      "is_core": true,
      "is_trialing": false,
      "expires_at": null,
      "days_remaining": null
    },
    "planning": {
      "name": "Planning & Gantt",
      "enabled": true,
      "is_core": false,
      "is_trialing": true,
      "expires_at": "2025-12-04",
      "days_remaining": 14
    }
  }
}
```

```
GET /api/features/enabled
Response: {
  "enabled_modules": ["timesheets", "expenses", "planning"]
}
```

```
POST /api/features/{module}/enable
Authorization: Owner/Admin only
```

```
POST /api/features/{module}/disable
Authorization: Owner/Admin only
```

```
POST /api/features/{module}/trial
Body: { "days": 14 }
Authorization: Owner/Admin only
```

---

### Invoices & Payment Methods

```
GET /api/billing/invoices
Response: {
  "invoices": [
    {
      "id": "in_...",
      "number": "INV-001",
      "date": "2025-11-01",
      "total": "25.00",
      "status": "paid",
      "download_url": "/api/billing/invoices/in_..."
    }
  ]
}
```

```
GET /api/billing/invoices/{invoice}
Download PDF
```

```
GET /api/billing/payment-method
Response: {
  "has_payment_method": true,
  "payment_method": {
    "type": "card",
    "card": {
      "brand": "visa",
      "last4": "4242",
      "exp_month": 12,
      "exp_year": 2026
    }
  }
}
```

```
PUT /api/billing/payment-method
Body: { "payment_method": "pm_..." }
Permission: manage-billing
```

---

### Webhooks

```
POST /api/webhooks/stripe
(No authentication - Stripe signature verification)
```

**Handled Events:**
- `customer.subscription.updated` → Updates `purchased_licenses` in `tenant_licenses`
- `customer.subscription.deleted` → Logs cancellation
- `invoice.payment_succeeded` → Logs successful payment
- `invoice.payment_failed` → Logs payment failure (TODO: suspend account logic)

---

## 🔐 Authorization

### Permissions

**New Permission:** `manage-billing`

**Assigned To:**
- ✅ Owner (all permissions)
- ✅ Admin (all permissions)
- ❌ Manager (no billing access)
- ❌ Technician (no billing access)

### Policy

**TenantFeaturePolicy** (`Modules\Billing\Policies\TenantFeaturePolicy.php`)

- `manage()` - Only Owner/Admin can enable/disable modules
- `viewAny()` - All authenticated users can view feature flags
- `view()` - All authenticated users can view specific features

---

## 🛡️ Middleware

### CheckModuleAccess

**Usage:**
```php
Route::middleware(['auth:sanctum', 'module.access:planning'])->group(function () {
    // Planning module routes
});
```

**Behavior:**
- Checks if module is enabled via `FeatureManager::isEnabled()`
- Returns 403 with upgrade message if disabled:
  ```json
  {
    "message": "The 'planning' module is not enabled for your subscription.",
    "module": "planning",
    "upgrade_required": true,
    "upgrade_url": "/billing"
  }
  ```

---

## 🔄 Model Logic

### TenantFeature Model

**Key Methods:**

```php
// Check if feature is active (enabled + not expired)
isActive(): bool

// Check if in trial period
isTrialing(): bool

// Days remaining in trial
daysRemainingInTrial(): int

// Enable/disable
enable(): void
disable(): void  // Throws exception if core module

// Check if core module (cannot be disabled)
isCoreModule(): bool
```

**Scopes:**

```php
// Active features only
active()

// Expired trials only
expired()

// Features for specific tenant
forTenant(string $tenantId)
```

---

### TenantLicense Model

**Key Methods:**

```php
// Available licenses (purchased - used)
availableLicenses(): int

// Check if can add user
canAddUser(): bool

// Increment/decrement licenses
incrementLicenses(int $quantity): void
decrementLicenses(int $quantity): void

// Increment/decrement usage (with auto-upgrade)
incrementUsage(): void
decrementUsage(): void

// Utilization percentage
utilizationPercentage(): int

// Cost calculations
monthlyCost(): float
annualCost(): float
```

**Scopes:**

```php
// Licenses with availability info
withAvailableLicenses()

// Tenants at capacity
atCapacity()

// Tenants in trial
trialing()
```

---

## 📝 Integration Steps

### 1. Run Migrations

```bash
docker exec -it timesheet_app php artisan migrate
```

This creates:
- `tenant_features` table in **central DB**
- `tenant_licenses` table in **central DB**

---

### 2. Seed Permissions

```bash
docker exec -it timesheet_app php artisan db:seed --class=RolesAndPermissionsSeeder
```

This creates:
- `manage-billing` permission
- Assigns to Owner/Admin roles

---

### 3. Initialize Tenant Features/Licenses

**Option A: During Tenant Registration** (Recommended)

In `TenantController::store()` (after tenant creation):

```php
use Modules\Billing\Services\FeatureManager;
use Modules\Billing\Services\LicenseManager;

// Inside tenant registration
$featureManager = app(FeatureManager::class);
$licenseManager = app(LicenseManager::class);

// Initialize features (core modules + trial modules)
$featureManager->initializeDefaultFeatures($tenant->id, [
    'planning', // Enable planning module with trial
]);

// Initialize license (1 license for Owner)
$licenseManager->initializeLicense(
    $tenant->id,
    licenses: 1,
    billingCycle: 'monthly',
    trialDays: 14
);
```

**Option B: Manual Seeding** (For Existing Tenants)

```php
// Create feature flags for existing tenant
TenantFeature::create([
    'tenant_id' => '01K9X...',
    'module_name' => 'planning',
    'is_enabled' => true,
    'expires_at' => now()->addDays(14), // 14-day trial
]);

// Create license record
TenantLicense::create([
    'tenant_id' => '01K9X...',
    'purchased_licenses' => 5,
    'used_licenses' => 3,
    'price_per_license' => 5.00,
    'billing_cycle' => 'monthly',
    'trial_ends_at' => now()->addDays(14),
]);
```

---

## 🧪 Testing

### Test Feature Flags

```bash
# Enable planning module for tenant
curl -X POST http://localhost:8080/api/features/planning/enable \
  -H "X-Tenant: upg-to-ai" \
  -H "Authorization: Bearer {token}"

# Check enabled modules
curl -X GET http://localhost:8080/api/features/enabled \
  -H "X-Tenant: upg-to-ai" \
  -H "Authorization: Bearer {token}"

# Access protected route (should return 403 if planning disabled)
curl -X GET http://localhost:8080/api/planning/projects \
  -H "X-Tenant: upg-to-ai" \
  -H "Authorization: Bearer {token}" \
  -H "module.access:planning"
```

### Test License Management

```bash
# Get billing overview
curl -X GET http://localhost:8080/api/billing \
  -H "X-Tenant: upg-to-ai" \
  -H "Authorization: Bearer {token}"

# Preview cost for 2 licenses
curl -X POST http://localhost:8080/api/subscription/preview-cost \
  -H "X-Tenant: upg-to-ai" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"quantity": 2}'

# Add 2 licenses
curl -X POST http://localhost:8080/api/subscription/add-licenses \
  -H "X-Tenant: upg-to-ai" \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{"quantity": 2}'
```

---

## 🚀 Next Steps (Phase 2)

### Frontend Integration

1. **Create React Components:**
   - `frontend/src/components/Billing/LicenseManager.tsx` - View/manage licenses
   - `frontend/src/components/Billing/FeatureToggle.tsx` - Enable/disable modules (Admin only)
   - `frontend/src/components/Billing/InvoiceList.tsx` - View/download invoices

2. **Feature Context Provider:**
   - `frontend/src/contexts/FeatureContext.tsx`
   - Load enabled modules on login
   - Conditional rendering of menu items based on features

3. **Navigation Updates:**
   - `frontend/src/components/Layout/SideMenu.tsx`
   - Show/hide menu items based on `FeatureContext`
   - Display "Upgrade" badge for disabled modules

4. **License Warnings:**
   - Show banner when approaching license limit
   - Prevent new user creation when at capacity
   - Link to billing page for upgrades

---

### Stripe Cashier Integration

1. **Install Laravel Cashier:**
   ```bash
   composer require laravel/cashier
   php artisan vendor:publish --tag="cashier-migrations"
   php artisan migrate
   ```

2. **Update Tenant Model:**
   ```php
   use Laravel\Cashier\Billable;
   
   class Tenant extends Model {
       use Billable;
   }
   ```

3. **Create Stripe Products/Prices:**
   - Monthly: €5/user/month
   - Annual: €50/user/year (17% discount)

4. **Implement Subscription Flow:**
   - Checkout session creation
   - Webhook handling (already implemented)
   - Invoice management (already implemented)

---

### Migrate Existing Modules

1. **Timesheets** (Core module - always enabled)
   - Move to `app/Modules/Timesheets/`
   - No middleware needed (core module)

2. **Expenses** (Core module - always enabled)
   - Move to `app/Modules/Expenses/`
   - No middleware needed (core module)

3. **Travel** (Optional module)
   - Move to `app/Modules/Travel/`
   - Add `module.access:travel` middleware

4. **Planning** (Optional module)
   - Move to `app/Modules/Planning/`
   - Add `module.access:planning` middleware

---

## 📚 Documentation

- **Architecture Pattern:** Modular Monolith with Feature Flags
- **Feature Flags:** Per-tenant module enablement with trial support
- **License Management:** Seat-based billing with Stripe integration
- **Caching:** 1-hour cache for feature flags, 5-minute for licenses
- **Authorization:** Owner/Admin only for billing management
- **Middleware:** `module.access:{module}` for module protection

---

## ✅ Completion Checklist

- [x] Database migrations (tenant_features, tenant_licenses)
- [x] Models (TenantFeature, TenantLicense)
- [x] Services (FeatureManager, LicenseManager)
- [x] Controllers (Billing, Subscription, Feature, Webhook)
- [x] Middleware (CheckModuleAccess)
- [x] Routes (billing.php)
- [x] Policy (TenantFeaturePolicy)
- [x] Service Provider (BillingServiceProvider)
- [x] Permission (manage-billing)
- [x] Documentation (this file)

**Backend Status:** ✅ Complete  
**Frontend Status:** ⏳ Pending (Phase 2)  
**Stripe Integration:** ⏳ Pending (Phase 2)

---

## 🎯 Success Metrics

- **Backend API:** All endpoints functional and tested
- **Feature Flags:** Modules can be enabled/disabled per tenant
- **License Tracking:** Accurate counting of purchased/used licenses
- **Caching:** Feature checks use cached values (1-hour TTL)
- **Authorization:** Only Owner/Admin can manage billing
- **Middleware:** Protected routes return 403 if module disabled

---

**End of Documentation**
