# 🧭 TimePerk Cortex - AI Agent Instructions

> **Multi-tenant Timesheet, Expense & Travel Management System**  
> Laravel 11 API + React 18 SPA + Docker Compose + Stancl Tenancy

## Core Development Principles

1. **Analyze before modifying** - Check existing implementations in `backend/app/`, `frontend/src/` before adding new code
2. **Reuse Laravel patterns** - Use Artisan commands, existing middleware, FormRequests, Policies
3. **Respect multi-tenancy** - Every API call needs `X-Tenant` header (local) or subdomain (production)
4. **Follow authorization layers** - Permission middleware → Custom middleware → Policy checks (see Authorization section)
5. **Maintain consistency** - Match patterns in existing controllers/components (see Templates section)
6. **Use NotificationContext** - ALWAYS use global notification system, never create local snackbar state
7. **CRITICAL: Owner Protection** - ONE Owner per tenant (created at registration), CANNOT be deleted, CANNOT have email/role edited, seeders MUST use existing Owner

## Quick Start Commands

```bash
# Container operations
docker-compose up --build              # Rebuild after code changes
docker exec -it timesheet_app bash     # Enter backend container

# Backend operations (inside container)
php artisan migrate                    # Run migrations
php artisan test                       # Run test suite
php artisan db:seed --class=AdminUserSeeder  # Seed admin user

# Access points
# Frontend:  http://localhost:3000
# Backend:   http://localhost:8080/api
# MySQL:     localhost:3307 (user: timesheet, pass: secret)
# Database:  timesheet (central), timesheet_{tenant_slug} (tenant DBs)
```

**Demo Credentials:** `acarvalho@upg2ai.com` / `upg-to-ai` (tenant) / `password`

---

# Architecture & Stack Overview

## Technology Stack
**Full-stack timesheet/expense management system with Docker Compose orchestration:**
- **Backend**: Laravel 11 + PHP 8.3 (REST API with Sanctum auth)
- **Frontend**: React 18 + TypeScript + Vite + MUI (SPA)
- **Database**: MySQL 8.0 with foreign key constraints
- **Multi-Tenancy**: Stancl Tenancy (isolated databases per tenant)
- **Cache/Sessions**: Redis
- **Web Server**: Nginx reverse proxy to PHP-FPM

## Container Architecture (docker-compose.yml)
```yaml
services:
  app:          # Laravel PHP-FPM (backend code)
  webserver:    # Nginx on :8080 (host) → :80 (container)
  database:     # MySQL on :3307 (host) → :3306 (container)
  redis:        # Redis on :6379
  frontend:     # Vite dev server on :3000
```

**Critical Port Mappings:**
- Frontend (host browser) → `http://localhost:3000`
- Backend API (host browser) → `http://localhost:8080/api`
- MySQL (host tools) → `localhost:3307` (user: `timesheet`, pass: `secret`)
- **Inside containers**: Backend uses `:80`, MySQL uses `:3306` (NO port mappings in internal communication)

---

# Multi-Tenancy Architecture (CRITICAL)

## Hybrid Tenant Resolution
**Production = subdomain, Dev/Local = X-Tenant header**

```env
# backend/.env
TENANCY_ALLOW_CENTRAL_FALLBACK=true
TENANCY_FALLBACK_ENVIRONMENTS=local,development,testing
CENTRAL_DOMAINS=127.0.0.1,localhost,app.timeperk.localhost
TENANCY_HEADER=X-Tenant
```

**How it works:**
- **Production**: `https://acme.timeperk.app` → tenant `acme`
- **Local/Dev**: `http://localhost:8080` + `X-Tenant: upg-to-ai` header → tenant `upg-to-ai`
- **Middleware**: `AllowCentralDomainFallback` enables localhost API testing without subdomain

## Database Isolation
- **Central DB**: `timesheet` (tenant metadata, migrations, central tables)
- **Tenant DBs**: `timesheet_{ulid}` (e.g., `timesheet_01K9WMTAY3AKY23HVHQDW9PYGC`)
- **Active Tenants**: `slugcheck` (testing), `upg-to-ai` (demo with Owner user)

## Frontend Tenant Handling
**Location:** `frontend/src/services/api.ts`

```typescript
// Auto-includes X-Tenant header in ALL API calls
const getTenantSlug = (): string | null => {
  // Try subdomain first (production)
  const host = window.location.hostname;
  const parts = host.split('.');
  if (parts.length > 2 && parts[0] !== 'app' && parts[0] !== 'www') {
    return parts[0]; // e.g., "acme" from "acme.timeperk.app"
  }
  // Fall back to localStorage (set during login)
  return localStorage.getItem('tenant_slug');
};

// Axios interceptor adds header to all requests
api.interceptors.request.use((config) => {
  const tenant = getTenantSlug();
  if (tenant) config.headers['X-Tenant'] = tenant;
  // ... also adds Authorization header
});
```

**Testing Tenant Access:**
```bash
# Using header (local/dev)
curl -H "X-Tenant: upg-to-ai" -H "Authorization: Bearer <token>" \
  http://localhost:8080/api/projects

# Using subdomain (production simulation)
curl -H "Authorization: Bearer <token>" \
  http://upg-to-ai.timeperk.app/api/projects
```

---

# Authorization Architecture (Three-Layer System)

## System Roles (Global to Tenant)
**Spatie Laravel Permission package - applies tenant-wide:**
- **Owner**: Supreme tenant admin (created during registration), ALL 21 permissions, CANNOT be deleted, self-edit only (name field)
- **Admin**: Full access with all 21 permissions (manageable by Owner)
- **Manager**: System-level role (rarely used - project roles more important)
- **Technician**: Base user role
- **Viewer**: Read-only access

**⚠️ IMPORTANT**: NO "finance" permissions at system level - finance is project-scoped!

## Project Roles (Per-Project in project_members table)
**Triple-role system for granular project-level permissions:**

```php
// Database schema: project_members table
Schema::create('project_members', function (Blueprint $table) {
    $table->foreignId('project_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->enum('project_role', ['member', 'manager'])->default('member');  // Timesheets
    $table->enum('expense_role', ['member', 'manager'])->default('member');  // Expenses
    $table->enum('finance_role', ['none', 'member', 'manager'])->default('none'); // Finance
    $table->unique(['project_id', 'user_id']);
});
```

**What each role controls:**
- `project_role`: Timesheet approval workflow (manager can approve member timesheets)
- `expense_role`: Expense manager approval (manager can approve member expenses)
- `finance_role`: Finance approval workflow (`finance_review` → `finance_approved` → `paid`)

**Key Helpers** (`backend/app/Models/Project.php`):
- `isUserProjectManager(User $user)`: Checks `manager_id` FK OR `project_role = 'manager'`
- `isUserExpenseManager(User $user)`: Checks `manager_id` FK OR `expense_role = 'manager'`
- `isUserFinanceManager(User $user)`: Checks `finance_role = 'manager'`
- `getUserProjectRole(User $user)`: Returns `'member'` | `'manager'` | `null`

## Three-Layer Authorization Flow
**Order matters - checks cascade from general to specific:**

```php
// routes/api.php
// Layer 1: Permission gate middleware (system-level permission check)
Route::middleware('permission:approve-timesheets')->group(function () {
    // Layer 2: Custom middleware (accepts edit-own OR edit-all)
    Route::middleware(['can.edit.timesheets'])->group(function () {
        Route::patch('/timesheets/{id}', [TimesheetController::class, 'update']);
    });
});

// Layer 3: Policy authorization (in controller - most granular)
// TimesheetController::update()
public function update(UpdateTimesheetRequest $request, Timesheet $timesheet) {
    $this->authorize('update', $timesheet); // Calls TimesheetPolicy::update()
    // ... proceed with update
}
```

