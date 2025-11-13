Perfeito — excelente prática de engenharia 👏
A instrução que queres é algo que faz o Codex/Copilot atuar de forma inteligente e crítica, analisando o código existente e escolhendo sempre a melhor abordagem (ou pedindo confirmação antes de mudar algo).

Aqui está o ficheiro completo atualizado em Markdown e inglês, com essa nova secção incluída no estilo natural e profissional — pronto para colocares em
/docs/saas_multitenant_codex_instructions.md 👇

⸻


# 🧭 TimePerk SaaS — Multitenant Implementation Guide (for Codex/Copilot)

## 🎯 Objective
Transform the existing **TimePerk (Timesheet + Payroll)** project into a **modern SaaS multitenant platform** using Laravel 11 and React (Vite + TypeScript).

The system must support multiple companies (tenants), each with isolated data, authentication, and user roles — while sharing a single application codebase.

---

## 🧱 Tech Stack
- **Backend:** Laravel 11 (PHP 8.3)
- **Frontend:** React 18 + TypeScript (Vite)
- **Database:** MySQL 8
- **Auth:** Laravel Breeze (API mode)
- **Multitenancy:** `stancl/tenancy` v4
- **Roles/Permissions:** `spatie/laravel-permission`
- **Activity Log:** `spatie/laravel-activitylog`
- **Containerization:** Docker Compose (optional)
- **Deployment target:** SaaS or self-hosted VM

---

## 🧩 Requirements
1. Each **tenant (company)** partilha a base central atual (single-DB multi-tenancy) e os seus registos carregam `tenant_id` para isolamento. O modo “1 BD central + 1 BD operacional por tenant” ainda não está implementado; se quisermos ativá-lo teremos de ligar o `DatabaseTenancyBootstrapper`, preparar migrations em `database/migrations/tenant`, correr os jobs `CreateDatabase/MigrateDatabase` no onboarding e sincronizar a camada comum (auth/tenants) com cada BD TimeSheet independente.
2. Tenant registration (onboarding form) automatically creates:
   - a `Company` record  
   - an `Admin` user linked to that tenant
3. Authentication and authorization are **tenant-scoped**.
4. Middleware enforces tenant context in every route.
5. Each tenant has its own dashboard: `/app/{tenant}/dashboard`
6. Shared models and migrations — no code duplication.
7. Tenants access the app via dedicated subdomains (e.g. `acme.app.timeperk.com`) that all point to the shared backend/database; if a subdomain cannot be provisioned automatically, raise a notification so Ops can create the DNS record manually, but the tenant must still exist centrally so API access works via header-based fallback.

---

## ⚙️ Implementation Steps

### 1️⃣ Install required packages
```bash
composer require stancl/tenancy
composer require spatie/laravel-permission
composer require spatie/laravel-activitylog
composer require laravel/breeze --dev

2️⃣ Configure tenancy
	•	Publish config:

php artisan vendor:publish --tag=tenancy


	•	Add tenancy middleware in app/Http/Kernel.php.
	•	Register tenant routes under routes/tenant.php.

3️⃣ Migrations
	•	Add tenant_id to main tables (e.g., users, projects, timesheets).
	•	Use Laravel migration hooks from stancl/tenancy for tenant-based schema.

4️⃣ Models

Create or update:

Tenant, User, Project, Timesheet, Company

Each model should include the BelongsToTenant trait.

5️⃣ Authentication (Breeze API)
	•	Extend registration to include tenant_id.
	•	During signup → create new tenant + admin.
	•	Modify guards to enforce tenant isolation.
	•	Require `X-Tenant: <tenant-slug>` (or `?tenant=<slug>`) on login/logout/user routes so sessions/tokens are scoped to that tenant.

6️⃣ Roles & Permissions

Use spatie/laravel-permission to define:
	•	Admin, Manager, Employee
Each tenant has its own permission set.

7️⃣ React Frontend (Vite)
	•	Add subdomain or tenant parameter to API base URL.
	•	Store tenant_id in auth context after login.
	•	Tenant dashboards accessible via /app/{tenant}/dashboard.

8️⃣ Activity Logging

Integrate spatie/laravel-activitylog for per-tenant actions.

### 🔗 Tenant Routing & Domain Provisioning
- **Base domain:** keep a shared primary domain/subdomain (e.g. `app.timeperk.com`) that always hits the central Laravel stack + single shared database.
- **Tenant subdomains:** on onboarding, derive the slug (e.g. `acme`) and register `acme.app.timeperk.com` pointing to the same stack. The application should attempt any automated DNS/edge update available; if automation isn’t wired yet, emit a notification (email/Slack/webhook) so Ops can create the DNS record manually. The tenant is considered active as soon as the record exists in the central DB.
- **Config knobs:** set `TENANCY_BASE_DOMAIN` (e.g. `app.timeperk.com`), `TENANCY_AUTO_PROVISION_DOMAINS=true|false` to indicate whether DNS automation is available, and `TENANCY_OPS_EMAIL` for manual notifications.
- **Auto-registration (dev helper):** enable `TENANCY_AUTO_REGISTER_ON_REQUEST=true` in non-prod environments to auto-create the `Domain` record the first time someone hits `https://slug.<base-domain>`. This is handy for localhost + `/etc/hosts` setups; keep it `false` in prod so domains are only created during onboarding/approval.
- **Fallback access:** even if DNS is pending, API calls can target the base domain as long as they include `X-Tenant: <slug>` (or `?tenant=<slug>`). This guarantees no downtime while waiting for subdomain propagation.
- **Validation:** every request must resolve the tenant slug/ID before touching the shared DB, ensuring each tenant only sees its own rows while still using the common schema.

