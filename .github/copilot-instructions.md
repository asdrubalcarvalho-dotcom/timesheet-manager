# 🧭 TimePerk Cortex - AI Agent Instructions

> **Multi-tenant Timesheet & Expense Management System**  
> Laravel 11 API + React 18 SPA + Docker Compose + Stancl Tenancy

## Core Development Principles

1. **Analyze before modifying** - Check existing implementations in `backend/app/`, `frontend/src/` before adding new code
2. **Reuse Laravel patterns** - Use Artisan commands, existing middleware, FormRequests, Policies
3. **Respect multi-tenancy** - Every API call needs `X-Tenant` header (local) or subdomain (production)
4. **Follow authorization layers** - Permission middleware → Custom middleware → Policy checks (see Authorization section)
5. **Maintain consistency** - Match patterns in existing controllers/components (see Templates section)

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

# TimePerk Cortex - AI Coding Agent Guide

## Architecture & Stack
**Full-stack timesheet/expense management system with Docker Compose orchestration:**
- **Backend**: Laravel 11 + PHP 8.3 (REST API with Sanctum auth)
- **Frontend**: React 18 + TypeScript + Vite + MUI (SPA)
- **Database**: MySQL 8.0 with foreign key constraints + Multi-tenant architecture (Stancl Tenancy)
- **Cache**: Redis (sessions + rate limiting)
- **Web Server**: Nginx (reverse proxy to PHP-FPM)

**Key Design Patterns:**
- **Multi-Tenancy**: Stancl Tenancy with hybrid mode (subdomain in production + X-Tenant header for local/dev)
- **RBAC (System Level)**: Spatie Laravel Permission with 5 system roles + granular permissions:
  - **Owner**: Super admin of tenant (created during tenant registration), has ALL 21 permissions, CANNOT be deleted, can only be edited by themselves (name only)
  - **Admin**: Full access with all 21 permissions (same as Owner but can be managed)
  - **Manager**: System-level role (rarely used, project roles are more important)
  - **Technician**: Base user role
  - **Viewer**: Read-only access
  - **IMPORTANT**: System roles are GLOBAL to the tenant (Spatie package). There are NO "finance" permissions at system level.
- **Triple-Role System (Project Level)**: Separate `project_role`, `expense_role`, and `finance_role` per user per project (via `project_members` pivot table):
  - `project_role`: `'member'` | `'manager'` - Controls timesheet approval workflow
  - `expense_role`: `'member'` | `'manager'` - Controls expense manager approval
  - `finance_role`: `'none'` | `'member'` | `'manager'` - Controls finance approval (THIS is where finance permissions live - per project!)
  - **IMPORTANT**: Project roles are PER-PROJECT assignments stored in `project_members` table. A user can be 'manager' for timesheets in Project A but 'member' in Project B.
- **Policy-Based Authorization**: Laravel Policies enforce ownership + status + project membership rules
- **Middleware Chaining**: Permission gates → Custom middleware → Policy checks
- **Form Request Validation**: Business rules (e.g., time overlap) in dedicated FormRequest classes
- **Granular Rate Limiting**: Separate limits for read (200/min), create (30/min), edit (20/min), critical (10/min)

## Multi-Tenancy Configuration
**Hybrid Tenant Resolution** (production = subdomain, dev/local = header):
```env
# backend/.env
TENANCY_ALLOW_CENTRAL_FALLBACK=true
TENANCY_FALLBACK_ENVIRONMENTS=local,development,testing
CENTRAL_DOMAINS=127.0.0.1,localhost,app.timeperk.localhost
TENANCY_HEADER=X-Tenant
```

**Middleware**: `AllowCentralDomainFallback` allows localhost API testing without subdomain
**Active Tenants**: `slugcheck` (testing), `upg-to-ai` (demo with Owner user)
**Database Pattern**: Each tenant has isolated database `timesheet_{ulid}`

## Demo Credentials
**UPG to AI Tenant (Owner):**
- Email: `acarvalho@upg2ai.com`
- Tenant: `upg-to-ai`
- Password: `password`
- Access: Full admin permissions, can create projects/users/expenses

**Frontend Login:** Available via "Owner (UPG to AI)" demo button on login page

