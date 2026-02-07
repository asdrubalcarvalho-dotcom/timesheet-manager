# TimePerk Cortex — Copilot Instructions

Use these instructions for **all code you generate or modify in this repository**.

## MUST (non‑negotiable)
- **Tenancy & DBs**
  - This is a multi-tenant app: central DB `timesheet` + tenant DBs `timesheet_{slug}`.
  - Tenant is resolved by **subdomain** or **`X-Tenant`** header.
  - **Do not** use `Model::on($connection)` in controllers.
  - If you write **raw DB queries**, explicitly use `DB::connection('tenant')` (or `central` when applicable).
- **Routing boundaries**
  - Central API routes live in `backend/routes/api.php`.
  - Tenant API routes live in `backend/routes/tenant.php`.
  - Place new endpoints in the correct file and ensure they run in the correct tenant/central context.
- **Authorization**
  - Always enforce authorization via **Policies** (e.g., `$this->authorize('update', $timesheet)`), and respect the triple roles on `project_members` (`project_role`, `expense_role`, `finance_role`).
  - If a change affects permissions, update the API permission metadata returned to the frontend.
- **Module boundaries**
  - The codebase is a modular monolith under `Modules/`.
  - **Do not** introduce direct cross-module coupling. Use existing services/interfaces/feature flags when coordination is needed.
- **Frontend API access**
  - In the React app, **always** call the backend using `frontend/src/services/api.ts` so auth and `X-Tenant` headers are injected.
- **Security**
  - Never add or output secrets, tokens, private keys, or real credentials.
  - Use secure defaults; validate/sanitize inputs and handle errors.

## SHOULD (preferred patterns)
- **Laravel**
  - Prefer Form Requests in `backend/app/Http/Requests/` over inline `validate()`.
  - Keep controllers thin; put business logic into Services.
- **React**
  - Use functional components + hooks; keep TypeScript strict.
  - Type API responses using the existing types (see `frontend/src/types/index.ts`).
- **Quality**
  - When you change behavior, add/update tests.
  - Follow existing project conventions and naming.

## OPTIONAL (when helpful)
- If you’re unsure which pattern to use, search the codebase for the closest existing example and follow it.
- Provide a short verification checklist (commands/steps) for reviewers.

## Read first (when relevant)
- `Modules/README.md`
- `docs/DEVELOPMENT_GUIDELINES.md`
- `docs/PERMISSION_MATRIX.md`
- Feature specs (e.g., `docs/EXPENSE_WORKFLOW_SPEC.md`)
- Feature flags: `docs/PENNANT_INTEGRATION_IMPLEMENTATION.md`
- `docs/COPILOT_PLAYBOOK.md`

> Operational troubleshooting (Docker/cache/debug playbooks) lives in `docs/COPILOT_PLAYBOOK.md`.