⸻

🚀 Development Flow
	1.	Clone the project
	2.	Configure .env with tenancy database settings
	3.	Run migrations:

php artisan migrate


	4.	Seed demo tenants & backfill existing data:

php artisan tenancy:bootstrap-demo


	5.	Launch app:

php artisan serve
npm run dev



⸻

💡 Example Tenant Flow
	1.	POST /api/register → creates new company and admin user
	2.	Admin logs in → redirected to /app/{tenant}/dashboard
	3.	Admin invites employees → each tied to same tenant_id
	4.	All CRUD operations (projects, timesheets, payroll exports) are automatically scoped to the tenant.

⸻

🧠 Codex / Copilot Instructions

✅ Analysis First, Then Action

Before generating or modifying code:
	1.	Analyze the existing project structure, dependencies, and configuration.
	2.	Determine whether the functionality already exists or if there’s a better, more modern or stable alternative.
	3.	Always choose the best available option — either what the project already implements well, or a newer, cleaner library or pattern.
	4.	Ask for confirmation before major changes that may alter architecture, routes, authentication, or database schema.

This ensures the generated code remains compatible, clean, and aligned with the existing logic.

⸻

🧩 Code generation goals
	•	Scaffold models and migrations for multitenancy
	•	Integrate tenancy middleware
	•	Adapt API routes and controllers
	•	Generate frontend tenant-aware components
	•	Provide demo seeder data for testing

⸻

🧾 Output Expectations
	•	Laravel backend fully ready for multitenancy
	•	React dashboard working with tenant context
	•	Clear separation between system-level and tenant-level routes
	•	Reusable modular structure for upcoming modules (Payroll, HR, Billing)

⸻

🔮 Future Modules (optional)
	•	Billing & Subscription with Stripe or Paddle
	•	Advanced RBAC per department
	•	AI-based timesheet analysis (Ollama / LangChain integration)

⸻

Filename:
/docs/saas_multitenant_codex_instructions.md

Awesome — here’s an add-on section you can append to /docs/saas_multitenant_codex_instructions.md.
It makes Codex/Copilot validate versions, dependencies, schema and routes first, and confirm with you before any breaking change.

## ✅ Automated Validation & Compatibility Checks (Run Before Any Change)

**Goal:** Ensure changes won’t break Laravel/React, multitenancy, or existing data.  
**Policy:** *Analyze → Validate → Ask confirmation → Apply changes.*

### 1) Environment & Tooling Versions
Create `scripts/validate_env.sh` and run it before coding:

