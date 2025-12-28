# TimePerk Cortex - AI Coding Agent Instructions

## 🎯 Project Overview

**TimePerk Cortex** is a multi-tenant SaaS timesheet and expense management system built with:
- **Backend**: Laravel 11 API (PHP 8.2+) with Stancl Tenancy
- **Frontend**: React 18 + TypeScript + Vite + Material-UI
- **Infrastructure**: Docker Compose with MySQL 8.0, Redis, Nginx
- **Architecture**: Modular monolith (see `Modules/` directory)

**Core Business Logic**: Track working hours and expenses per project with multi-stage approval workflows and role-based authorization.

**⚠️ MANDATORY READING BEFORE ANY CODE CHANGES:**
1. `docs/DEVELOPMENT_GUIDELINES.md` - Common errors and anti-patterns
2. Relevant spec in `docs/` for the feature (e.g., `EXPENSE_WORKFLOW_SPEC.md`)
3. `docs/PERMISSION_MATRIX.md` - Authorization rules (if touching auth/policies)

## 📍 Current Development Context

**Active Branch**: `feature/planning-gantt` (Planning module with Gantt chart integration)  
**Active Modules**: Planning (FullCalendar.js), Timesheets, Expenses, Billing  
**Latest Version**: v1.2.0 (Multi-tenancy + granular rate limiting)

**Check before starting work:**
- Branch name for feature context
- `CHANGELOG.md` for recent changes and breaking updates
- Current module's feature flag requirements (`TenantFeatures::active()`)
- Related docs in `docs/` matching your feature area

## 🏗️ Multi-Tenancy Architecture (Critical)

### Database Isolation Strategy
- **Central DB** (`timesheet`): Stores tenant metadata, domains, companies
- **Tenant DBs** (`timesheet_{slug}`): Isolated business data per tenant
- Each tenant registration creates a new MySQL database automatically

### Tenant Identification (3 Methods)
```php
// 1. Subdomain (production): acme.app.timeperk.com → tenant: "acme"
// 2. X-Tenant header (dev/API): X-Tenant: slugcheck
// 3. Query parameter (fallback): ?tenant=test-company
```

### Environment Configuration (Critical)
```bash
# .env configuration for multi-tenancy
CENTRAL_DOMAINS=api.localhost,app.localhost,localhost,127.0.0.1
TENANCY_BASE_DOMAIN=timeperk.localhost  # Subdomain base for production
TENANCY_HEADER=X-Tenant                  # Dev/API header
TENANCY_QUERY_PARAMETER=tenant           # Fallback query param
TENANCY_CENTRAL_CONNECTION=mysql         # Central DB connection
TENANCY_TENANT_CONNECTION=tenant         # Dynamic tenant connection
TENANCY_DATABASE_PREFIX=timesheet_       # Tenant DB naming pattern
TENANCY_ALLOW_CENTRAL_FALLBACK=true      # Allow localhost without tenant
```

**Docker Network Architecture**:
- `nginx_api` (port 80): Serves Laravel API at `http://api.localhost`
- `nginx_app` (port 8082): Serves React frontend at `http://localhost:8082`
- `timesheet_mysql` (port 3307): MySQL 8.0 with central + tenant databases
- `timesheet_redis` (port 6379): Redis for cache and sessions

### Key Tenancy Files
- `config/tenancy.php`: Central configuration with DatabaseTenancyBootstrapper **DISABLED** (manual connection pattern)
- `app/Tenancy/Bootstrappers/SanctumTenancyBootstrapper.php`: Custom Sanctum integration
- `routes/api.php`: Central routes (registration, health checks)
- `routes/tenant.php`: Tenant-scoped business routes
- `app/Http/Middleware/InitializeTenancyBy*.php`: Custom tenant resolution middleware

**Critical Middleware Stack** (applied in this order):
1. `InitializeTenancyByRequestData`: Resolves tenant from header/subdomain/query
2. `EnsureTenantContext`: Validates tenant context exists
3. `SetSanctumTenantConnection`: Configures Sanctum for tenant DB
4. `auth:sanctum`: Laravel Sanctum authentication
5. `EnsureModuleEnabled`: Feature flag validation for conditional modules

**Modern Tenancy Pattern** (2025):
- Controllers NO LONGER need manual `->on($connection)` calls
- Middleware `InitializeTenancyByRequestData` automatically resolves tenant context
- Simply use Eloquent models directly: `Project::get()` instead of `Project::on($connection)->get()`
- Tenant context is maintained automatically throughout request lifecycle
- **Exception**: Raw DB queries still need explicit connection: `DB::connection('tenant')->table('users')->get()`