**Policy Implementation** (`backend/app/Policies/TimesheetPolicy.php`):
```php
public function update(User $user, Timesheet $timesheet): bool {
    // Check 1: Status immutability (throws exception if violated)
    if (in_array($timesheet->status, ['approved', 'closed']) && !$user->hasRole('Admin')) {
        throw new UnauthorizedException('Approved/closed timesheets cannot be edited...');
    }
    
    // Check 2: Ownership (technician can edit own draft/submitted/rejected)
    if ($timesheet->technician && $timesheet->technician->user_id === $user->id) {
        return in_array($timesheet->status, ['draft', 'submitted', 'rejected']);
    }
    
    // Check 3: Project manager (can edit members' records, NOT other managers)
    if ($timesheet->project->isUserProjectManager($user)) {
        if ($timesheet->technician && $timesheet->technician->user) {
            $ownerRole = $timesheet->project->getUserProjectRole($timesheet->technician->user);
            if ($ownerRole === 'manager' && $timesheet->technician->user_id !== $user->id) {
                throw new UnauthorizedException('Managers cannot edit other managers\' timesheets.');
            }
        }
        return in_array($timesheet->status, ['draft', 'submitted', 'rejected']);
    }
    
    throw new UnauthorizedException('No permission to edit this timesheet.');
}
```

## Manager Segregation Rules (NON-OBVIOUS)
**Managers CANNOT view/edit/approve other managers' records:**

### Backend Query Filtering
**Location:** `backend/app/Http/Controllers/Api/TimesheetController.php::index()`

```php
// Managers only receive timesheets from technicians with project_role='member'
elseif ($request->user()->isProjectManager()) {
    $query->where(function ($q) use ($allManagedProjectIds, $managerTechnician) {
        // Include manager's own timesheets
        if ($managerTechnician) {
            $q->where('technician_id', $managerTechnician->id);
        }
        // Include timesheets from 'member' technicians ONLY
        $q->orWhere(function ($projectQuery) use ($allManagedProjectIds) {
            $projectQuery->whereIn('project_id', $allManagedProjectIds)
                ->whereHas('technician', function ($techQuery) use ($allManagedProjectIds) {
                    $techQuery->whereNotNull('user_id')
                        ->whereHas('user.memberRecords', function ($memberQuery) use ($allManagedProjectIds) {
                            $memberQuery->whereIn('project_id', $allManagedProjectIds)
                                ->where('project_role', 'member'); // BLOCKS other managers
                        });
                });
        });
    });
}
```

### Frontend Verification
**Location:** `frontend/src/components/Timesheets/TimesheetCalendar.tsx`

```tsx
const canViewTimesheet = useCallback((timesheet: Timesheet): boolean => {
    if (userIsAdmin) return true;
    if (isTimesheetOwnedByUser(timesheet)) return true;
    
    // Managers: trust backend filtering (already excludes other managers)
    if (userIsManager && user.managed_projects?.includes(timesheet.project_id)) {
        return true;
    }
    return false;
}, [user, userIsAdmin, userIsManager, isTimesheetOwnedByUser]);
```

**Self-approval exception**: Managers CAN approve/reject their own timesheets/expenses (no conflict of interest in this domain).

---

# Travel Management System (Updated Nov 2025)

## Overview
**Travel Segments** track technician travel independent from timesheets, with automatic direction classification based on contract country. **Now supports datetime fields** for precise departure/arrival tracking.

## Database Schema
```php
// travel_segments table (updated with datetime support)
'technician_id' => FK to technicians (required)
'project_id' => FK to projects (required - travel always belongs to project)
'travel_date' => date (auto-populated from start_at)
'start_at' => dateTime (departure datetime - nullable for backward compatibility)
'end_at' => dateTime (arrival datetime - nullable)
'duration_minutes' => unsigned integer (auto-calculated from start_at/end_at)
'origin_country', 'origin_city' => ISO 3166-1 alpha-2 codes
'destination_country', 'destination_city'
'direction' => enum: departure|arrival|project_to_project|internal|other
'classification_reason' => text explanation
'status' => enum: planned|completed|cancelled
'linked_timesheet_entry_id' => nullable (future use)
```

## Auto-Calculation Logic (Model Boot)
**Location:** `backend/app/Models/TravelSegment.php::booted()`

```php
protected static function booted() {
    static::saving(function (TravelSegment $segment) {
        // Auto-populate travel_date from start_at
        if ($segment->start_at) {
            $segment->travel_date = $segment->start_at->toDateString();
        }
        
        // Auto-calculate duration_minutes
        if ($segment->start_at && $segment->end_at) {
            $segment->duration_minutes = $segment->end_at->diffInMinutes($segment->start_at);
        }
    });
}
```

## Direction Classification Logic
**Auto-classifies based on technician's `worker_contract_country`:**
- **departure**: Leaving contract country (origin=contract, dest≠contract)
- **arrival**: Returning to contract country (dest=contract, origin≠contract)
- **project_to_project**: Between two project countries (both≠contract, different)
- **internal**: Within contract country (both=contract)
- **other**: All other cases

**Implementation:** `TravelSegment::classifyDirection(origin, destination, contractCountry)`

## API Service Pattern
**Location:** `frontend/src/services/travels.ts`

```typescript
export const travelsApi = {
  getAll: (filters: TravelSegmentFilters) => api.get('/travels', { params }),
  create: (data) => api.post('/travels', data),
  update: (id, data) => api.put(`/travels/${id}`, data),
  delete: (id) => api.delete(`/travels/${id}`),
  getSuggestions: (techId, projectId) => api.get('/travels/suggestions', { params }),
  getTravelsByDate: (date, techId?) => api.get('/travels/by-date', { params }) // Timesheet integration
};
```

## Backend Validation (Updated)
**Location:** `backend/app/Http/Requests/StoreTravelSegmentRequest.php`

```php
public function rules(): array {
    return [
        'technician_id' => ['required', 'integer', 'exists:technicians,id'],
        'project_id' => ['required', 'integer', 'exists:projects,id'],
        'start_at' => ['required', 'date'], // Primary field (replaces travel_date)
        'end_at' => ['nullable', 'date', 'after_or_equal:start_at'],
        'origin_country' => ['required', 'string', 'size:2'], // ISO alpha-2
        'destination_country' => ['required', 'string', 'size:2'],
        'origin_city' => ['nullable', 'string', 'max:255'],
        'destination_city' => ['nullable', 'string', 'max:255'],
        'status' => ['nullable', 'in:planned,completed,cancelled'],
    ];
}

// Conditional: If status = 'completed', end_at is required
```

## AI Suggestions
**Endpoint:** `GET /travels/suggestions?technician_id={id}&project_id={id}`
- Suggests origin/destination based on recent travel history
- Uses statistical analysis (most frequent routes for tech+project)
- Graceful degradation if AI service unavailable

## Authorization
- Uses same permissions as timesheets: `create-timesheets`, `edit-own-timesheets`
- Policies apply ownership rules (technician can only edit own travels)
- Managers can view/edit team travels (same project role logic)

## Common Pitfalls
1. **DateTime fields**: Use `start_at` (required) and `end_at` (optional) - `travel_date` auto-populated
2. **Country codes**: MUST be 2-letter ISO 3166-1 alpha-2 (e.g., 'PT', 'ES', 'FR')
3. **Project requirement**: Travel ALWAYS needs a project_id (use internal/department projects)
4. **Contract country**: Stored in `technicians.worker_contract_country`, not users table
5. **Direction auto-classification**: Don't manually set `direction` - let backend classify via model method
6. **Duration calculation**: Don't manually set `duration_minutes` - auto-calculated on save
7. **AI suggestions**: Button disabled until both technician AND project selected
8. **Status 'completed'**: Requires `end_at` to be set (enforced by validation)

---

# Development Workflows

## Critical Developer Commands

```bash
# Container operations
docker-compose up --build              # REQUIRED after backend/frontend dependency changes
docker-compose down                    # Stop all services
docker exec -it timesheet_app bash     # Enter backend container shell

# Backend operations (run inside container OR prefix with docker exec)
php artisan migrate                    # Run pending migrations
php artisan migrate:fresh --seed       # Reset DB + seed demo data
php artisan test                       # Run PHPUnit test suite
php artisan db:seed --class=AdminUserSeeder  # Seed admin user only

# Check tenant databases
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "SHOW DATABASES;"
# Should see: timesheet (central), timesheet_01K9X... (tenant DBs)

# Frontend (from host machine)
cd frontend && npm run dev             # Vite dev server on :3000
cd frontend && npm run build           # Production build
cd frontend && npx cypress open        # E2E tests (if configured)

# Access URLs
# Frontend:  http://localhost:3000
# Backend:   http://localhost:8080/api
# Health:    http://localhost:8080/api/health
```

