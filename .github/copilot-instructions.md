# TimePerk Cortex - AI Coding Agent Guide

## Architecture & Stack
**Full-stack timesheet/expense management system with Docker Compose orchestration:**
- **Backend**: Laravel 11 + PHP 8.3 (REST API with Sanctum auth)
- **Frontend**: React 18 + TypeScript + Vite + MUI (SPA)
- **Database**: MySQL 8.0 with foreign key constraints
- **Cache**: Redis (sessions + rate limiting)
- **Web Server**: Nginx (reverse proxy to PHP-FPM)

**Key Design Patterns:**
- **RBAC**: Spatie Laravel Permission (3 roles: Technician, Manager, Admin + 17 granular permissions)
- **Triple-Role System**: Separate `project_role`, `expense_role`, and `finance_role` per user per project (via `project_members` pivot table)
- **Policy-Based Authorization**: Laravel Policies enforce ownership + status + project membership rules
- **Middleware Chaining**: Permission gates → Custom middleware → Policy checks
- **Form Request Validation**: Business rules (e.g., time overlap) in dedicated FormRequest classes

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
# MySQL: localhost:3307 (user: timesheet, pass: secret, db: timesheet)
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

**Rate Limits:** `login:5/min`, `create:30/min`, `edit:20/min`, `delete:10/min`, `critical:10/min` (approve/reject)
**Defined in:** `backend/app/Providers/RouteServiceProvider.php::configureRateLimiting()`

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