### Testing Tenants
- **slugcheck**: Development tenant (create if missing: `POST /api/tenants/register`)
- **test-company**: Demo tenant with sample data (`admin@testcompany.test` / `admin123`)

**⚠️ CRITICAL ANTI-PATTERN**: The manual `$connection = tenancy()->initialized ? 'tenant' : config('database.default')` pattern is DEPRECATED. Never use `Model::on($connection)` in controllers - middleware handles tenant resolution automatically.

## 🔐 Authorization System (17 Permissions)

### Triple-Role Architecture (Non-Standard)
Each user has **3 independent roles per project** in `project_members` table:
- `project_role`: Controls timesheet permissions (`member` | `manager` | `none`)
- `expense_role`: Controls expense permissions (`member` | `manager` | `none`)  
- `finance_role`: Controls finance approval stage (`member` | `manager` | `none`)

**Example**: User can be timesheet manager but expense member on same project.

### Critical Authorization Rules
```php
// Manager Segregation: Managers cannot view/edit/approve other managers' records
// Implementation in Policies (TimesheetPolicy, ExpensePolicy):
if ($record->technician && $record->technician->user) {
    $ownerProjectRole = $record->project->getUserProjectRole($record->technician->user);
    if ($ownerProjectRole === 'manager') {
        return false; // Block access to other managers' records
    }
}
```

### Key Permission Patterns
- **Spatie Laravel Permission**: Used for system-level roles (Admin, Manager, Technician)
- **Laravel Policies**: `TimesheetPolicy`, `ExpensePolicy` for resource authorization
- **API Responses**: Always include permission metadata for frontend UI control
- **Status Protection**: Cannot edit/delete `approved` or `closed` records (Admin override)

### Form Requests (Validation)
```php
// app/Http/Requests/StoreTimesheetRequest.php
// app/Http/Requests/UpdateExpenseRequest.php
// Use these for professional validation instead of inline validate()
```

## 🗂️ Backend Architecture Patterns

### Directory Structure
```
app/
├── Http/
│   ├── Controllers/Api/          # Tenant-scoped API endpoints
│   ├── Middleware/               # Tenancy + auth middleware
│   └── Requests/                 # Form validation classes
├── Models/                       # Eloquent models with tenant scopes
├── Policies/                     # Authorization policies
├── Services/                     # Business logic (TimesheetAIService, etc.)
├── Tenancy/                      # Custom tenancy bootstrappers
└── Traits/                       # Reusable model traits
```

### Critical Models & Relationships
```php
User → hasMany(ProjectMember) → belongsTo(Project)
Timesheet → belongsTo(Technician) → belongsTo(User)
Expense → belongsTo(Project), belongsTo(User)
Project → hasMany(ProjectMember) // with project_role, expense_role, finance_role
```

### Service Classes (Business Logic)
- `TimesheetAIService`: AI-powered insights and pattern analysis
- `TimesheetValidationService`: Overlap detection and validation rules
- `TenantDomainProvisioner`: Automatic domain provisioning
- `TenantFeatures`: Laravel Pennant feature flag management (see `Modules/README.md`)
- `TenantResolver`: Tenant context resolution from request data
- `PriceCalculator`, `PlanManager`: Billing services (see billing docs)

### Modular Architecture
**Note**: `Modules/` directory is currently empty but prepared for future self-contained feature modules with independent routes, controllers, and migrations. Future modules planned:
- `Timesheets/`, `Expenses/`: Core modules (always enabled)
- `Travels/`, `Planning/`, `AI/`: Conditional modules (plan-based or addon)
- `Billing/`: Subscription and payment management

**Key Rules for Future Modules**: 
- NO direct cross-module dependencies (communication via billing services or feature flags only)
- Each module has own `Routes/`, `Controllers/`, `Models/`, `Services/`, `Policies/`, `Database/migrations/`
- Conditional modules use `EnsureModuleEnabled` middleware
- Check `TenantFeatures::active($tenant, 'module_name')` for feature flags
- Autoloaded via `composer.json`: `"Modules\\": "../Modules/"`