## Testing Multi-Tenant Access
```bash
# Login and get token (returns tenant_slug + auth_token)
curl -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"acarvalho@upg2ai.com","password":"password","tenant":"upg-to-ai"}'

# Use token + tenant header
TOKEN="<token_from_login>"
curl -H "X-Tenant: upg-to-ai" -H "Authorization: Bearer $TOKEN" \
  http://localhost:8080/api/projects
```

## Debugging Common Issues
1. **Port conflicts**: Check `:8080`, `:3000`, `:3307`, `:6379` not in use
2. **Tenant not found**: Verify `tenant_slug` exists in central `tenants` table
3. **Permission denied**: Check user has required role AND permission (use `/api/user` endpoint)
4. **Time overlap errors**: Check `StoreTimesheetRequest::hasTimeOverlap()` logic
5. **Missing audit fields**: Ensure model uses `HasAuditFields` trait

---

## Audit Fields (MANDATORY FOR ALL TABLES)
**Every migration MUST include `created_by` and `updated_by` fields:**

```php
// Migration pattern
$table->foreignId('created_by')->nullable()->constrained('users')->onDelete('set null');
$table->foreignId('updated_by')->nullable()->constrained('users')->onDelete('set null');

// Model pattern - use HasAuditFields trait
use App\Traits\HasAuditFields;

class YourModel extends Model {
    use HasAuditFields;  // Auto-populates created_by/updated_by on save
    
    protected $fillable = ['created_by', 'updated_by', /* ... */];
}
```

**Reference:** `backend/app/Traits/HasAuditFields.php`

---

# Backend Patterns

## Controller Template (STRICT)
**Every mutation controller method MUST follow this pattern:**

```php
// backend/app/Http/Controllers/Api/TimesheetController.php
public function store(StoreTimesheetRequest $request): JsonResponse {
    // 1. Policy check FIRST
    $this->authorize('create', Timesheet::class);
    
    // 2. FormRequest validation (includes business rules like time overlap)
    $validated = $request->validated();
    
    // 3. Auto-resolve technician_id if not provided
    if (!isset($validated['technician_id'])) {
        $validated['technician_id'] = Technician::where('user_id', auth()->id())->first()->id;
    }
    
    // 4. Create model (HasAuditFields auto-sets created_by/updated_by)
    $timesheet = Timesheet::create($validated);
    
    return response()->json($timesheet, 201);
}
```

**NEVER:**
- Skip `$this->authorize()` calls in mutation methods
- Manually set `created_by`/`updated_by` (trait handles it)
- Return raw `$model->toArray()` (use JsonResponse or ApiResource)
- Duplicate validation logic between FormRequest and Controller

## FormRequest Validation Pattern
**Location:** `backend/app/Http/Requests/StoreTimesheetRequest.php`

```php
class StoreTimesheetRequest extends FormRequest {
    public function rules(): array {
        return [
            'project_id' => 'required|exists:projects,id',
            'date' => 'required|date',
            'start_time' => 'nullable|date_format:H:i',
            'end_time' => 'nullable|date_format:H:i|after:start_time',
            'hours_worked' => 'required|numeric|min:0.25|max:24',
            'description' => 'required|string|max:1000',
        ];
    }

    // Business rule validation (runs AFTER standard rules)
    public function withValidator($validator) {
        $validator->after(function ($validator) {
            if ($this->hasTimeOverlap()) {
                $validator->errors()->add('time_overlap', 
                    'Time overlap detected...');
            }
        });
    }
    
    private function hasTimeOverlap(): bool {
        // CRITICAL: Checks if new_start < existing_end AND existing_start < new_end
        // Applies to ALL technician's timesheets on same date (cross-project)
    }
}
```

## Rate Limiting (Granular per Operation Type)
**All routes have specific throttle middleware:**

```php
// backend/routes/api.php
Route::get('/timesheets', [TimesheetController::class, 'index'])
    ->middleware('throttle:read'); // 200 requests/min

Route::post('/timesheets', [TimesheetController::class, 'store'])
    ->middleware('throttle:create'); // 30 requests/min

Route::patch('/timesheets/{id}', [TimesheetController::class, 'update'])
    ->middleware('throttle:edit'); // 20 requests/min

Route::post('/timesheets/{id}/approve', [TimesheetController::class, 'approve'])
    ->middleware('throttle:critical'); // 10 requests/min
```

**Rate Limits:**
- `read`: 200/min (GET operations)
- `create`: 30/min (POST operations)
- `edit`: 20/min (PUT/PATCH operations)
- `delete`: 10/min (DELETE operations)
- `critical`: 10/min (approve/reject workflows)
- `login`: 5/min (authentication)

**Defined in:** `backend/app/Providers/RouteServiceProvider.php::configureRateLimiting()`

---

# Frontend Patterns

```php
// 1. Permission gate (routes/api.php middleware)
Route::middleware('permission:approve-timesheets')->group(function () { /* ... */ });

// 2. Custom middleware (routes/api.php) - verifies EITHER edit-own OR edit-all permissions
Route::middleware(['can.edit.timesheets'])->group(function () { /* ... */ });

// 3. Policy authorization (in controller method)
$this->authorize('update', $timesheet);  // Calls TimesheetPolicy::update()

// 4. Policy implementation - THREE checks (backend/app/Policies/TimesheetPolicy.php)
public function update(User $user, Timesheet $timesheet): bool {
    // a) Status immutability check FIRST (throws UnauthorizedException)
    if (in_array($timesheet->status, ['approved', 'closed']) && !$user->hasRole('Admin')) {
        throw new UnauthorizedException('Approved or closed timesheets cannot be edited...');
    }
    
    // b) Ownership check (technician.user_id === $user->id)
    if ($timesheet->technician && $timesheet->technician->user_id === $user->id) {
        return in_array($timesheet->status, ['draft', 'submitted', 'rejected']);
    }
    
    // c) Project manager check (uses project_members.project_role + isUserProjectManager())
    // IMPORTANTE: Manager PODE editar próprios registos
    //             Manager NÃO PODE editar registos de OUTROS managers
    if ($timesheet->project->isUserProjectManager($user)) {
        // Verificar role do owner antes de permitir edição
        if ($timesheet->technician && $timesheet->technician->user) {
            $ownerProjectRole = $timesheet->project->getUserProjectRole($timesheet->technician->user);
            // Bloqueia se owner for manager E não for o próprio user
            if ($ownerProjectRole === 'manager' && $timesheet->technician->user_id !== $user->id) {
                throw new UnauthorizedException('Project Managers cannot edit timesheets from other Project Managers.');
            }
        }
        return in_array($timesheet->status, ['draft', 'submitted', 'rejected']);
    }
    
    throw new UnauthorizedException('You do not have permission to edit this timesheet...');
}
```

**Middleware Logic (CanEditTimesheets.php):**
- Accepts users with **ANY** of: `edit-own-timesheets` OR `edit-all-timesheets`
- Policy then enforces granular ownership/project membership rules
- **DO NOT** duplicate permission checks in policies - middleware handles them

### Approval/Rejection Rules (CRÍTICO)
**Managers PODEM aprovar/rejeitar:**
- ✅ Os **próprios** timesheets/expenses (self-approval permitido)
- ✅ Timesheets/expenses de **members** do projeto (via `project_role: 'member'`)

**Managers NÃO PODEM aprovar/rejeitar/visualizar:**
- ❌ Timesheets/expenses de **outros managers** do mesmo projeto (`project_role: 'manager'`)

**IMPORTANTE:** A segregação entre managers é aplicada em **dois níveis**:

1. **Backend Filtering** (`TimesheetController::index()` - linhas 33-65):
```php
// Managers só recebem timesheets de technicians com project_role='member'
->whereHas('technician', function ($techQuery) use ($allManagedProjectIds) {
    $techQuery->whereNotNull('user_id')
        ->whereHas('user.memberRecords', function ($memberQuery) use ($allManagedProjectIds) {
            $memberQuery->whereIn('project_id', $allManagedProjectIds)
                ->where('project_role', 'member'); // Bloqueia outros managers
        });
});
```