## Critical Developer Commands
```bash
# Rebuild containers after code/dependency changes
docker-compose up --build

# Backend operations
docker exec -it timesheet_app bash
docker exec -it timesheet_app php artisan migrate
docker exec -it timesheet_app php artisan test
docker exec -it timesheet_app php artisan db:seed --class=AdminUserSeeder

# Access services
# Frontend: http://localhost:3000
# Backend API: http://localhost:8080/api
# MySQL: localhost:3307 (user: timesheet, pass: secret)
# - Central DB: timesheet (tenant records + migrations)
# - Tenant DBs: timesheet_slugcheck, timesheet_{ulid}

# Test tenant access
# Demo credentials: acarvalho@upg2ai.com / upg-to-ai / password
```

## Multi-Tenant Testing
```bash
# Using X-Tenant header (local/dev)
curl -H "X-Tenant: upg-to-ai" http://localhost:8080/api/projects

# Using subdomain (production)
curl http://upg-to-ai.timeperk.app/api/projects
```

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

**Rules:**

**Reference:** `backend/app/Traits/HasAuditFields.php`

## Three-Layer Authorization (NON-OBVIOUS)
**Order matters - checks cascade from general to specific:**

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

```php
// TimesheetPolicy::approve() e reject()
if ($timesheet->project->isUserProjectManager($user)) {
    // Se for o próprio timesheet, PODE aprovar/rejeitar (self-approval)
    if ($timesheet->technician && $timesheet->technician->user_id === $user->id) {
        return true;
    }
    
    // Se for de outro user, verificar role via project_members table
    if ($timesheet->technician && $timesheet->technician->user) {
        $ownerProjectRole = $timesheet->project->getUserProjectRole($timesheet->technician->user);
        return $ownerProjectRole === 'member'; // Bloqueia outros managers
    }
    return true;
}
```

**Mesma lógica aplica-se a Expenses** (usando `expense_role` em vez de `project_role` via `project_members` table).

**Finance Role aplica-se apenas a Expenses** (usando `finance_role` via `project_members` table para aprovação financeira).

**Project Membership Helpers** (`backend/app/Models/Project.php`):
- `isUserProjectManager(User $user)`: Checks `manager_id` FK OR `project_members.project_role = 'manager'`
- `isUserExpenseManager(User $user)`: Checks `manager_id` FK OR `project_members.expense_role = 'manager'`
- `isUserFinanceManager(User $user)`: Checks `project_members.finance_role = 'manager'`
- `getUserProjectRole(User $user)`: Returns `'member'` | `'manager'` | `null` from `project_members.project_role`
- `getUserExpenseRole(User $user)`: Returns `'member'` | `'manager'` | `null` from `project_members.expense_role`
- `getUserFinanceRole(User $user)`: Returns `'none'` | `'member'` | `'manager'` from `project_members.finance_role`
- `isUserMember(User $user)`: Checks if user exists in `project_members` (any role)

**Database Schema** (`project_members` table):
```php
Schema::create('project_members', function (Blueprint $table) {
    $table->id();
    $table->foreignId('project_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->enum('project_role', ['member', 'manager'])->default('member'); // For timesheets
    $table->enum('expense_role', ['member', 'manager'])->default('member'); // For expenses
    $table->enum('finance_role', ['none', 'member', 'manager'])->default('none'); // For finance approval
    $table->timestamps();
    $table->unique(['project_id', 'user_id']); // One membership per user per project
});
```

**Rate Limits (Optimized for Intensive Navigation):**
- `api`: 120 requests/min (general API limit)
- `read`: 200 requests/min (GET requests - very permissive)
- `login`: 5 requests/min (security)
- `create`: 30 requests/min (POST operations)
- `edit`: 20 requests/min (PUT/PATCH operations)
- `delete`: 10 requests/min (DELETE operations)
- `critical`: 10 requests/min (approve/reject workflows)

**All routes have granular throttle applied:**
- GET routes → `throttle:read` (200/min)
- POST routes → `throttle:create` (30/min)
- PUT/PATCH routes → `throttle:edit` (20/min)
- DELETE routes → `throttle:delete` (10/min)
- Approve/Reject → `throttle:critical` (10/min)

**Defined in:** 
- Rate limiters: `backend/app/Providers/RouteServiceProvider.php::configureRateLimiting()`
- Route application: `backend/routes/api.php` (all endpoints have specific throttle middleware)

## Owner Protection System (CRITICAL)
**Owner is the supreme tenant administrator created during tenant registration:**

