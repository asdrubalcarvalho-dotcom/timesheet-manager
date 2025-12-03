{
  "specVersion": "1.0",
  "name": "LaravelMultitenantBillingPennantFrontend",
  "description": "Frontend integration for multitenant billing and feature-gating using React Context, Pennant-backed APIs and modular UI components. Codex/Copilot MUST follow tasks in order and respect dependencies.",
  "phases": [
    {
      "id": "phase-frontend-billing",
      "title": "Phase 1.5 – New Billing + Feature Frontend (Do NOT break existing flows)",
      "tasks": [
        {
          "id": "frontend-bootstrap",
          "anchor": "frontend-bootstrap",
          "title": "Frontend bootstrap for billing integration",
          "dependsOn": [],
          "steps": [
            "Ensure backend Phase 1 billing endpoints are available and stable: /api/billing/summary, /api/billing/upgrade-plan, /api/billing/toggle-addon, /api/billing/checkout/start, /api/billing/checkout/confirm.",
            "Ensure API base URL configuration is correct.",
            "Do NOT modify existing auth or routing logic unnecessarily.",
            "All new billing logic must be implemented as new components, contexts, or API files."
          ]
        },
        {
          "id": "billing-api-client",
          "anchor": "billing-api-client",
          "title": "Billing API client module",
          "dependsOn": ["frontend-bootstrap"],
          "steps": [
            "Create frontend/src/api/billing.ts.",
            "Implement: getBillingSummary(), upgradePlan(), toggleAddon(), startCheckout(), confirmCheckout().",
            "Return typed responses and normalize errors.",
            "Ensure billing.ts is the only place where billing HTTP calls occur."
          ]
        },
        {
          "id": "billing-context",
          "anchor": "billing-context",
          "title": "BillingContext (React Context)",
          "dependsOn": ["billing-api-client"],
          "steps": [
            "Create BillingContext in frontend/src/contexts/BillingContext.tsx.",
            "Expose: billingSummary, loading, error, refreshSummary(), requestUpgradePlan(), toggleAddon(), startCheckout(), confirmCheckout().",
            "billingSummary must contain: plan, users, modules_enabled, addons, base_subtotal, total, requires_upgrade.",
            "Wrap the app (or billing routes) with BillingContext.Provider.",
            "Do NOT embed billing logic in UI components."
          ]
        },
        {
          "id": "feature-context-update",
          "anchor": "feature-context-update",
          "title": "FeatureContext update",
          "dependsOn": ["billing-context"],
          "steps": [
            "Refactor FeatureContext to derive module flags from billingSummary.modules_enabled.",
            "Expose computed flags such as hasTravels, hasPlanning, hasAI.",
            "FeatureContext must be read-only; all mutations go through BillingContext.",
            "Mark old feature flags as deprecated."
          ]
        },
        {
          "id": "sidebar-integration",
          "anchor": "sidebar-integration",
          "title": "Sidebar/nav integration with features",
          "dependsOn": ["feature-context-update"],
          "steps": [
            "Refactor sidebar to show/hide modules based on FeatureContext flags.",
            "Travels, Planning, and AI items appear only when enabled.",
            "Do NOT hardcode plan names or module logic in the sidebar."
          ]
        },
        {
          "id": "billing-page-ui",
          "anchor": "billing-page-ui",
          "title": "BillingPage UI",
          "dependsOn": ["billing-context", "sidebar-integration"],
          "steps": [
            "Create frontend/src/components/Billing/BillingPage.tsx.",
            "Use BillingContext to display: plan, is_trial, trial countdown (days remaining), users, addons, module access, base_subtotal, total, requires_upgrade.",
            "If is_trial === true: show Enterprise Trial banner, show trial end date, hide payment buttons, show Upgrade CTA only if user wants to upgrade early.",
            "If trial expired and users > 2: show 'Trial expired – upgrade required' banner and disable modules except Timesheets/Expenses.",
            "If trial expired and users ≤ 2: automatically reflect Starter plan UI.",
            "Add UI to upgrade plan (Starter → Team → Enterprise).",
            "Add UI to toggle addons (only for Team): planning +18%, ai +18%.",
            "Add simulated credit card checkout form and handle success/failure messages.",
            "Ensure UI never calculates pricing locally; always use billingSummary from the API.",
            "Ensure layout matches existing design system."
          ]
        },
        {
          "id": "module-guards",
          "anchor": "module-guards",
          "title": "Frontend module guards",
          "dependsOn": ["feature-context-update"],
          "steps": [
            "Create RequireFeature component that checks FeatureContext flags.",
            "If feature disabled, render LockedModule UI or redirect.",
            "Wrap TravelsPage, PlanningPage, AIPage with RequireFeature."
          ]
        },
        {
          "id": "frontend-tests",
          "anchor": "frontend-tests",
          "title": "Frontend tests",
          "dependsOn": ["billing-page-ui", "module-guards"],
          "steps": [
            "Test BillingPage for each plan (Starter/Team/Enterprise) with mocked BillingContext.",
            "Test planning and AI addons update totals correctly (+18%).",
            "Test RequireFeature prevents access to locked modules.",
            "Test flows: Starter → Team upgrade reflects new modules."
          ]
        }
      ]
    },
    {
      "id": "phase-frontend-migration",
      "title": "Phase 2 – Align old UI with new billing/feature model",
      "tasks": [
        {
          "id": "frontend-legacy-audit",
          "anchor": "frontend-legacy-audit",
          "dependsOn": ["frontend-tests"],
          "steps": [
            "Find all legacy module checks (PRO, Starter, Team, Enterprise).",
            "Mark with // LEGACY-FEATURE-FLAG or // LEGACY-BILLING-UI.",
            "Document these locations for refactor."
          ]
        },
        {
          "id": "frontend-migration-phase",
          "anchor": "frontend-migration-phase",
          "dependsOn": ["frontend-legacy-audit"],
          "steps": [
            "Replace legacy checks with FeatureContext/BillingContext.",
            "Use RequireFeature for guarded routes.",
            "Run tests after each migration step.",
            "Coordinate with backend Phase 2."
          ]
        },
        {
          "id": "frontend-cleanup",
          "anchor": "frontend-cleanup",
          "dependsOn": ["frontend-migration-phase"],
          "steps": [
            "Remove unused flags and dead billing UI code.",
            "Remove LEGACY comments.",
            "Finalize documentation on BillingContext + FeatureContext as the new model."
          ]
        }
      ]
    }
  ],
  "dependencyOrder": [
    "frontend-bootstrap",
    "billing-api-client",
    "billing-context",
    "feature-context-update",
    "sidebar-integration",
    "billing-page-ui",
    "module-guards",
    "frontend-tests",
    "frontend-legacy-audit",
    "frontend-migration-phase",
    "frontend-cleanup"
  ]
}