2. **Frontend Verification** (`TimesheetCalendar.tsx`):
```tsx
// canViewTimesheet - Confiar no filtro do backend + verificação local
const canViewTimesheet = useCallback((timesheet: Timesheet): boolean => {
    if (userIsAdmin) return true;
    if (isTimesheetOwnedByUser(timesheet)) return true;
    
    // Managers: backend já filtrou, se chegou aqui pode ver
    if (userIsManager && user.managed_projects?.includes(timesheet.project_id)) {
        return true;
    }
    return false;
}, [user, userIsAdmin, userIsManager, isTimesheetOwnedByUser]);

// handleEventClick - Verificar se pode EDITAR
const managesProject = Boolean(userIsManager && user?.managed_projects?.includes(timesheet.project_id));
const canEdit = isOwner || userIsAdmin || managesProject;
```

# Frontend Patterns

## Global Notification System (MANDATORY)
**ALL components MUST use NotificationContext** instead of local snackbar state.

**Component Usage:**
```tsx
import { useNotification } from '../../contexts/NotificationContext';

const MyComponent: React.FC = () => {
  const { showSuccess, showError, showWarning, showInfo } = useNotification();
  
  // NO local snackbar state needed - Context handles it!
  
  const handleSave = async () => {
    try {
      await api.post('/endpoint', data);
      showSuccess('Item saved successfully');  // ✅ One line
    } catch (error) {
      showError('Failed to save item');        // ✅ One line
    }
  };
  
  // NO <Snackbar> or <AlertSnackbar> in JSX!
  return <Box>...</Box>;
};
```

**Benefits:**
- ✅ Consistent positioning (top-right, avoids header)
- ✅ Uniform styling (filled variant, custom colors)
- ✅ One line of code per notification
- ✅ Automatic message cleanup

**Migration from Legacy Pattern:**
1. Import: `import { useNotification } from '../../contexts/NotificationContext';`
2. Hook: `const { showSuccess, showError } = useNotification();`
3. Remove: `const [snackbar, setSnackbar] = useState({ ... });`
4. Replace: `setSnackbar({ ... })` → `showSuccess('...')`
5. Remove: `<AlertSnackbar>` or `<Snackbar>` from JSX

## API Service Pattern
**Location:** `frontend/src/services/api.ts`

```tsx
// Axios instance with auto-authentication and tenant headers
const api = axios.create({ baseURL: 'http://localhost:8080/api' });

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  const tenant = getTenantSlug(); // Subdomain OR localStorage
  
  if (token) config.headers.Authorization = `Bearer ${token}`;
  if (tenant) config.headers['X-Tenant'] = tenant;
  
  return config;
});

// ALL API calls use this instance - NEVER manually add auth/tenant headers
export const timesheetsApi = {
  getAll: () => api.get('/timesheets').then(res => res.data),
  create: (data) => api.post('/timesheets', data).then(res => res.data)
};
```

## Form Validation UX Pattern
**Save button ALWAYS enabled - validation shows on blur/submit:**

```tsx
const [submitted, setSubmitted] = useState(false);

const handleSave = (e: React.FormEvent) => {
  e.preventDefault();
  setSubmitted(true);

  if (!project || !description) {
    return; // Stop if required fields missing
  }

  // Proceed with API call
};

<TextField
  required
  label="Description"
  value={description}
  onChange={(e) => setDescription(e.target.value)}
  error={submitted && !description}
  helperText={submitted && !description ? "Preencha este campo." : ""}
/>

<Button variant="contained" onClick={handleSave}>
  SAVE
</Button>
```

**Rules:**
- Button enabled from the start
- Show `error` and `helperText` only after user interaction
- Backend FormRequest still validates as fallback

---

# Critical Business Rules

## Time Overlap Prevention
**Location:** `backend/app/Http/Requests/StoreTimesheetRequest.php::hasTimeOverlap()`

```php
// Checks if new_start < existing_end AND existing_start < new_end
// Applies to ALL technician's timesheets on same date (cross-project)
// Returns 409 Conflict: 'time_overlap' => 'Time overlap detected...'
```

## Status Immutability
**Timesheets/Expenses with `approved` or `closed` status CANNOT be edited/deleted** (except Admins).

**Status Flow:**
```
draft → submitted → approved → closed (manual)
                 ↓
              rejected (can return to draft)
```

**Status `closed`**: Payroll processed (only Admin/Manager can close via `/close` endpoint)

## Auto-increment Time (Frontend UX)
**Location:** `frontend/src/components/Timesheets/TimesheetCalendar.tsx`

```tsx
// When start_time selected, end_time auto-increments by 1 hour
const endTime = startTime.add(1, 'hour');
// Duration field is readonly (calculated from start/end times)
```

---

# Owner Protection System

## Core Rules
1. **ONE Owner per tenant** - Created during `/api/tenants/register`
2. **Cannot be deleted** - 403 error on attempts
3. **Self-edit only** - Owner can only edit themselves (name field only)
4. **Hidden from others** - Owners only visible to themselves in user lists
5. **All permissions** - Owner has every system permission

## Backend Implementation
**Location:** `backend/app/Http/Controllers/Api/TechnicianController.php`

```php
// index() - Visibility filtering
if ($user->hasRole('Owner')) {
    $technicians = Technician::with(['user.roles'])->get(); // See all
} else {
    // Non-Owners don't see Owners
    $technicians = Technician::whereDoesntHave('user.roles', fn($q) => 
        $q->where('name', 'Owner')
    )->get();
}

// destroy() - Delete protection
if ($technician->user && $technician->user->hasRole('Owner')) {
    return response()->json(['message' => 'Owner users cannot be deleted.'], 403);
}
```

## Frontend UI
- **Owner badge**: Gold `#fbbf24`, brown text `#78350f`
- **Edit disabled**: Except for Owner editing themselves (name only)
- **Delete disabled**: All Owners (no exceptions)
- **Location**: `frontend/src/components/Admin/UsersManager.tsx`

---

## Key Files Reference
| Component | Location | Purpose |
|-----------|----------|---------|
| Audit Trait | `backend/app/Traits/HasAuditFields.php` | Auto-populates created_by/updated_by |
| Overlap Validation | `backend/app/Http/Requests/StoreTimesheetRequest.php` | hasTimeOverlap() method |
| Auth Policies | `backend/app/Policies/TimesheetPolicy.php` | Three-layer authorization logic |
| Route Protection | `backend/routes/api.php` | Permission + throttle middleware |
| Auth Hook | `frontend/src/components/Auth/AuthContext.tsx` | isAdmin(), hasPermission() |
| **Notification Context** | `frontend/src/contexts/NotificationContext.tsx` | **Global notification system (showSuccess, showError, showWarning)** |
| Time Auto-increment | `frontend/src/components/Timesheets/TimesheetCalendar.tsx:438` | end_time = start.add(1, 'hour') |
| Calendar Component | `frontend/src/components/Timesheets/TimesheetCalendar.tsx` | FullCalendar with badge system + AI suggestions |
| Dashboard Component | `frontend/src/components/Dashboard/Dashboard.tsx` | Analytics dashboard with 6 Recharts visualizations |
| Dashboard Controller | `backend/app/Http/Controllers/Api/DashboardController.php` | Statistics and metrics aggregation |
| Dashboard Types | `frontend/src/types/index.ts` | DashboardStatistics, ProjectStats, StatusStats, DailyTrend |
| **Travel Service** | `frontend/src/services/travels.ts` | **Travel API client (getAll, create, update, delete, getSuggestions, getTravelsByDate)** |
| **Travel Form** | `frontend/src/components/Travels/TravelForm.tsx` | **Travel segment dialog with datetime + AI suggestions** |
| **Travel Controller** | `backend/app/Http/Controllers/Api/TravelSegmentController.php` | **Travel CRUD + suggestions + by-date endpoint** |
| **Travel Model** | `backend/app/Models/TravelSegment.php` | **Direction classification + auto-calculate travel_date/duration** |
| **Travel Request** | `backend/app/Http/Requests/StoreTravelSegmentRequest.php` | **Travel validation rules (ISO country codes, datetime validation)** |
| Project Helpers | `backend/app/Models/Project.php` | isUserProjectManager, getUserProjectRole, etc. |