### Core Rules
1. **ONE Owner per tenant** - Created automatically during `/api/tenants/register`
2. **Cannot be deleted** - 403 error on delete attempts (`TechnicianController::destroy()`)
3. **Self-edit only** - Only Owner can edit themselves, and **only the name field**
4. **Hidden from others** - Owners only visible to themselves in user lists
5. **All 21 permissions** - Owner has every system permission by default

### Backend Implementation
**Location:** `backend/app/Http/Controllers/Api/TechnicianController.php`

```php
// index() - Visibility filtering
if ($user->hasRole('Owner')) {
    // Owner sees ALL users including other Owners
    $technicians = Technician::with(['user.roles'])->get();
} else {
    // Non-Owners see everyone EXCEPT Owners
    $technicians = Technician::whereDoesntHave('user.roles', function($q) {
        $q->where('name', 'Owner');
    })->get();
}

// update() - Self-edit protection
if ($technician->user && $technician->user->hasRole('Owner')) {
    if ($currentUser->id !== $technician->user_id) {
        return response()->json(['message' => 'Owner users cannot be edited by others.'], 403);
    }
    // Owner can only update name
    $validated = $request->validate(['name' => 'string|max:255']);
    $technician->update(['name' => $validated['name']]);
}

// destroy() - Delete protection
if ($technician->user && $technician->user->hasRole('Owner')) {
    return response()->json(['message' => 'Owner users cannot be deleted.'], 403);
}
```

### Frontend Implementation
**Location:** `frontend/src/components/Admin/UsersManager.tsx`

```tsx
// Edit button disabled for Owners (except self)
disabled={user?.is_owner && user.user_id !== currentUser?.id}

// Delete button disabled for ALL Owners
disabled={user?.is_owner}

// Dialog fields disabled except name when editing Owner
<TextField
  disabled={editingUser?.is_owner && field !== 'name'}
  // Password field hidden for Owners
  type={field === 'password' && !editingUser?.is_owner ? 'password' : 'text'}
/>

// Owner badge in DataGrid
{user.is_owner && (
  <Chip label="Owner" size="small"
    sx={{ ml: 1, bgcolor: '#fbbf24', color: '#78350f' }} />
)}
```

**Location:** `frontend/src/components/Layout/SideMenu.tsx`

```tsx
// Single badge next to user name (NO duplicates)
const isOwner = user?.roles?.includes('Owner');
const displayRole = isOwner ? 'Owner' : (isSuperAdmin ? 'Admin' : null);

{displayRole && (
  <Chip label={displayRole} size="small"
    sx={{ bgcolor: displayRole === 'Owner' ? '#fbbf24' : '#8b5cf6' }} />
)}
```

### UI Badges
- **Owner badge**: Gold background `#fbbf24`, brown text `#78350f`
- **Admin badge**: Purple background `#8b5cf6`, white text
- **Location**: Single badge in SideMenu header (not duplicated), DataGrid name column

## Critical Business Rules (DO NOT VIOLATE)
### Time Overlap Prevention
**Location:** `backend/app/Http/Requests/StoreTimesheetRequest.php::hasTimeOverlap()`

```php
// Logic: Checks if new_start < existing_end AND existing_start < new_end
// Applies to ALL technician's timesheets on same date (cross-project)
// Returns 409 Conflict with error: 'time_overlap' => 'Time overlap detected...'
```


### Status Immutability
**Timesheets/Expenses com status `approved` ou `closed` NÃO podem ser editados/apagados** (exceto Admins).
**Status `closed`** = Payroll processado (apenas Admin ou Manager pode fechar manualmente via endpoint `/close`).

### Status Flow
```
draft → submitted → approved → closed (manual)
                 ↓
              rejected (pode voltar a draft)
```

### Auto-increment Time (Frontend UX)
**Location:** `frontend/src/components/Timesheets/TimesheetCalendar.tsx` (lines 438, 499, 1326, 1332)

```tsx
// When start_time selected, end_time auto-increments by 1 hour
const endTime = startTime.add(1, 'hour');
// Duration field is readonly (calculated from start/end times)
```

## Manager Visibility Architecture (CRITICAL)
**Two-Layer Filtering System to enforce Manager segregation:**

### Layer 1: Backend Query Filtering
**Location:** `backend/app/Http/Controllers/Api/TimesheetController.php::index()` (lines 33-65)

