

{
  "specVersion": "1.0",
  "name": "LaravelMultitenantBillingPennant",
  "description": "End-to-end multitenant billing and feature-gating implementation using Laravel Pennant and modular monolith architecture. Codex/Copilot MUST follow tasks in order and respect dependencies.",
  "phases": [
    {
      "id": "phase1-core-billing",
      "title": "Phase 1 – New Multitenant Billing + Features (Do NOT touch legacy code)",
      "tasks": [
        {
          "id": "modules-structure",
          "anchor": "modules-structure",
          "title": "Module structure (Laravel modular monolith)",
          "dependsOn": [],
          "steps": [
            "Create a /Modules directory at the project root if it does not exist.",
            "Create these module folders: /Modules/Timesheets, /Modules/Expenses, /Modules/Travels, /Modules/Planning, /Modules/AI, /Modules/Billing.",
            "Inside each module, create: /Routes, /Controllers, /Models, /Services, /Policies, /Database/migrations.",
            "Configure Composer PSR-4 autoloading for /Modules namespace.",
            "Modules must not depend directly on each other; cross-module logic must use Billing services, Pennant, Tenant context."
          ]
        },

        {
          "id": "multitenant-core",
          "anchor": "multitenant-core",
          "title": "Multi-tenant foundation",
          "dependsOn": ["modules-structure"],
          "steps": [
            "Ensure Tenant model exists: id, name, billing_plan, timestamps.",
            "Ensure users table has tenant_id foreign key.",
            "Create TenantResolver to resolve tenant from authenticated user (or domain/header).",
            "Create SetTenantContext middleware to store tenant in app container.",
            "Register middleware globally or on API group."
          ]
        },

        {
          "id": "pennant-setup",
          "anchor": "pennant-setup",
          "title": "Feature flags with Laravel Pennant",
          "dependsOn": ["multitenant-core"],
          "steps": [
            "Install Pennant: composer require laravel/pennant.",
            "Run php artisan pennant:install and migrate.",
            "Use database driver in config/pennant.php.",
            "Create app/Services/TenantFeatures.php with methods: enable, disable, active.",
            "Use Feature::for($tenant)->active('feature').",
            "Define feature keys: timesheets, expenses, travels, planning, ai."
          ]
        },

        {
          "id": "billing-data",
          "anchor": "billing-data",
          "title": "Billing data structure",
          "dependsOn": ["pennant-setup"],
          "steps": [
            "Create subscriptions table via migration with fields: id, tenant_id (FK), plan (starter|team|enterprise), user_limit (integer, nullable), addons (json, nullable), is_trial (boolean default false), trial_ends_at (datetime nullable), status (active|past_due|cancelled), created_at, updated_at.",
            "Create Subscription model with helper methods: isStarter(), isTeam(), isEnterprise(), isTrialActive(), isTrialExpired().",
            "Create payments table via migration with fields: id, tenant_id (FK), amount (decimal), currency (string), status (pending|paid|failed), gateway, gateway_reference, metadata (json), created_at, updated_at.",
            "Create Payment model.",
            "Update config/billing.php:",
            "- Starter: price = 0€, included_users = 2, features = ['timesheets','expenses'].",
            "- Team: price_per_user = 25€, features = ['timesheets','expenses','travels'], addons = { planning: 0.18, ai: 0.18 }. ",
            "- Enterprise: price_per_user = 35€, features = ['timesheets','expenses','travels','planning','ai'], addons = {}.",
            "- Trial: enabled = true, days = 15, plan = enterprise, user_limit = 999999.",
            "Define feature_mapping for Pennant per plan."
          ]
        },

        {
          "id": "billing-services",
          "anchor": "billing-services",
          "title": "Billing and pricing services",
          "dependsOn": ["billing-data"],
          "steps": [
            "Create PriceCalculator service returning: plan, is_trial, user_count, base_subtotal, addons, total, requires_upgrade, features.",
            "Starter: total = 0, requires_upgrade = true if >2 users.",
            "Trial: treat plan as enterprise with total = 0 and all features active.",
            "Team: base_subtotal = 25 * users; planning_addon = base_subtotal * 0.18 if enabled; ai_addon = (base_subtotal + planning_addon) * 0.18 if enabled.",
            "Enterprise: base_subtotal = 35 * users; no addons.",
            "Create PlanManager with methods: startTrialForTenant(), endTrialForTenant(), downgradeFromTrial(), applyPlan().",
            "PlanManager.syncFeaturesForSubscription(): maps plan + addons to Pennant activation.",
            "Implement PaymentGatewayInterface and FakeCreditCardGateway: simulate card approval, then call PlanManager->applyPlan() with metadata['plan'] and metadata['addons']."
          ]
        },

        {
          "id": "billing-api",
          "anchor": "billing-api",
          "title": "Billing API endpoints",
          "dependsOn": ["billing-services"],
          "steps": [
            "Create BillingController in /Modules/Billing.",
            "Add GET /api/billing/summary using PriceCalculator.",
            "Add POST /api/billing/upgrade-plan with pending payment.",
            "Add POST /api/billing/toggle-addon.",
            "Add POST /api/billing/checkout/start.",
            "Add POST /api/billing/checkout/confirm applying PlanManager on success."
          ]
        },

        {
          "id": "module-locking",
          "anchor": "module-locking",
          "title": "Feature-based module access",
          "dependsOn": ["pennant-setup", "billing-services"],
          "steps": [
            "Create EnsureModuleEnabled middleware.",
            "Check TenantFeatures::active($tenant, $moduleKey).",
            "Apply to: Travels, Planning, AI routes.",
            "Timesheets/Expenses always enabled."
          ]
        },

        {
          "id": "tests",
          "anchor": "tests",
          "title": "Billing + Feature tests",
          "dependsOn": ["billing-api", "module-locking"],
          "steps": [
            "Starter cannot access Travels (403).",
            "Team can access Travels (200).",
            "Planning addon adds +18%.",
            "AI addon adds +18% only in Enterprise.",
            "Starter >2 users triggers upgrade rule.",
            "Pennant flags remain tenant-scoped."
          ]
        }
      ]
    },

    {
      "id": "phase2-migration",
      "title": "Phase 2 – Safe migration & cleanup",
      "tasks": [
        {
          "id": "future-extensions",
          "anchor": "future-extensions",
          "dependsOn": ["tests"],
          "steps": [
            "Do NOT modify legacy billing automatically.",
            "Mark legacy code with: // LEGACY-BILLING.",
            "New billing must ONLY use Pennant + Subscription + Billing Services.",
            "New routes MUST use EnsureModuleEnabled.",
            "Refactors must happen only in dedicated PRs."
          ]
        },

        {
          "id": "migration-phase",
          "anchor": "migration-phase",
          "dependsOn": ["future-extensions"],
          "steps": [
            "Document current billing logic for each module.",
            "Replace legacy guards with Pennant checks.",
            "Module-by-module migration with full test runs.",
            "Ensure no tenant loses module access.",
            "Mark migrated legacy sections as deprecated."
          ]
        },

        {
          "id": "legacy-compatibility",
          "anchor": "legacy-compatibility",
          "dependsOn": ["migration-phase"],
          "steps": [
            "Do not delete legacy code until all modules are migrated.",
            "Use feature flags to shut off old billing paths.",
            "Final cleanup: remove unused tables/routes/code.",
            "Document final billing architecture."
          ]
        }
      ]
    }
  ],

  "dependencyOrder": [
    "modules-structure",
    "multitenant-core",
    "pennant-setup",
    "billing-data",
    "billing-services",
    "billing-api",
    "module-locking",
    "tests",
    "future-extensions",
    "migration-phase",
    "legacy-compatibility"
  ]
}