### Rate Limiting Strategy
```php
// routes/api.php throttle groups:
'throttle:read'     // 200/min - GET requests
'throttle:create'   // 30/min  - POST requests
'throttle:edit'     // 20/min  - PUT/PATCH
'throttle:critical' // 10/min  - Approvals, deletions
'throttle:login'    // 5/min   - Authentication
```

## ⚛️ Frontend Architecture

### Key Technologies
- **React Router DOM v6**: Client-side routing
- **Axios**: API calls with auto-injected `X-Tenant` header
- **Material-UI v6**: Component library with DataGrid
- **FullCalendar**: Timesheet calendar interface
- **Recharts**: Dashboard visualizations

### Critical Files
```
src/
├── services/api.ts              # Axios instance with tenant headers
├── contexts/AuthContext.tsx     # Auth state + permissions
├── components/
│   ├── Auth/                    # Login, TenantRegistration
│   ├── Timesheets/              # TimesheetCalendar (main interface)
│   ├── Expenses/                # ExpenseManager with approval workflow
│   ├── Admin/                   # Admin panel (Projects, Tasks, Locations, Users)
│   └── Approvals/               # ApprovalManager for managers
└── types/index.ts               # TypeScript interfaces
```

### Tenant Header Injection (Critical)
```typescript
// src/services/api.ts
const getTenantSlug = (): string | null => {
  // 1. Try subdomain (e.g., "acme" from "acme.app.timeperk.com")
  const host = window.location.hostname;
  const parts = host.split('.');
  if (parts.length > 2 && parts[0] !== 'app' && parts[0] !== 'www') {
    return parts[0];
  }
  // 2. Fallback to localStorage (set during login)
  return localStorage.getItem('tenant_slug');
};

// Auto-injected in axios interceptor
api.interceptors.request.use((config) => {
  const tenantSlug = getTenantSlug();
  if (tenantSlug) {
    config.headers['X-Tenant'] = tenantSlug;
  }
  return config;
});
```

### Permission-Based UI Rendering
```tsx
// Components must hide/disable actions based on permissions from API
const { permissions } = timesheet; // Returned from API
{permissions.canApprove && (
  <Button onClick={handleApprove}>Approve</Button>
)}
```

### Helper Functions (api.ts)
The `src/services/api.ts` file exports key helpers for tenant management:
- `setTenantSlug(slug: string)`: Store tenant slug after login
- `clearTenantSlug()`: Remove tenant slug on logout
- `getAuthHeaders()`: Get headers with auth + tenant for native fetch()
- `fetchWithAuth(input, init)`: Alternative to axios with same auth pattern

**Critical Pattern**: Always use `api` instance from `api.ts` for HTTP calls - it automatically handles:
- Base URL configuration (Docker vs local)
- Authorization header injection from localStorage
- Tenant header (`X-Tenant`) auto-detection from subdomain or localStorage
- JSON content type headers

## 🚀 Development Workflows

### Docker Commands
```bash
# Start environment
docker-compose up -d

# ⚠️ CRITICAL: Setup database permissions (REQUIRED after first start or down -v)
docker-compose exec app php artisan db:setup-permissions

# Access backend container
docker-compose exec app bash

# Run migrations (central database)
docker-compose exec app php artisan migrate

# Run tenant-specific migrations
docker-compose exec app php artisan tenants:migrate <slug>

# Seed tenant database
docker-compose exec app php artisan tenants:seed <slug> --class=RolePermissionSeeder

# List all tenants
docker-compose exec app php artisan tenants:list
```

### Database Permissions (Multi-Tenancy)
⚠️ **PROBLEMA RECORRENTE**: Erro "Access denied to database 'timesheet_01XYZ...'" ao criar tenant.

**Causa**: User `timesheet` precisa de permissão `CREATE` para criar tenant databases.

**Solução automática** (rodar após `docker-compose up -d` ou após `down -v`):
```bash
docker-compose exec app php artisan db:setup-permissions
```

**Solução manual** (se comando falhar):
```bash
docker-compose exec database mysql -u root -proot < docker/mysql/init.sql
```

**Documentação completa**: `docs/DATABASE_PERMISSIONS.md`

### Artisan Commands (Custom)
```bash
# Tenant management
php artisan tenants:list --status=active
php artisan tenants:migrate acme --fresh --seed
php artisan tenants:seed --all
php artisan tenants:verify {slug}  # Comprehensive tenant health check
php artisan tenants:delete {slug} --force

# Testing & seeding
php artisan bootstrap:demo-tenant  # Creates demo tenant with sample data
php artisan test:email-verification  # Test email flows

# Generate ER diagram
php artisan generate:erd
```