```php
// Managers only receive timesheets from technicians with project_role='member'
elseif ($request->user()->isProjectManager()) {
    $user = $request->user();
    $allManagedProjectIds = $user->getManagedProjectIds();
    $managerTechnician = Technician::where('user_id', $user->id)->first();
    
    $query->where(function ($q) use ($allManagedProjectIds, $managerTechnician) {
        // Include manager's own timesheets
        if ($managerTechnician) {
            $q->where('technician_id', $managerTechnician->id);
        }
        
        // Include timesheets from 'member' technicians ONLY
        if (!empty($allManagedProjectIds)) {
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
        }
    });
}
```

### Layer 2: Frontend Filtering
**Location:** `frontend/src/components/Timesheets/TimesheetCalendar.tsx`

```tsx
// Helper to check view permissions
const canViewTimesheet = useCallback((timesheet: Timesheet): boolean => {
    if (!user) return false;
    if (userIsAdmin) return true;
    if (isTimesheetOwnedByUser(timesheet)) return true;
    
    // Managers: trust backend filtering
    if (userIsManager && user.managed_projects?.includes(timesheet.project_id)) {
        return true;
    }
    return false;
}, [user, userIsAdmin, userIsManager, isTimesheetOwnedByUser]);

// Apply to visible timesheets
const visibleTimesheets = useMemo(() => {
    const viewableTimesheets = timesheets.filter(t => canViewTimesheet(t));
    // ... then apply scope filters (mine/others/all)
}, [timesheets, canViewTimesheet, /* ... */]);
```

**Result:** Managers NEVER see timesheets from other managers in the same project.

## Controller Pattern (STRICT TEMPLATE)
```php
// backend/app/Http/Controllers/TimesheetController.php
public function store(StoreTimesheetRequest $request): JsonResponse {
    $this->authorize('create', Timesheet::class);  // 1. Policy check first
    $validated = $request->validated();             // 2. FormRequest includes overlap validation
    
    // 3. Auto-resolve technician_id if not provided (Managers/Admins can create for others)
    if (!isset($validated['technician_id'])) {
        $validated['technician_id'] = Technician::where('user_id', auth()->id())->first()->id;
    }
    
    $timesheet = Timesheet::create($validated);    // 4. HasAuditFields auto-sets created_by/updated_by
    return response()->json($timesheet, 201);
}
```

**NEVER:**
- Skip `$this->authorize()` calls in mutation methods
- Manually set `created_by`/`updated_by` (trait handles it)
- Return raw `$model->toArray()` (use JsonResponse or ApiResource)
- Duplicate validation logic between FormRequest and Controller

## React Component Pattern

### Global Notification System (MANDATORY)
**ALL components MUST use NotificationContext** instead of local snackbar state.

**Setup (already configured):**
```tsx
// frontend/src/contexts/NotificationContext.tsx - Global provider with AlertSnackbar
// frontend/src/App.tsx - NotificationProvider wraps entire application
```

**Component Usage Pattern:**
```tsx
import { useNotification } from '../../contexts/NotificationContext';

const MyComponent: React.FC = () => {
  const { showSuccess, showError, showWarning, showInfo } = useNotification();
  
  // NO local snackbar state needed!
  // const [snackbar, setSnackbar] = useState({ ... }); // ❌ DELETE THIS
  
  const handleSave = async () => {
    try {
      await api.post('/endpoint', data);
      showSuccess('Item saved successfully');  // ✅ One line
    } catch (error) {
      showError('Failed to save item');        // ✅ One line
    }
  };
  
  // NO <Snackbar> or <AlertSnackbar> in JSX - Context handles it!
  return <Box>...</Box>;
};
```

**Benefits:**
- ✅ **Consistent positioning** (top-right, margin-top to avoid header)
- ✅ **Uniform styling** (filled variant, custom colors)
- ✅ **One line of code** per notification
- ✅ **Automatic message cleanup** (removes duplicate backend error text)
- ✅ **4 severity types**: success (green), error (red), warning (orange), info (orange)

**Migration Checklist** (when updating existing components):
1. Add import: `import { useNotification } from '../../contexts/NotificationContext';`
2. Add hook: `const { showSuccess, showError, showWarning } = useNotification();`
3. Remove: `const [snackbar, setSnackbar] = useState({ ... });`
4. Replace: `setSnackbar({ open: true, message: '...', severity: 'success' })` → `showSuccess('...')`
5. Remove: `<AlertSnackbar>` or `<Snackbar><Alert>` from JSX
6. Remove unused imports: `Snackbar`, `Alert`, `AlertSnackbar`