```bash
#!/usr/bin/env bash
set -euo pipefail

echo "🔎 Validating local toolchain..."
php -v | head -n1
composer --version
node -v
npm -v

echo "🔎 Validating Laravel & project packages..."
php artisan --version || { echo "❌ Laravel not available"; exit 1; }
composer info | grep -E "laravel/framework|stancl/tenancy|spatie/laravel-permission|spatie/laravel-activitylog" || true

echo "🔎 Checking Node deps..."
jq -r '.dependencies, .devDependencies' package.json | grep -E "react|vite|typescript" || true

echo "✅ Env check done."

Copilot/Codex: Always run this script and halt if PHP < 8.2, Node < 18, or Laravel < 10.

⸻

2) Dependency & Config Consistency
	•	Composer constraints to keep:
	•	laravel/framework:^10.0|^11.0
	•	stancl/tenancy:^4.0
	•	spatie/laravel-permission:^6.0
	•	spatie/laravel-activitylog:^4.0
	•	NPM constraints to keep:
	•	react:^18, vite:^5, typescript:^5

Codex MUST:
	1.	Read composer.json / package.json.
	2.	Prefer existing libs if already present and compatible.
	3.	Propose upgrades only if they are minor/patch or provide a strong benefit; ask for confirmation before major upgrades.

⸻

3) Pre-Change Project Audit

Codex should run (or script) the following:

# PHP static analysis & formatting
./vendor/bin/phpstan analyse --memory-limit=1G || true
./vendor/bin/pint --test || true

# Unit tests (if present)
php artisan test --testsuite=Unit || true

# Lint TS/React
npm run type-check || npx tsc --noEmit
npm run lint || npx eslint "resources/**/*.{ts,tsx,js}"

# Route & config sanity
php artisan route:list > storage/logs/route-list.txt
php artisan config:cache && php artisan route:cache && php artisan view:cache
php artisan optimize:clear

If any step fails: Codex must stop and ask how to proceed with a summary of errors.

⸻

4) Database Safety Checks (Tenancy)

Before migrations or schema changes:
	1.	Export current schema

php artisan schema:dump --prune


	2.	Backup database (example MySQL):

mysqldump -u"$DB_USERNAME" -p"$DB_PASSWORD" "$DB_DATABASE" > backup_$(date +%F_%H%M).sql


	3.	Dry-run migration (simulate via CI or a disposable DB container).

Codex MUST ask for confirmation before:
	•	Adding/removing columns on tenant-scoped tables.
	•	Changing tenant resolution (subdomain → path) or auth guards.
	•	Switching tenancy mode (single-DB → DB-per-tenant).

⸻

5) Non-Breaking Migration Pattern

When altering tables used in production:
	1.	Add nullable column(s).
	2.	Backfill data with an idempotent seeder/command.
	3.	Flip code to read new column(s).
	4.	Make column(s) required in a second migration.
	5.	Remove legacy fields (final migration).

Codex must propose this plan and wait for approval.

⸻

6) Feature Flags for Risky Changes

Use a simple config or database-stored flags to toggle new behavior:

// config/features.php
return [
  'tenancy_subdomain_routing' => true,
  'new_timesheet_engine' => false,
];

// Usage
if (config('features.new_timesheet_engine')) {
   // new logic
} else {
   // legacy logic
}

Codex should guard new modules or refactors (routing, auth, tenancy resolution) behind feature flags and default them off until approved.

⸻

7) Git Hooks & CI (optional but recommended)

Pre-commit (Husky)

npx husky-init && npm install
# .husky/pre-commit
#!/bin/sh
. "$(dirname "$0")/_/husky.sh"
npm run lint && npm run type-check
php -v >/dev/null && ./vendor/bin/pint --test

GitHub Actions (Laravel + Node)
Create .github/workflows/ci.yml:

name: CI
on: [push, pull_request]
jobs:
  build:
    runs-on: ubuntu-latest
    services:
      mysql:
        image: mysql:8
        env:
          MYSQL_DATABASE: app
          MYSQL_ROOT_PASSWORD: root
        ports: ['3306:3306']
        options: >-
          --health-cmd="mysqladmin ping -h 127.0.0.1 -proot"
          --health-interval=10s --health-timeout=5s --health-retries=5
    steps:
      - uses: actions/checkout@v4
      - uses: shivammathur/setup-php@v2
        with:
          php-version: '8.3'
          tools: composer
      - run: composer install --no-interaction --prefer-dist
      - run: cp .env.example .env && php artisan key:generate
      - run: php artisan migrate --force
      - run: ./vendor/bin/pint --test
      - run: ./vendor/bin/phpstan analyse --no-progress --memory-limit=1G
      - uses: actions/setup-node@v4
        with:
          node-version: '20'
      - run: npm ci
      - run: npm run build
      - run: php artisan test --testsuite=Unit || true


⸻

8) Confirmation Gate (Human-in-the-Loop)

Before applying any major change, Codex must present:
	•	What will change (files, routes, tables, env, libs).
	•	Why this option (pros/cons vs current approach).
	•	Rollback plan (how to revert quickly).
	•	Impact on tenants (downtime, migrations, data risk).

Proceed only after explicit approval.

⸻

9) Observability & Audit Guarantees
	•	Log every tenant-resolved request (tenant id, user id, route, response time) via centralized logger; redact PII before shipping logs.
	•	Forward key metrics (request rate, error %, queue latency) with tags per tenant so noisy neighbors are easy to isolate.
	•	Push security/audit events (role changes, impersonation, payroll exports) into `spatie/laravel-activitylog` + an external sink (e.g., OpenSearch) for long-term retention.
	•	Add health endpoints (`/healthz`, `/readyz`) that check tenancy bootstrapping, queue workers, and DB connectivity. Codex must keep them lightweight (<100 ms) and unauthenticated but rate-limited.

⸻

10) Tenant QA Playbook
Codex should keep the following smoke checklist in `docs/qa/tenant_smoke.md` (create/update as needed) and run it after risky changes:
	1.	Create tenant via onboarding API and confirm default Admin role + permissions.
	2.	Login as admin, manager, employee — ensure dashboards show only tenant-scoped data.
	3.	Create sample projects/timesheets, export payroll, and verify records are tagged with the correct tenant_id in DB.
	4.	Delete a tenant in a sandbox and verify cascading cleanup (users, jobs, cached routes).
	5.	Run frontend build with `VITE_TENANT=demo` to ensure tenant-aware routing works for both subdomain and path modes.

⸻

11) Deployment & Rollback Guardrails
	•	All rollout steps must be idempotent and scriptable (Capistrano, Envoy, or GitHub Actions). No manual click-ops.
	•	Before deploy, capture `php artisan tenancy:run --command="migrate:status"` output per tenant and archive it (helps prove compliance).
	•	Blue/green or canary deploys are preferred. When not possible, schedule low-traffic windows and broadcast maintenance events via `tenants` table flag.
	•	Rollback plan: keep previous Docker image + composer.lock/package-lock snapshots; `php artisan migrate:rollback --step=1` only if data-safe; otherwise, build reversible data-migration scripts.
	•	Document every deploy/rollback in `docs/releases/<YYYY-MM-DD>-<slug>.md` with: git SHA, artisan commands, tenant impact, follow-up tasks. Codex must append to this log whenever it proposes deployment steps.


⸻

12) Execution Roadmap (Wave 1 → Wave 3)
Wave 1 — Platform Scaffolding (current sprint)
	1.	Finalize tenant metadata (`tenants` table) and `companies` schema; add seeders for demo tenants.
	2.	Add nullable `tenant_id` to users, projects, timesheets, expenses, tasks, locations, technicians, project_members; backfill via console command that maps every existing record to the primary demo tenant.
	3.	Introduce `EnsureTenantContext` + `InitializeTenancyByRequestData` middleware in API routes; keep legacy routes behind feature flag until backfill completes.
	4.	Update Auth/login/logout endpoints to require `X-Tenant` header (or `tenant` query param) and scope Sanctum tokens per tenant.

Wave 2 — Isolation & Permissions
	1.	✅ (Nov 11 2025) Move role/permission seeding per tenant; add pivot tables (tenant_role, tenant_permission) or use Spatie teams.
	2.	Refactor repositories/controllers to always filter by `tenant_id` (policy helpers + query scopes).
	3.	Add tenant-aware dashboards and include tenant summary in `/api/user`.
	4.	Create onboarding API (`POST /api/tenants/register`) that creates Company + Tenant + Admin, runs initial seeder, and sends invite email.

Wave 3 — Ops & DX
	1.	Add tenant CLI (`php artisan tenants:list`, `tenants:impersonate`, `tenants:seed --tenant=slug`).
	2.	Implement feature flags per tenant (`config/features.php` + DB overrides).
	3.	Set up automated smoke tests (`docs/qa/tenant_smoke.md`), Observability dashboards, and release checklist entries per tenant.
	4.	Document migration playbooks + rollback scripts in `docs/releases/`.

Codex should update this roadmap as steps are delivered and reference the relevant section before kicking off each wave.

## 🧩 Multitenant Database Architecture — Mandatory Model

The final SaaS architecture **must use a dual-layer database model**:

1. **Central Database (`central`)**
   - Stores only global metadata:
     - `tenants` table (tenant id, database name, company info)
     - `domains` table (subdomain or FQDN for each tenant)
     - shared configuration or system logs
   - Contains **no business data** (no users, timesheets, expenses, etc.).
   - Acts as the “controller” for tenant lifecycle management.

2. **Tenant Databases (`timesheet_<tenant_id>` or `timesheet_<slug>`)**
   - Each tenant has its **own full schema**.
   - Includes all operational data identical to the current single Timesheet DB:
     - `users`, `resources`, `timesheets`, `projects`, `expenses`, `tasks`, `payroll_exports`, etc.
   - Managed via the `DatabaseTenancyBootstrapper`.
   - Migrations are stored in `database/migrations/tenant`.
   - During onboarding, the system:
     1. Creates the tenant record in the central DB.
     2. Creates a new tenant database (prefixed `timesheet_` + tenant id).
     3. Runs all tenant migrations and seeders.
     4. Returns connection info for that tenant’s app context.

3. **Goal for Codex/Copilot**
   - Treat this structure as **immutable**.
   - Never merge tenant data into the central DB.
   - Never reuse the central DB for application data.
   - Every new client must have its own `timesheet_<tenant>` database fully populated with the Timesheet schema.

**In short:**  
> “One central DB for tenant metadata, one independent Timesheet DB per client — each with its own users, resources, timesheets, and expenses.”