### Testing Credentials
```
Tenant: test-company
Email: admin@testcompany.test
Password: admin123
```

### Local URLs
- Frontend: `http://localhost:8082`
- API: `http://api.localhost` (or `http://localhost:80`)
- Database: `localhost:3307`

## 📋 Development Guidelines

### Before Modifying Code
1. **Read spec files first**: `docs/Requirements/`, `docs/*.md`
2. **Check DEVELOPMENT_GUIDELINES.md**: Avoid common pitfalls
3. **Understand current branch**: Check branch name for feature context (e.g., `feature/planning-ai-reports`)

### Common Pitfalls (Must Avoid)
```php
// ❌ WRONG: Duplicate fields in $fillable
protected $fillable = ['status', 'status']; // Always check existing fields

// ❌ WRONG: Conflicting foreign key constraints
$table->foreignId('task_id')->nullable()->constrained()->onDelete('restrict');

// ✅ CORRECT: Match constraint to nullability
$table->foreignId('task_id')->constrained()->onDelete('restrict'); // Required
$table->foreignId('category_id')->nullable()->constrained()->onDelete('set null'); // Optional
```

### Frontend-Backend Validation Sync
```typescript
// Frontend validation MUST match backend rules
// Backend: required|exists:tasks,id
// Frontend: Must show required field without "None" option
<TextField select label="Task *" required>
  <MenuItem value={0}>Select a task</MenuItem> {/* Placeholder, not "None" */}
  {tasks.map((task) => <MenuItem key={task.id} value={task.id}>{task.name}</MenuItem>)}
</TextField>
```

### Migration Patterns
```php
// For tenant-specific tables: database/migrations/tenant/
// For central tables: database/migrations/

// Always include tenant_id in central-referenced tables
Schema::table('project_members', function (Blueprint $table) {
    $table->char('tenant_id', 26)->after('id'); // ULID
    $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
});
```

## 🔒 Protected Code Areas (from .cursorrules)

### Strategic Files (Modify Only When Explicitly Instructed)
- `backend/app/Models/**`
- `backend/app/Http/Controllers/**`
- `backend/app/Providers/**`
- `backend/config/**`
- `backend/routes/**`
- `frontend/src/**`

**Exception**: New billing code allowed in:
- `backend/app/Modules/Billing/**`
- `backend/app/Services/Billing/**`
- `frontend/src/api/billing.ts`
- `frontend/src/contexts/BillingContext.tsx`
- `frontend/src/components/Billing/**`

### Phase Control Strategy
When working on features, respect the current development phase:
- Check `CHANGELOG.md` and branch name for phase context
- Billing features: Follow phase plan in billing documentation
- New modules: Ensure proper feature flag and middleware setup before implementation

## 📚 Key Documentation Files

### Architecture & Specs
- `docs/PERMISSION_MATRIX.md`: Authorization rules and role matrix
- `docs/DEVELOPMENT_GUIDELINES.md`: Common errors and patterns
- `docs/TENANT_DEPLOYMENT.md`: Deployment and troubleshooting
- `docs/SANCTUM_MULTI_TENANT_AUTH.md`: Authentication in multi-tenant context
- `docs/local_tenancy.md`: Local tenant development setup

### Feature Implementation Guides
- `docs/ADMIN_PANEL_IMPLEMENTATION.md`: Admin panel structure
- `docs/EXPENSE_WORKFLOW_SPEC.md`: Multi-stage expense approval
- `docs/TIMESHEET_VALIDATION_SPEC.md`: Overlap detection rules
- `docs/PLANNING_MODULE_IMPLEMENTATION.md`: Gantt chart planning
- `docs/PENNANT_INTEGRATION_IMPLEMENTATION.md`: Feature flag system
- `docs/OWNER_PROTECTION_SYSTEM.md`: Owner role security patterns
- `docs/FINANCE_ROLE_IMPLEMENTATION.md`: Finance role and approval workflow

### Billing & Stripe Integration
- `docs/PHASE_10_STRIPE_WEBHOOKS_IMPLEMENTATION.md`: Webhook handling
- `docs/PHASE_4_CUSTOMER_PORTAL_IMPLEMENTATION.md`: Self-service portal
- `docs/BILLING_STRIPE_PRODUCTION_AUDIT.md`: Production checklist
- `docs/STRIPE_PAYMENT_SETUP.md`: Payment method configuration
- `docs/BILLING_CALCULATION_TEST_GUIDE.md`: Testing billing logic