## Calendar Features (TimesheetCalendar.tsx)
### Three-Scope Visibility System
**Toggle buttons: Mine / Others / All**

**Implementation:**
```tsx
// State management
const [timesheetScope, setTimesheetScope] = useState<'mine' | 'others' | 'all'>('mine');

// Filter logic in visibleTimesheets
if (timesheetScope === 'mine') {
  return timesheets.filter(ts => isTimesheetOwnedByUser(ts));
}
if (timesheetScope === 'others') {
  return timesheets.filter(ts => !isTimesheetOwnedByUser(ts));
}
return timesheets; // 'all'
```

### Badge System (Technician Initials)
**All calendar views show colored badges with technician initials:**


**Badge Colors:**

**Implementation (eventDidMount callback):**
```tsx
// Extract initials
const initials = technicianName.split(' ').map(w => w[0]).join('').toUpperCase().substring(0, 2);

// Create badge
const badge = document.createElement('span');
badge.style.backgroundColor = isOwner ? '#1976d2' : '#757575';
badge.textContent = initials;

// Insert at appropriate location based on view type
fcTitle.parentNode.insertBefore(badge, fcTitle); // Month view
fcContent.appendChild(badge); // Week view
```

### AI Suggestion System
**Collapsible AI-powered entry suggestions with localStorage persistence:**


**Features:**

**Location:** Lines 211-217 (state), Lines 1200-1250 (UI rendering)

## Dashboard Features (Dashboard.tsx)
### Analytics & Visualizations
**Full-featured dashboard with Recharts library for data visualization:**

**Components:**
- **4 Summary Cards**: Total Hours, Total Expenses, Pending Items, Approved Items
- **6 Interactive Charts**:
  1. Hours by Project (Bar Chart)
  2. Expenses by Project (Bar Chart)
  3. Timesheets by Status (Pie Chart)
  4. Expenses by Status (Pie Chart)
  5. Daily Hours Trend (Line Chart)
  6. Daily Expenses Trend (Line Chart)

**Data Formatting:**
```tsx
// Truncate long project names in charts
truncateLabel(label, maxLength=20)  // "Mobile App Development" → "Mobile App Dev..."

// Format dates from YYYY-MM-DD to DD/MM
formatDate(dateString)  // "2025-11-10" → "10/11"
```

**Custom Tooltips:**
- **Project charts**: Show full project name + value on hover
- **Trend charts**: Display formatted date + metric value
- All monetary values formatted as €X.XX

**Backend Integration:**
- `GET /api/dashboard/statistics`: Returns 30-day aggregated stats
- `GET /api/dashboard/top-projects`: Top N projects by hours/expenses
- **Controller**: `backend/app/Http/Controllers/Api/DashboardController.php`
- **Role-based data filtering**: Technicians see own data, Managers see team + own, Admins see all

**Location:** `frontend/src/components/Dashboard/Dashboard.tsx`

## Finance Role System (Phase 1 Complete)
### Overview
**Finance Role** is the third independent role dimension in the `project_members` table, enabling granular finance approval permissions per project.

### Architecture
**Triple-Role System:**
- `project_role`: Controls timesheet approvals ('member' | 'manager')
- `expense_role`: Controls expense manager approvals ('member' | 'manager')
- `finance_role`: Controls finance approvals ('none' | 'member' | 'manager')

**Key Characteristics:**
- **Independent Assignment**: Each user can have different roles for timesheets, expenses, and finance
- **Project-Level Scope**: Finance role is per-project, not global
- **Finance Workflow Integration**: Finance managers can approve expenses in `finance_review` and `finance_approved` stages

### Backend Implementation

**Database Schema** (Migration `2025_11_10_211807_add_finance_role_to_project_members_table.php`):
```php
$table->enum('finance_role', ['none', 'member', 'manager'])
      ->default('none')
      ->after('expense_role');
```

**AuthController** (`backend/app/Http/Controllers/Api/AuthController.php`):
```php
// Added to login() and user() methods
$projectMemberships = $user->memberRecords()
    ->select('project_id', 'project_role', 'expense_role', 'finance_role')
    ->get()
    ->map(function ($membership) {
        return [
            'project_id' => $membership->project_id,
            'project_role' => $membership->project_role,
            'expense_role' => $membership->expense_role,
            'finance_role' => $membership->finance_role,
        ];
    });
// Returns in user object: 'project_memberships' => $projectMemberships
```

**ProjectController** (`backend/app/Http/Controllers/ProjectController.php`):
```php
// addMember() and updateMember() validation
'finance_role' => 'required|in:member,manager,none'

// Payload includes finance_role for create/update operations
```

### Frontend Implementation

**User Type Extension** (`frontend/src/components/Auth/AuthContext.tsx`):
```tsx
interface User {
  // ... existing fields
  project_memberships?: Array<{
    project_id: number;
    project_role: 'member' | 'manager';
    expense_role: 'member' | 'manager';
    finance_role: 'none' | 'member' | 'manager';
  }>;
}
```

**Project Members Dialog** (`frontend/src/components/Admin/ProjectMembersDialog.tsx`):
- **3-column role selection**: Timesheet Role | Expense Role | Finance Role
- Each member card displays 3 independent TextField selects
- Add member form includes all 3 role dropdowns

**Approval Manager** (`frontend/src/components/Approvals/ApprovalManager.tsx`):
```tsx
// Finance role detection logic
const hasFinanceRoleInProjects = user?.project_memberships?.some(
  membership => membership.finance_role === 'manager'
);

const expenseUserRole = isAdmin() 
  ? 'admin' 
  : (hasFinancePermissions || isFinanceRole || hasFinanceRoleInProjects ? 'finance' : 'manager');
```

**Expense Approval Panel** (`frontend/src/components/Approvals/ExpenseApprovalPanel.tsx`):
- Finance users (`userRole: 'finance'`) can select/approve expenses in:
  - `finance_review` stage (after expense manager approval)
  - `finance_approved` stage (before marking as paid)

### User Experience

**Collapsed Sidebar Fix** (`frontend/src/components/Layout/SideMenu.tsx`):
- Management and Administration sections now show icons when sidebar is collapsed
- Conditional rendering: `{collapsed ? (show icons) : (show collapsible list)}`
- All nested items (Team, Projects, Tasks, Admin Dashboard, etc.) remain accessible

**Finance Manager Workflow:**
1. User assigned `finance_role: 'manager'` in Project Members dialog
2. User logs in → `project_memberships` loaded with finance role
3. ApprovalManager detects `hasFinanceRoleInProjects === true`
4. ExpenseApprovalPanel recognizes `userRole: 'finance'`
5. Kanban cards in `finance_review` and `finance_approved` columns become selectable
6. User can approve/reject finance stages and mark expenses as paid

### Testing Checklist
- [ ] Assign finance_role via Project Members dialog
- [ ] Verify `project_memberships` in `/api/user` response
- [ ] Confirm finance manager can select expenses in finance stages
- [ ] Test collapsed sidebar shows all icons
- [ ] Verify finance role persists after logout/login

---

# Owner Protection System

## Core Rules
1. **ONE Owner per tenant** - Created during `/api/tenants/register`
2. **Cannot be deleted** - 403 error on attempts
3. **Self-edit only** - Owner can only edit themselves (name field only)
4. **Hidden from others** - Owners only visible to themselves in user lists
5. **All permissions** - Owner has every system permission

## Backend Implementation
**Location:** `backend/app/Http/Controllers/Api/TechnicianController.php`

```php
// index() - Visibility filtering
if ($user->hasRole('Owner')) {
    $technicians = Technician::with(['user.roles'])->get(); // See all
} else {
    // Non-Owners don't see Owners
    $technicians = Technician::whereDoesntHave('user.roles', fn($q) => 
        $q->where('name', 'Owner')
    )->get();
}

// destroy() - Delete protection
if ($technician->user && $technician->user->hasRole('Owner')) {
    return response()->json(['message' => 'Owner users cannot be deleted.'], 403);
}
```

