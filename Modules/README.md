# Modules - Laravel Modular Monolith Architecture

## Overview
This directory implements a **modular monolith** architecture for TimePerk Cortex, where each module is self-contained with its own routes, controllers, models, services, policies, and migrations.

## Module Structure
```
Modules/
├── Timesheets/      # Core timesheet management (always enabled)
├── Expenses/        # Core expense management (always enabled)
├── Travels/         # Travel segments (requires Team+ plan or >2 users)
├── Planning/        # Gantt planning (requires addon)
├── AI/              # AI insights (requires Enterprise + addon)
└── Billing/         # Subscription & payment management
```

## Directory Structure per Module
Each module follows a consistent structure:
```
ModuleName/
├── Routes/          # Module-specific route definitions
├── Controllers/     # HTTP request handlers
├── Models/          # Eloquent models (if module needs own tables)
├── Services/        # Business logic and service layer
├── Policies/        # Authorization policies
└── Database/
    └── migrations/  # Module-specific database migrations
```

## Key Principles

### 1. Module Independence
- **NO direct dependencies** between modules
- Cross-module communication MUST go through:
  - Billing services (`PriceCalculator`, `PlanManager`)
  - Laravel Pennant feature flags
  - Tenant context resolution

### 2. Feature Gating
- Modules use `EnsureModuleEnabled` middleware
- Feature flags checked via `TenantFeatures::active($tenant, 'module_name')`
- Always enabled: `timesheets`, `expenses`
- Conditionally enabled: `travels`, `planning`, `ai`

### 3. Multi-Tenant Isolation
- All modules respect tenant context
- Database queries use tenant connection when initialized
- Models use tenant-scoped global scopes where needed

## Module-to-Plan Mapping

| Module | Starter | Team | Enterprise |
|--------|---------|------|------------|
| **Timesheets** | ✅ | ✅ | ✅ |
| **Expenses** | ✅ | ✅ | ✅ |
| **Travels** | ❌ (unlocked at >2 users) | ✅ | ✅ |
| **Planning** | ❌ | ➕ addon (+18%) | ➕ addon (+18%) |
| **AI** | ❌ | ❌ | ➕ addon (+18%) |

## Autoloading
Configured in `backend/composer.json`:
```json
"autoload": {
    "psr-4": {
        "Modules\\": "../Modules/"
    }
}
```

Run `composer dump-autoload` after creating new classes.

## Adding New Modules
1. Create directory structure matching existing modules
2. Add feature flag in `TenantFeatures` service
3. Register routes in module's `/Routes` directory
4. Apply `EnsureModuleEnabled` middleware if conditionally enabled
5. Update `config/billing.php` with pricing rules
6. Document in this README

## Related Documentation
- `docs/TASK LIST BILLING.md`: Full billing architecture specification
- `config/billing.php`: Pricing rules and plan definitions
- `app/Services/TenantFeatures.php`: Feature flag management
- `app/Http/Middleware/EnsureModuleEnabled.php`: Access control

---

**Phase**: Phase 1 - Core Billing (New Architecture)  
**Status**: In Development  
**Last Updated**: 2025-11-21