### Testing & QA
- `docs/FRONTEND_TENANT_TESTING_GUIDE.md`: Manual testing procedures
- `docs/tenant_onboarding_tests.md`: Onboarding flow validation

## 🎨 Code Style Conventions

### Laravel Backend
- Use Form Requests for validation, not inline `validate()`
- Always authorize with Policies: `$this->authorize('update', $timesheet)`
- Never use manual `->on($connection)` in controllers (deprecated pattern)
- Return permission metadata in API responses for frontend authorization

### React Frontend
- TypeScript strict mode enabled
- Use functional components with hooks
- API responses typed in `src/types/index.ts`
- Material-UI theming in `src/App.tsx`
- Use `api.ts` instance for all HTTP calls (auto-injects tenant header)

### Database
- ULID for tenant IDs (26 chars)
- Auto-incrementing IDs for business tables
- Soft deletes not used (hard deletes only)
- Enum columns for status fields (not string lookup tables)

## 🐛 Debugging Tips

### ⚠️ CRITICAL: Docker Container File Sync Issues

**⚠️ REGRA DE OURO: Após qualquer alteração de código, SEMPRE rebuildar containers!**

**NÃO perder tempo** tentando "consertar" ou "recarregar" - vai direto para rebuild:

```bash
# COMANDO ÚNICO - Use isto SEMPRE após mudanças de código:
docker-compose down -v && docker-compose up -d --build
```

**Sintomas de cache (quando ignorar tentativas de fix):**
- Mudanças no frontend não aparecem no browser
- Botões não funcionam após alteração de código
- API retorna 404 em rotas recém-criadas
- CORS errors após atualizar `cors.php`
- Middleware não executa após registro
- Variáveis `.env` não atualizam
- 500 errors sem logs após modificações

**Por que acontece:**
- **Frontend**: Nginx serve build estático em `/usr/share/nginx/html` (não atualiza automaticamente)
- **Backend**: PHP-FPM com opcache + Laravel config cache
- **Volumes**: Docker pode cachear arquivos mesmo com bind mounts
- **Build**: Vite/npm build não é executado em hot-reload

**WORKFLOW CORRETO:**
1. Editar código (backend ou frontend)
2. **IMEDIATAMENTE** rodar: `docker-compose down -v && docker-compose up -d --build`
3. Aguardar 15 segundos (MySQL init)
4. Testar mudanças

**❌ NÃO fazer:**
- `docker-compose restart` (não rebuilda)
- Apenas `npm run build` sem rebuild de containers
- Tentar limpar cache manualmente
- Modificar mais código antes de validar com rebuild

**✅ FAZER:**
- Rebuild completo após CADA mudança
- Validar que funcionou antes de próxima alteração
- Aceitar que rebuild é parte do workflow

### Tenant Context Issues
```php
// Check current tenant
dd(tenancy()->initialized, tenant()?->id);

// Force tenant context in Tinker
php artisan tenants:migrate <slug>
php artisan tinker
tenancy()->initialize(Tenant::find('01ABC...'));
```

### API Authentication Errors
```bash
# Check Sanctum tokens table (in tenant DB!)
docker-compose exec database mysql -u timesheet -psecret -e "USE timesheet_slugcheck; SELECT * FROM personal_access_tokens;"
```

### Frontend Tenant Header Missing
```javascript
// Check browser DevTools Network tab → Request Headers
// Must see: X-Tenant: slugcheck (or tenant from subdomain)
```

---

## 📝 Quick Reference

| Task | Command |
|------|---------|
| **⚠️ SEMPRE após mudanças** | `docker-compose down -v && docker-compose up -d --build` |
| Start dev environment | `docker-compose up -d` |
| View logs | `docker-compose logs -f app` |
| Run backend tests | `docker-compose exec app php artisan test` |
| Clear Laravel cache | `docker-compose exec app php artisan optimize:clear` |
| Register new tenant | `POST /api/tenants/register` (see docs/TENANT_DEPLOYMENT.md) |

**⚠️ WORKFLOW OBRIGATÓRIO:**
1. Editar código
2. `docker-compose down -v && docker-compose up -d --build`
3. Aguardar 15s
4. Testar

**Remember**: Docker cache é o problema #1. Não perder tempo debuggando - rebuildar SEMPRE!