### Legacy Component Pattern (Deprecated - Use NotificationContext instead)
```tsx
// OLD PATTERN - DO NOT USE
const Manager: React.FC = () => {
  const { isAdmin, hasPermission } = useAuth();
  const [snackbar, setSnackbar] = useState({ open: false, message: '', severity: 'success' }); // ❌ DEPRECATED
  
  // API calls automatically include Authorization header via Axios interceptor
  const response = await api.get('/endpoint');  // No manual header needed
  
  // MUI DataGrid + Dialog + Snackbar pattern for CRUD
};
```

**Frontend API Service Pattern** (`frontend/src/services/api.ts`):
```tsx
// Axios instance with auto-authentication
const api = axios.create({ baseURL: 'http://localhost:8080/api' });

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  if (token) config.headers.Authorization = `Bearer ${token}`;
  return config;
});

// ALL API calls use this instance - never manually add auth headers
export const projectsApi = {
  getAll: () => api.get('/projects').then(res => res.data),
  create: (data) => api.post('/projects', data).then(res => res.data)
};
```

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

## Common Pitfalls
1. **Missing Policy check**: Controllers MUST call `$this->authorize()` before mutations
2. **Duplicate validation**: Don't re-implement `hasTimeOverlap()` - it's in FormRequest
3. **Manager segregation**: Managers **CANNOT** ver/editar/aprovar registos de OUTROS managers do mesmo projeto - backend filtra via `whereHas('user.memberRecords')` + frontend verifica com `canViewTimesheet()`
4. **Container ports**: Backend external `:8080` (nginx internal `:80`), MySQL `:3307` (internal `:3306`)
5. **Auth headers**: Frontend uses `api` service from `services/api.ts` - NEVER manually add auth headers
6. **Status checks**: Always verify timesheet/expense status before allowing edits
7. **Technician lookup**: Use `where('user_id', auth()->id())` not `where('email', ...)`
8. **Environment mismatch**: Frontend `VITE_API_URL=http://localhost:8080` from host browser, internal container uses `:80`
9. **FullCalendar DOM manipulation**: Use `eventDidMount` callback, not `eventContent` - preserve native positioning by inserting elements (don't replace innerHTML)
10. **Calendar view types**: Different views have different DOM structures (timeGrid vs dayGrid vs list) - always check `info.view.type`
11. **Self-approval**: Managers PODEM aprovar/rejeitar os próprios timesheets/expenses (não há conflito de interesses - managers são responsáveis pelo trabalho)
12. **Role verification order**: Sempre verificar ownership PRIMEIRO (`user_id === $user->id`), DEPOIS verificar role do owner para bloquear managers
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

## Additional Documentation

### Expense Workflow
The expense system has evolved from simple approval to multi-stage finance workflow:
- **Stage 1**: Expense Manager validates receipts → `finance_review`
- **Stage 2**: Finance Team approves payment → `finance_approved`
- **Stage 3**: Payment processed → `paid`
- **Deprecated**: Plain `approved` status (migrate to `finance_review`)
- **Reference**: `docs/EXPENSE_WORKFLOW_SPEC.md`

### AI Integration
- **Service**: `backend/app/Services/TimesheetAIService.php`
- **Controller**: `backend/app/Http/Controllers/SuggestionController.php`
- **Frontend Component**: `frontend/src/components/AI/AISuggestionCard.tsx`
- **API Endpoint**: `GET /api/suggestions/timesheet` with `project_id` and `date` params
- **Graceful Degradation**: Falls back to statistical analysis if OpenAI API unavailable
- **User Preference**: localStorage key `timesheet_ai_suggestions_enabled` (boolean)

### Testing & Seeding
```bash
# Run backend tests
docker exec -it timesheet_app php artisan test

# Seed admin user (admin@timeperk.com / admin123)
docker exec -it timesheet_app php artisan db:seed --class=AdminUserSeeder

# Fresh migration with seeders
docker exec -it timesheet_app php artisan migrate:fresh --seed
```

### Documentation Resources
- **Admin Panel**: `docs/ADMIN_PANEL_IMPLEMENTATION.md`
- **Permissions Matrix**: `docs/PERMISSION_MATRIX.md`
- **Development Guide**: `docs/DEVELOPMENT_GUIDELINES.md`
- **Travel Timesheets**: `docs/TRAVEL_TIMESHEETS_FEATURE.md`
- **Database Analysis**: `docs/DATABASE_ANALYSIS_AND_GANTT_PROPOSAL.md`


## Frontend hardening tasks (multi-tenant)

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

