# Copilot Playbook — TimePerk Cortex (Detailed)

This document is the **detailed companion** to `.github/copilot-instructions.md`.
Use it for troubleshooting, workflows, and repo-specific implementation notes.

---

## 1) Big Picture
- Laravel 11 API in `backend/` and React 18 + Vite SPA in `frontend/`, run via Docker Compose.
- Multi-tenant: central DB `timesheet` + tenant DBs `timesheet_{slug}`; tenant resolved via subdomain or `X-Tenant` header.
- Modular monolith: top-level `Modules/` with per-module `Routes/Controllers/Models/Services/Policies/Database/migrations`.

## 2) Critical Architecture Notes
- Tenancy middleware resolves tenant; avoid `Model::on($connection)` in controllers.
- Raw DB queries must specify the correct connection explicitly:
  - tenant: `DB::connection('tenant')`
  - central: `DB::connection('central')` (only when needed)
- Central vs tenant routes:
  - central: `backend/routes/api.php`
  - tenant: `backend/routes/tenant.php`
- Feature gating:
  - `EnsureModuleEnabled` middleware + `TenantFeatures::active()`
  - See `Modules/README.md`.
- Authorization:
  - triple roles on `project_members` (`project_role`, `expense_role`, `finance_role`)
  - enforce via Policies (Timesheet/Expense) and manager segregation
  - See `docs/PERMISSION_MATRIX.md`.

## 3) Frontend Patterns
- Always use `api` from `frontend/src/services/api.ts` so auth + `X-Tenant` headers are injected.
- UI enables/disables actions using permission metadata returned by API responses.

## 4) Workflows

### Start environment
```bash
docker-compose up -d
```

### After first start or after resetting volumes
If you did a full reset (e.g., `down -v`) you will likely need to (re)seed permissions:
```bash
docker-compose exec app php artisan db:setup-permissions
```
See `docs/DATABASE_PERMISSIONS.md`.

### Migrations
- Central:
```bash
docker-compose exec app php artisan migrate
```
- Tenant:
```bash
docker-compose exec app php artisan tenants:migrate <slug>
```

### Demo tenant credentials
Do **not** store real credentials in this playbook.
If you need demo access, keep it in a secure place (e.g., password manager) or in a non-committed local note.

## 5) Conventions and Gotchas
- Form Requests live in `backend/app/Http/Requests/`; prefer them over inline `validate()`.
- Rate limits configured in `backend/routes/api.php` via `throttle:*` groups.
- Module independence: no direct cross-module dependencies; use services/feature flags for coordination.

## 6) Docs to Read
- `docs/DEVELOPMENT_GUIDELINES.md`
- `docs/EXPENSE_WORKFLOW_SPEC.md` (or other feature spec)
- `docs/PERMISSION_MATRIX.md`
- `docs/PENNANT_INTEGRATION_IMPLEMENTATION.md` (feature flags)
- `docs/OWNER_PROTECTION_SYSTEM.md` (owner role security patterns)
- `docs/FINANCE_ROLE_IMPLEMENTATION.md` (finance role / approvals)

### Billing & Stripe Integration
- `docs/PHASE_10_STRIPE_WEBHOOKS_IMPLEMENTATION.md`
- `docs/PHASE_4_CUSTOMER_PORTAL_IMPLEMENTATION.md`
- `docs/BILLING_STRIPE_PRODUCTION_AUDIT.md`
- `docs/STRIPE_PAYMENT_SETUP.md`
- `docs/BILLING_CALCULATION_TEST_GUIDE.md`

### Testing & QA
- `docs/FRONTEND_TENANT_TESTING_GUIDE.md`
- `docs/tenant_onboarding_tests.md`

---

## 7) Code Style Conventions

### Laravel backend
- Use Form Requests for validation (avoid inline `validate()`).
- Always authorize with Policies, e.g.:
  - `$this->authorize('update', $timesheet)`
- Keep controllers thin; move logic into Services.
- Return permission metadata in API responses when the frontend needs it.

### React frontend
- TypeScript strict mode.
- Functional components + hooks.
- Type API responses using existing types in `frontend/src/types/index.ts`.
- Use the `api.ts` instance for all HTTP calls.

### Database
- ULID for tenant IDs (26 chars).
- Auto-incrementing IDs for business tables.
- Soft deletes are not used (hard deletes only).
- Use enum columns for status fields (avoid string lookup tables unless existing).

---

## 8) Debugging Playbook

### 8.1 Docker / File-sync / Caching issues
There are cases where changes don’t appear due to:
- a static frontend build being served by Nginx,
- PHP opcache / Laravel caches,
- stale container images or volumes.

**Recommended approach (symptom-based):**

#### Step A — Try the light fixes first
Use these when changes should be visible but aren’t.

- Backend cache clear:
```bash
docker-compose exec app php artisan optimize:clear
```
- Restart services (no rebuild):
```bash
docker-compose restart
```

#### Step B — Rebuild when you see cache symptoms
If you see any of these symptoms, do a rebuild:
- Frontend UI changes do not appear in the browser.
- New API route returns 404 after being added.
- CORS behavior doesn’t change after editing `cors.php`.
- Middleware/guards don’t behave as expected after changes.
- `.env` changes don’t take effect (after confirming file is updated).

Rebuild (keeps volumes):
```bash
docker-compose up -d --build
```

#### Step C — Full reset only when truly needed
Use a full reset **only** when you suspect broken volumes, schema drift, or corrupted state.
**Warning:** this deletes volumes and local DB data.

```bash
docker-compose down -v
# then
docker-compose up -d --build
```

---

### 8.2 Tenant context issues
```php
// Check current tenant context
// (Run inside a controller / tinker as appropriate)
dd(tenancy()->initialized, tenant()?->id);
```

Force tenant context in Tinker:
```bash
docker-compose exec app php artisan tinker
```
```php
// Example:
// tenancy()->initialize(Tenant::find('<ULID>'));
```

### 8.3 API authentication issues (Sanctum)
If tokens are tenant-scoped, confirm you are checking the **tenant** DB.
Example query:
```bash
docker-compose exec database mysql -u timesheet -psecret -e "USE timesheet_<slug>; SELECT * FROM personal_access_tokens LIMIT 20;"
```

### 8.4 Frontend tenant header missing
In browser DevTools → Network → Request Headers:
- confirm `X-Tenant: <slug>` exists (or tenant comes from subdomain).

---

## 9) Quick Reference

| Task | Command |
|------|---------|
| Start dev environment | `docker-compose up -d` |
| View logs | `docker-compose logs -f app` |
| Run backend tests | `docker-compose exec app php artisan test` |
| Clear Laravel cache | `docker-compose exec app php artisan optimize:clear` |
| Rebuild containers | `docker-compose up -d --build` |
| Full reset (deletes DB volumes) | `docker-compose down -v && docker-compose up -d --build` |
| Register new tenant | `POST /api/tenants/register` (see `docs/TENANT_DEPLOYMENT.md`) |

> Rule of thumb: prefer **light fixes** first; use **rebuild** when you see cache symptoms; use **down -v** only when you must wipe state.