## Frontend UI
- **Owner badge**: Gold `#fbbf24`, brown text `#78350f`
- **Edit disabled**: Except for Owner editing themselves (name only)
- **Delete disabled**: All Owners (no exceptions)
- **Location**: `frontend/src/components/Admin/UsersManager.tsx`

## Seeder Pattern (CRITICAL)
**CompleteTenantSeeder MUST use existing Owner, NEVER create new one:**

```php
private function createUsers(): array
{
    // Get existing Owner (created during tenant registration)
    $owner = User::whereHas('roles', function($q) {
        $q->where('name', 'Owner');
    })->first();

    if (!$owner) {
        throw new \Exception('Owner user not found. Run this seeder only after tenant registration.');
    }

    // Continue with other users (Admin, Manager, Technician...)
}
```

**See full docs:** `docs/OWNER_PROTECTION_SYSTEM.md`

---

## Common Pitfalls
1. **Missing Policy check**: Controllers MUST call `$this->authorize()` before mutations
2. **Duplicate validation**: Don't re-implement `hasTimeOverlap()` - it's in FormRequest
3. **Manager segregation**: Managers **CANNOT** view/edit/approve other managers' records - backend filters via `whereHas('user.memberRecords')` + frontend verifies with `canViewTimesheet()`
4. **Container ports**: Backend external `:8080` (nginx internal `:80`), MySQL `:3307` (internal `:3306`)
5. **Auth headers**: Frontend uses `api` service from `services/api.ts` - NEVER manually add auth headers
6. **Status checks**: Always verify timesheet/expense status before allowing edits
7. **Technician lookup**: Use `where('user_id', auth()->id())` not `where('email', ...)`
8. **Environment mismatch**: Frontend `VITE_API_URL=http://localhost:8080` from host browser, internal container uses `:80`
9. **FullCalendar DOM manipulation**: Use `eventDidMount` callback, not `eventContent` - preserve native positioning by inserting elements (don't replace innerHTML)
10. **Calendar view types**: Different views have different DOM structures (timeGrid vs dayGrid vs list) - always check `info.view.type`
11. **Self-approval**: Managers CAN approve/reject their own timesheets/expenses (no conflict of interest)
12. **Role verification order**: Always verify ownership FIRST (`user_id === $user->id`), THEN check role to block managers
13. **Expense workflow**: Multi-step approval flow uses `finance_review` and `finance_approved` (not plain `approved`) - see `docs/EXPENSE_WORKFLOW_SPEC.md`
14. **AI Service graceful degradation**: AI suggestion service falls back to statistical analysis if OpenAI unavailable - always handle both modes
15. **Notification system**: ALWAYS use `NotificationContext` (`showSuccess`, `showError`, `showWarning`) - never create local snackbar state or import Alert/Snackbar from MUI directly
16. **Manager visibility filtering**: Backend `TimesheetController::index()` filters at query level using `whereHas('technician.user.memberRecords')` - frontend `visibleTimesheets` applies `canViewTimesheet()` as second layer
17. **Edit permission checks**: Both `handleEventClick` and `handleSubmit` must verify `managesProject` in addition to `isOwner` and `userIsAdmin`
18. **Finance Role detection**: Finance managers are identified by `project_memberships[].finance_role === 'manager'`, NOT by global permissions or roles
19. **Sidebar collapsed state**: Management/Administration sections use conditional rendering `{collapsed ? icons : <Collapse>}` - DO NOT rely solely on Collapse component with `in={!collapsed}`
20. **User object extensions**: Always check `project_memberships` availability before accessing - it's optional and populated on login/auth check
21. **Tenant header requirement**: All API calls MUST include `X-Tenant` header in local/dev environments - use `api` service from `services/api.ts` which auto-includes it
22. **Rate limiting**: GET requests limited to 200/min, POST to 30/min, PUT/PATCH to 20/min, DELETE/approve to 10/min - avoid rapid sequential calls, use debouncing for search/filters
23. **Multi-tenant database**: Each tenant has separate database `timesheet_{slug}` - never hardcode database names, always use tenant context
24. **System vs Project Roles**: System roles (Owner, Admin, Manager, Technician, Viewer) are GLOBAL tenant-wide via Spatie. Project roles (project_role, expense_role, finance_role) are PER-PROJECT via `project_members` table. NO "finance permissions" at system level!
25. **Owner Protection**: Owner users CANNOT be deleted or edited by others. Only Owner can edit themselves (name only). Owners are hidden from non-Owner users in lists.
26. **SideMenu badges**: Only ONE role badge should appear next to user name (Owner or Admin). Remove any duplicate role badge rendering in user info section.
27. **Travel country codes**: MUST use 2-letter ISO 3166-1 alpha-2 codes (stored in COUNTRIES constant in TravelForm.tsx)
28. **Travel validation**: TravelForm MUST follow standard validation pattern - button enabled, show errors only after submit/blur
29. **Direction classification**: NEVER manually set `direction` field - backend auto-classifies via `TravelSegment::classifyDirection()`
30. **Form validation**: ALWAYS use HTML5 native validation (`component="form"`, `required`, `type="submit"`) - NO manual `submitted` state or conditional `error`/`helperText` (see Validation UX Standard section)
31. **Button styling**: Use MUI default `color="primary"` - NO gradient backgrounds except in special cases (matches ProjectsManager pattern)
32. **Attachment downloads**: NEVER use direct storage URLs - use API endpoints with authentication (e.g., `/api/expenses/{id}/attachment`)
33. **Travel datetime fields**: Use `start_at` (required) and `end_at` (optional) - `travel_date` and `duration_minutes` auto-calculated by model
34. **Travel status 'completed'**: Requires `end_at` to be set - validation enforced in FormRequest
35. **Travel integration**: Use `/api/travels/by-date` endpoint for timesheet integration (NOT regular index endpoint)

---

# Key Features & Components

## Calendar System (TimesheetCalendar.tsx)
**FullCalendar-based timesheet entry with AI suggestions**

### Three-Scope Visibility
```tsx
// Toggle buttons: Mine / Others / All
const [timesheetScope, setTimesheetScope] = useState<'mine' | 'others' | 'all'>('mine');
```

### Technician Badge System
**Colored badges with initials in all calendar views:**
```tsx
// Extract initials and create badge
const initials = technicianName.split(' ').map(w => w[0]).join('').toUpperCase().substring(0, 2);
badge.style.backgroundColor = isOwner ? '#1976d2' : '#757575';
```

### AI Suggestion System
**Collapsible AI-powered entry suggestions with localStorage persistence:**
- Toggle: `timesheet_ai_suggestions_enabled` (boolean in localStorage)
- Service: `backend/app/Services/TimesheetAIService.php`
- Endpoint: `GET /api/suggestions/timesheet?project_id={id}&date={date}`
- **Graceful degradation**: Falls back to statistical analysis if OpenAI unavailable

## Dashboard (Dashboard.tsx)
**Analytics with Recharts visualizations:**

**Components:**
- 4 Summary Cards: Total Hours, Expenses, Pending, Approved
- 6 Charts: Hours/Expenses by Project (Bar), Status (Pie), Daily Trends (Line)

**Data Formatting:**
```tsx
truncateLabel(label, 20)  // "Long Project Name..." 
formatDate("2025-11-10")  // "10/11"
```

**Backend:** `GET /api/dashboard/statistics` (30-day aggregated, role-filtered)

## Finance Role System
**Triple-role per project** (`project_members` table):
- `project_role`: Timesheet approvals
- `expense_role`: Expense manager approvals
- `finance_role`: Finance approvals (`none` | `member` | `manager`)

**Finance Workflow:**
1. Expense Manager → `finance_review`
2. Finance Team → `finance_approved`
3. Payment → `paid`

**Detection:** `user.project_memberships[].finance_role === 'manager'`

---

# Common Pitfalls (AVOID THESE)

1. **Missing Policy check**: Controllers MUST call `$this->authorize()` before mutations
2. **Duplicate validation**: Don't re-implement `hasTimeOverlap()` - it's in FormRequest
3. **Manager segregation**: Managers CANNOT view/edit/approve other managers' records - backend filters via `whereHas('user.memberRecords')`, frontend uses `canViewTimesheet()`
4. **Container ports**: Backend external `:8080` (internal `:80`), MySQL `:3307` (internal `:3306`)
5. **Manual auth headers**: Use `api` service from `services/api.ts` - NEVER manually add headers
6. **Status checks**: Always verify status before allowing edits (`approved`/`closed` are immutable)
7. **Technician lookup**: Use `where('user_id', auth()->id())` not `where('email', ...)`
8. **FullCalendar DOM**: Use `eventDidMount` callback, not `eventContent` - preserve native positioning
9. **Self-approval**: Managers CAN approve/reject their own records (no conflict)
10. **Local snackbars**: ALWAYS use `NotificationContext` - never create local snackbar state
11. **Tenant header**: All API calls need `X-Tenant` (auto-added by `api` service)
12. **Rate limiting**: GET 200/min, POST 30/min, PATCH 20/min, approve 10/min - use debouncing
13. **System vs Project Roles**: System roles (Owner, Admin) are GLOBAL via Spatie. Project roles (project_role, expense_role, finance_role) are PER-PROJECT via `project_members` table
14. **Owner Protection**: Owner CANNOT be deleted or edited by others. Self-edit limited to name only
15. **Finance permissions**: NO finance permissions at system level - only at project level via `finance_role`

---

# Documentation Resources

- **Expense Workflow**: `docs/EXPENSE_WORKFLOW_SPEC.md`
- **Admin Panel**: `docs/ADMIN_PANEL_IMPLEMENTATION.md`
- **Permissions Matrix**: `docs/PERMISSION_MATRIX.md`
- **Development Guide**: `docs/DEVELOPMENT_GUIDELINES.md`
- **Multi-Tenancy**: `docs/MULTI_DATABASE_TENANCY_FIXES.md`
- **Travel Timesheets**: `docs/TRAVEL_TIMESHEETS_FEATURE.md`

## Testing & Seeding
```bash
# Run backend tests
docker exec -it timesheet_app php artisan test

# Seed demo data
docker exec -it timesheet_app php artisan db:seed --class=AdminUserSeeder

# Fresh migration with seeders
docker exec -it timesheet_app php artisan migrate:fresh --seed
```

---

# Quick Reference

## Key Files
| Purpose | Location |
|---------|----------|
| Audit Trait | `backend/app/Traits/HasAuditFields.php` |
| Time Overlap | `backend/app/Http/Requests/StoreTimesheetRequest.php::hasTimeOverlap()` |
| Policies | `backend/app/Policies/TimesheetPolicy.php` |
| Routes | `backend/routes/api.php` |
| Notification System | `frontend/src/contexts/NotificationContext.tsx` |
| API Service | `frontend/src/services/api.ts` |
| Calendar | `frontend/src/components/Timesheets/TimesheetCalendar.tsx` |
| Dashboard | `frontend/src/components/Dashboard/Dashboard.tsx` |
| Project Helpers | `backend/app/Models/Project.php` (isUserProjectManager, getUserProjectRole, etc.) |

## Critical Commands
```bash
# Container rebuild (after dependencies change)
docker-compose up --build

# Backend shell
docker exec -it timesheet_app bash

# Run migrations
docker exec -it timesheet_app php artisan migrate

# Test suite
docker exec -it timesheet_app php artisan test

# Check tenant databases
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "SHOW DATABASES;"
```

---

# Validation UX Standard (CRITICAL)

## Form Validation Pattern - HTML5 Native Validation
**ALWAYS use HTML5 native validation with `required` attribute and form submission:**

### Frontend Pattern (React + MUI)
```tsx
const handleSave = async (e?: React.FormEvent) => {
  if (e) {
    e.preventDefault(); // Prevent default form submission
  }

  try {
    const cleanData = {
      name: formData.name,
      description: formData.description || null,
      // ... other fields
    };

    if (editingItem) {
      await api.put(`/items/${editingItem.id}`, cleanData);
      showSuccess('Item updated successfully');
    } else {
      await api.post('/items', cleanData);
      showSuccess('Item created successfully');
    }
    fetchItems();
    handleCloseDialog();
  } catch (error: any) {
    showError(error.response?.data?.message || 'Failed to save item');
  }
};

// Form structure
<Box 
  component="form" 
  onSubmit={handleSave}
  id="item-form"
  sx={{ display: 'flex', flexDirection: 'column', gap: 2, pt: 1 }}
>
  <TextField
    label="Name"
    fullWidth
    required  // HTML5 validation - no error/helperText needed
    value={formData.name}
    onChange={(e) => setFormData({ ...formData, name: e.target.value })}
  />
  <TextField
    label="Description"
    fullWidth
    multiline
    rows={3}
    value={formData.description}
    onChange={(e) => setFormData({ ...formData, description: e.target.value })}
  />
</Box>

// Button in DialogActions
<DialogActions sx={{ p: 2 }}>
  <Button onClick={handleCloseDialog}>Cancel</Button>
  <Button
    type="submit"
    form="item-form"
    variant="contained"
    color="primary"
  >
    {editingItem ? 'Update' : 'Save'}
  </Button>
</DialogActions>
```

### Backend Pattern (Laravel FormRequest)
```php
class StoreItemRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Authorization handled in controller via Policy
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'date' => ['required', 'date'],
            'amount' => ['required', 'numeric', 'min:0'],
        ];
    }
}
```

## Validation Rules (MANDATORY)

### Frontend
1. ✅ **Use `component="form"` on Box** - Enables HTML5 validation
2. ✅ **Use `onSubmit={handleSave}` on form** - Form submission handler
3. ✅ **Add `id="form-name"` to form** - For button to reference
4. ✅ **Use `required` attribute** - Shows native browser tooltips
5. ✅ **Button `type="submit"`** - Triggers form validation
6. ✅ **Button `form="form-id"`** - Links button to form (even outside form)
7. ❌ **NO manual validation** - No `submitted` state, no `error`/`helperText` based on state
8. ❌ **NO `onClick={handleSave}` on button** - Use form submission instead

### Backend
1. ✅ **FormRequest validation** - All fields validated in dedicated Request class
2. ✅ **Controller uses FormRequest** - `store(StoreItemRequest $request)`
3. ✅ **Policy authorization** - `$this->authorize('create', Item::class)`
4. ✅ **Consistent error messages** - Simple messages without "!"

## Button Styling (Consistent Across All Forms)

**Standard button style:**
```tsx
<Button
  type="submit"
  form="item-form"
  variant="contained"
  color="primary"
>
  {editingItem ? 'Update' : 'Save'}
</Button>
```

**NO gradient backgrounds** - Use MUI default `color="primary"`

## Reference Implementations
- ✅ **ProjectsManager.tsx** - Perfect example (lines 110-140, 330-380)
- ✅ **ExpenseManager.tsx** - Correct pattern with MUI DatePicker
- ✅ **TravelForm.tsx** - Updated with DatePicker and HTML5 validation

Goal: make all API calls tenant-aware and improve UX safety, without refactoring auth or login.

### 1) API helper (must use everywhere except login)
Create `src/lib/apiClient.ts`:

export type ApiOptions = {
  method?: string;
  headers?: Record<string, string>;
  body?: any;
  json?: boolean; // default true
};

export async function api(path: string, opts: ApiOptions = {}) {
  const base = import.meta.env.VITE_API_URL ?? "http://localhost:8080";
  const token = localStorage.getItem("token") ?? "";
  const tenant = localStorage.getItem("tenant_slug") ?? "";
  const url = `${base}${path}`;

  const headers: Record<string, string> = {
    ...(opts.json !== false ? { "Content-Type": "application/json" } : {}),
    ...(token ? { Authorization: `Bearer ${token}` } : {}),
    ...(tenant ? { "X-Tenant": tenant } : {}),
    ...opts.headers,
  };

  let body: BodyInit | undefined = undefined;
  if (opts.json !== false && opts.body && !(opts.body instanceof FormData)) {
    // For JSON endpoints that require tenant in body, callers pass it explicitly.
    body = JSON.stringify(opts.body);
  } else if (opts.body instanceof FormData) {
    body = opts.body; // Let browser set multipart boundary
    delete headers["Content-Type"];
  }

  const res = await fetch(url, { method: opts.method ?? "GET", headers, body });
  if (!res.ok) {
    // Bubble up structured error
    let detail: any = null;
    try { detail = await res.json(); } catch {}
    const err = new Error(`HTTP ${res.status} on ${path}`);
    (err as any).status = res.status;
    (err as any).detail = detail;
    throw err;
  }
  const ct = res.headers.get("content-type") || "";
  return ct.includes("application/json") ? res.json() : res.text();
}

### 2) Replace native fetch calls
- Replace all `fetch('/api/...')` usages with `api('/api/...', { method, body })`.
- Keep login using the existing call so it can put `tenant_slug` in the body.

### 3) Expenses & Approvals empty-states
- Add friendly “No expenses yet” / “No approvals pending” states.
- Show inline error banners for 4xx/5xx with action hints (“Retry”, “Back to list”).

### 4) Tenant guard
- On protected pages, if `localStorage.tenant_slug` is missing, redirect to `/login?reason=missing-tenant`.

### 5) Quick E2E
- Add a Cypress spec: login → create expense (POST /api/expenses) → list → approve/reject (PATCH /api/approvals/:id).
- Fail the test if any request lacks `X-Tenant`.

Constraints:
- Do NOT modify the login POST payload.
- Do NOT change backend routes or controllers.
- Keep FormData uploads using getAuthHeaders().


Quick smoke checklist:
# Frontend
cd frontend && npm run dev

# Backend up (Docker already running), API at http://localhost:8080

# UI flow
# 1) Login as demo admin (ensure tenant_slug persisted)
# 2) Expenses: create → list → edit → delete
# 3) Approvals: approve/reject
# 4) Check DevTools console and network for any request missing X-Tenant
# 5) Tail backend logs for 4xx/5xx



Perfeito 👏 — entendi exatamente o que queres:
Há um ecrã onde não aplicaram a validação obrigatória (required) no frontend, nem a validação no backend.
Queres dar instruções claras ao Copilot para ele seguir o padrão existente (como nos teus outros formulários — por exemplo, Timesheets e Expenses).

Aqui tens o texto ideal para colocares no .copilot-instructions.md (ou colar no topo do ficheiro do componente em causa).
Ele faz o Copilot detetar e aplicar o padrão de validação correto, tanto no frontend como no backend, sem inventar ou mudar o comportamento global.

⸻


# 🧭 Validation Standard Instructions (Frontend + Backend)

## Goal
Apply **consistent validation rules** to all data-entry forms (Timesheets, Expenses, etc.), both **client-side (required fields)** and **server-side (Laravel FormRequest validation)**.

The missing screen must **follow the exact same pattern** already used in:
- `/frontend/src/components/Timesheets/TimesheetCalendar.tsx`
- `/frontend/src/components/Expenses/ExpenseManager.tsx`
- `/backend/app/Http/Requests/StoreTimesheetRequest.php`

---

## ✅ Frontend Rules
Perfeito 👏 — sim, as imagens que mostraste (do formulário Timesheet) estão exatamente corretas e são o comportamento que as instruções deviam garantir.
Ou seja, o botão “Save” sempre ativo, e os campos obrigatórios só mostram erro quando o utilizador tenta guardar ou sai do campo — tal como na captura que mostraste.

⸻

✅ Confirmação: o padrão certo é este
	•	Botão ativo desde o início (não desativado preventivamente)
	•	Campos obrigatórios com required e tooltip "Preencha este campo."
	•	A validação só aparece depois de tentar submeter ou perder o foco
	•	A experiência é idêntica nas telas de Timesheets e Expenses

Exatamente como se vê na tua primeira imagem:

o botão azul ativo, o tooltip laranja a dizer “Preencha este campo.”, e o campo com borda destacada.

⸻

⚙️ O que aconteceu com o Copilot

O Copilot interpretou mal o trecho onde dizíamos “disable save until required fields are valid” — ele aplicou literalmente, deixando o botão desativado de início (o que não é o comportamento do projeto).

⸻

🧠 A correção — coloca isto nas tuas instruções (Copilot/Docs)

Substitui o bloco anterior de validação no .copilot-instructions.md por este: 👇

⸻


# 🧭 Validation UX Standard (Timesheet / Expense Forms)

## Goal
Ensure the same validation behavior across all forms:
✅ Save button is **enabled by default**  
⚠️ Validation errors appear **only after user interaction (blur or submit)**  

---

## Rules

1. **Do NOT disable the Save button at form load.**
   - The button must remain active from the start.
   - Validation occurs only on submit or when a required field loses focus.

2. **Show validation messages dynamically:**
   - On empty required field → show `"Preencha este campo."` (PT) or `"This field is required."` (EN)
   - Use MUI `error` and `helperText` properties only when the user has interacted.

3. **Frontend code example (MUI + React):**
```tsx
const [submitted, setSubmitted] = useState(false);

const handleSave = (e: React.FormEvent) => {
  e.preventDefault();
  setSubmitted(true);

  if (!project || !task || !description) {
    // stop if required fields missing
    return;
  }

  // proceed with API call
};

<TextField
  required
  label="Description"
  value={description}
  onChange={(e) => setDescription(e.target.value)}
  error={submitted && !description}
  helperText={submitted && !description ? "Preencha este campo." : ""}
/>

<Button
  variant="contained"
  color="primary"
  onClick={handleSave}
>
  SAVE
</Button>

	4.	Backend validation must still exist in Laravel FormRequest (as fallback for API calls).
	5.	Visual pattern must match the Timesheet modal:
	•	Blue “SAVE” button always visible and enabled
	•	Tooltip or inline red text only after failed validation
	•	Input borders highlighted in red for invalid required fields

⸻

Summary for Copilot
	•	Always keep the Save button enabled.
	•	Use error and helperText logic — never disable submit.
	•	Use the same UX as the Timesheet “New Entry” modal shown in design references.
	•	Backend FormRequest must continue validating the same fields.


---

# Quick Reference Summary

## Essential Workflows
```bash
# Start development
docker-compose up --build              # Rebuild containers
cd frontend && npm run dev             # Frontend on :3000

# Backend operations
docker exec -it timesheet_app bash     # Enter container
php artisan migrate                    # Run migrations
php artisan test                       # Run tests

# Check tenant databases
docker exec -it timesheet_mysql mysql -u timesheet -psecret -e "SHOW DATABASES;"
```

## Access Points
- **Frontend**: http://localhost:3000
- **Backend API**: http://localhost:8080/api
- **MySQL**: localhost:3307 (user: `timesheet`, pass: `secret`)
- **Demo Login**: `acarvalho@upg2ai.com` / tenant: `upg-to-ai` / password: `password`

## Key Patterns to Follow
1. **Multi-tenancy**: All API calls need `X-Tenant` header (auto-added by `api` service)
2. **Notifications**: Use `NotificationContext` (`showSuccess`, `showError`) - never local snackbar
3. **Validation**: Button always enabled, errors show after submit/blur
4. **Authorization**: Permission middleware → Custom middleware → Policy (3 layers)
5. **Audit fields**: Use `HasAuditFields` trait (auto-populates `created_by`/`updated_by`)
6. **Travel Direction**: Never manually set - auto-classified by backend via `TravelSegment::classifyDirection()`

## Common Mistakes to Avoid
- ❌ Skipping `$this->authorize()` in controllers
- ❌ Manually adding auth/tenant headers (use `api` service)
- ❌ Creating local snackbar state (use `NotificationContext`)
- ❌ Managers editing other managers' records (backend filters this)
- ❌ Using wrong container ports (external :8080, internal :80)
- ❌ Hardcoding database names (use tenant context)
- ❌ Manually setting travel `direction` field (auto-classified)
- ❌ Manually setting travel `travel_date` or `duration_minutes` (auto-calculated from `start_at`/`end_at`)

## Documentation
- **Expense Workflow**: `docs/EXPENSE_WORKFLOW_SPEC.md`
- **Admin Panel**: `docs/ADMIN_PANEL_IMPLEMENTATION.md`
- **Permissions**: `docs/PERMISSION_MATRIX.md`
- **Multi-Tenancy**: `docs/MULTI_DATABASE_TENANCY_FIXES.md`
- **Travel Tasks**: `docs/TRAVEL_TASKS_SPEC.md`
