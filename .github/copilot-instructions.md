# Copilot Instructions for TimesheetManager Project

## Project Overview
This is a **Copilot Workspace-driven** Laravel 11 + React 18 solution generator for a Timesheet and Expense Management System. The project uses GitHub Copilot Workspace's automated scaffolding approach with **Docker Compose containerization** and comprehensive AI guidance documentation.

## Comprehensive Documentation Structure
- **README.md** - Professional GitHub documentation for end users
- **README_DEV_GUIDE.md** - Human developer guide with extension patterns
- **docs/ai/README_DEV_GUIDE_AI.md** - Concise AI agent operational guide
- **docs/ai/ai_context.json** - Machine-readable context for automated agents
- **.copilot/hooks/** - Session logging and context loading automation

## Architecture & Key Components

### Core Structure (Generated via Copilot Workspace)
- **backend/** - Laravel 11 API with Sanctum auth (PHP 8.3 + MySQL 8.0 containers)
- **frontend/** - React 18 SPA with Vite + TypeScript (Node 20 container)
- **docker-compose.yml** - Multi-service orchestration (app, webserver, database, redis, frontend)
- **docker/** - Nginx configuration and container setup

### Docker Services Architecture
```yaml
# Core services from docker-compose.yml
app: Laravel API (PHP-FPM 8.3)
webserver: Nginx (reverse proxy)
database: MySQL 8.0 (persistent storage)
redis: Redis Alpine (cache + sessions)  
frontend: React SPA (Node 20 + Vite dev server)
```

### Data Models & Business Logic
```php
// Core entities from Laravel migrations
Technician: id, name, email, role, hourly_rate
Project: id, name, start_date, end_date  
Timesheet: technician_id, project_id, task_id, location_id, date, start_time, end_time, hours_worked, description, status
Expense: technician_id, project_id, date, amount, description, attachment_path, status
```

**Business Workflow (Intentionally Minimal)**:
- Technicians submit timesheets and expenses per project
- Project Managers approve or reject submissions  
- **Time Overlap Prevention**: Database triggers prevent overlapping time entries for robust data integrity
- **Auto-increment Time**: Frontend automatically adds 1 hour to end time when start time is selected
- No multi-level escalation or ERP integration required
- All logic contained within local application with database-level validation

## Development Workflow

### 1. Project Generation (Docker-First Automated Process)
The project uses **Copilot Workspace automation** - never manually scaffold:
- Configuration lives in `.copilot/config.yml` with detailed guidance section
- Auto-executes: Docker setup → Laravel creation → React scaffolding → Container build → Service verification
- **Critical**: If generation stops mid-way, re-trigger continues automatically from last step
- Final step triggers `docker-compose up` and CI validation

### 2. Environment Requirements
```bash
# Docker-first development setup
Docker and Docker Compose (required)
Laravel 11 with PHP 8.3 (containerized)
Node 20+ and npm (containerized)  
MySQL 8.0 (containerized)
Redis (containerized)

# Services communication (internal Docker network)
Backend API: http://localhost:80
Frontend SPA: http://localhost:3000
Database: database:3306 (container-to-container)
```

### 3. Extension Strategy 
**CRITICAL**: Agents must extend functionality by modifying the **generated codebase**, not the `.copilot/config.yml`:
- Add new Laravel controllers, models, migrations, or React pages to generated solution
- Only modify YAML config if solution architecture fundamentally changes
- Future features (leave management, reporting, ERP sync) go in backend/frontend code
- Always rebuild Docker containers after major changes

### 4. Testing Approach (Progressive Implementation)
```bash
# Test structure under backend/tests/
1. PHPUnit tests for Controllers and Policies
2. Feature tests for API endpoints
3. Optional: React component tests with Vitest
# CI workflow automatically executes: docker-compose with test suite
```

### 5. Key Development Rules
- **Never ask for user input** - guidance section provides all domain context
- **Auto-continue incomplete steps** - resilient generation process
- **Docker-first development** - all services must be containerized
- **Container health checks** - ensure services are ready before proceeding

## Project-Specific Patterns

### Authentication & Authorization Strategy - FULLY IMPLEMENTED ✅
- **Laravel Sanctum**: API token-based authentication ✅
- **Spatie Laravel Permission**: Professional role and permission management ✅
- **Roles**: Technician, Manager, Admin with granular permissions ✅
- **Form Requests**: Professional validation with StoreTimesheetRequest, UpdateTimesheetRequest ✅

### 📋 **Role-Based Access Control (RBAC) - UPDATED 2025-11-06** ✅

#### **Role Hierarchy & Permissions**:
| Role | View Access | Validation Rights | Project Assignment |
|------|-------------|-------------------|-------------------|
| **Technician** | ✅ Own timesheets only | ❌ Cannot validate | ❌ No project management |
| **Manager** | ✅ Own projects' timesheets + own records | ✅ Can approve/reject timesheets from managed projects | ✅ Assigned to specific projects via `projects.manager_id` |
| **Admin** | ✅ All system records | ✅ Can validate all timesheets | ✅ Can manage all projects |

#### **Database Structure Updates**:
```sql
-- NEW: Project-Manager relationship
ALTER TABLE projects ADD COLUMN manager_id BIGINT UNSIGNED NULL;
ALTER TABLE projects ADD FOREIGN KEY (manager_id) REFERENCES users(id) ON DELETE SET NULL;

-- Existing: User-Role relationship via Spatie Laravel Permission
users -> model_has_roles -> roles
```

#### **Policy Implementation (TimesheetPolicy.php)**:
```php
// Technician: Own records only, no validation
if ($user->hasRole('Technician')) {
    return $timesheet->technician->user_id === $user->id;
}

// Manager: Projects they manage + own records, can validate managed projects
if ($user->hasRole('Manager')) {
    return $timesheet->project->manager_id === $user->id || 
           $timesheet->technician->user_id === $user->id;
}

// Admin: All records, all validations
return $user->hasRole('Admin');
```
- **Permissions**: 17 granular permissions implemented ✅
- **Laravel Policies**: TimesheetPolicy, ExpensePolicy for ownership and status-based rules ✅
- **Middleware**: CheckPermission middleware protecting all API routes ✅
- **Rate Limiting**: Intelligent throttling preventing abuse ✅

### API Design Convention
```php
// Laravel API Resource pattern with professional validation
class TimesheetController extends Controller {
    // Uses StoreTimesheetRequest/UpdateTimesheetRequest for validation
    // Spatie permissions: $this->authorize('create', Timesheet::class)
    // Preserves critical business rules: time overlap validation, auto-increment
}
```

### Permission System (NEW - Professional Implementation)
```php
// Spatie Laravel Permission integration
- Roles: Technician, Manager, Admin
- Permissions: create-timesheets, approve-timesheets, view-reports, etc.
- Usage: $user->can('approve-timesheets'), @can('edit-timesheets')
- Form Requests: StoreTimesheetRequest with time overlap validation preserved

## 🧱 Responsive UI Design (Frontend)

All frontend generation must comply with the **Responsive Design Guidelines** below.

### 📱 General Rules
- Mobile-first layout (`sm:` → `md:` → `lg:` breakpoints)
- Avoid horizontal scroll; prefer stacked vertical layouts on mobile
- Use **MUI Grid or Tailwind Flex/Grid utilities**
- Ensure **tables collapse into cards** for screens under 768px
- Use `gap-4`, `p-4`, and `rounded-xl` for spacing and readability
- Font sizes scale with screen width (`text-sm md:text-base lg:text-lg`)
- Buttons in toolbars (e.g. Export, Approve) stack under 640px
- **Time input fields** use responsive grid layout (1fr on mobile, 1fr 1fr 1fr on desktop)
- **Calculated fields** should be readonly with visual feedback (icons, colors, helper text)

### 🎯 Core Components
| Component | Behavior | Notes |
|------------|-----------|-------|
| `TimesheetCalendar` | Grid → single-column on mobile | Daily time blocks stacked |
| `ExpensesGrid` | Table → card list on mobile | Each expense becomes one card |
| `DashboardSummary` | 3-column grid → vertical cards | KPI layout responsive |
| `ExportPanel` | Horizontal → vertical under 640px | Buttons wrap |
| `ModalForm` | Centered modal → full-width on mobile | Scrollable content |
| `TimeSelectionGrid` | 3-column → single-column | Start/End/Duration with auto-calculation |

### 🌗 Accessibility & Performance
- All components use semantic HTML (`<section>`, `<main>`, `<button>`, `<table>`)
- Maintain WCAG-compliant contrast
- Use `prefers-color-scheme` for dark mode auto support
- Optimize assets for lazy loading

### 🕐 Time Selection UX Patterns
- **Auto-calculation**: Duration fields readonly, calculated from start/end times
- **Auto-increment**: When start time is selected, end time automatically increments by 1 hour
- **Visual feedback**: Icons, colors, and check marks for calculated values
- **Sensible defaults**: 09:00-17:30 (8.5h including lunch break)
- **Input validation**: Real-time feedback with Portuguese error messages
- **Responsive layout**: 3-column grid collapses to single column on mobile

### ⏰ Business Rules (Critical) - IMPLEMENTED ✅
- **No Time Overlaps**: Users cannot create timesheet entries with overlapping time periods on the same date
- **DATABASE**: MySQL 8.0 backend with application-level validation for data integrity
  - ✅ **APPLICATION-LEVEL VALIDATION**: Backend API provides user-friendly error handling (409 Conflict)
- **Application-Level Validation**: Backend API provides comprehensive overlap detection (409 Conflict)
- ✅ Logic: `new_start < existing_end AND existing_start < new_end` implemented in StoreTimesheetRequest
- ✅ MySQL 8.0 database provides reliable data storage with proper constraint handling
- **Time Increment Logic**: Start time selection automatically sets end time (+1 hour)
  - ✅ Frontend auto-increment implemented in TimesheetCalendar component
  - ✅ Logic: `newTime.add(1, 'hour')` for end time auto-increment
- **Multiple Timesheets**: Users can create multiple timesheets per date for different projects
  - ✅ Removed unique constraint restriction per project per date
  - ✅ Time overlap validation prevents conflicts regardless of project
  - ✅ Application validation works across all projects ensuring no time conflicts through API layer

### 🔐 Professional Authorization System - UPDATED 2025-11-06 ✅
- **Spatie Laravel Permission**: Industry-standard role and permission management
  - ✅ **3 Roles**: Technician, Manager, Admin with granular permissions
  - ✅ **17 Permissions**: create-timesheets, approve-timesheets, manage-projects, etc.
  - ✅ **Laravel Policies**: TimesheetPolicy, ExpensePolicy for granular authorization
  - ✅ **Middleware Protection**: CheckPermission middleware on all API routes
  - ✅ **Rate Limiting**: Intelligent throttling (5/min login, 30/min create, 10/min approve)
- **Form Requests**: StoreTimesheetRequest, UpdateTimesheetRequest with preserved business rules
- **API Responses**: Include permission data for frontend authorization UI
- **Ownership Rules**: Technicians can only access their own records
- **Status-Based Rules**: Cannot edit/delete approved records (except Admins)

#### **Validation Rules (Approve/Reject) - IMPLEMENTED ✅**:
```php
// TimesheetController approve/reject methods with authorization
public function approve(Timesheet $timesheet): JsonResponse {
    $this->authorize('approve', $timesheet); // Uses TimesheetPolicy
    $timesheet->approve();
    return response()->json($timesheet);
}

// TimesheetPolicy validation logic
public function approve(User $user, Timesheet $timesheet): bool {
    // Admins: Can validate all timesheets
    if ($user->hasRole('Admin')) return true;
    
    // Managers: Can validate only timesheets from their managed projects
    if ($user->hasRole('Manager')) {
        return $timesheet->project->manager_id === $user->id;
    }
    
    // Technicians: Cannot validate any timesheets
    return false;
}
```

---

## 🧩 AI Guidance Hierarchy
1. `.copilot/config.yml` — generation steps and goals  
2. `docs/ai/ai_context.json` — structured architecture and responsive layout schema  
3. `README_DEV_GUIDE_AI.md` — operational behavior for AI agents  
4. This file — persistent UI and workflow rules for Copilot and ChatGPT agents  

---

## 🧠 Behavior Summary
- **Frontend:** Always responsive, mobile-first, grid or flex layouts
- **Backend:** Immutable Approved entries, RESTful controllers
- **Architecture:** Docker-first, Laravel 11 + React 18
- **Agent Rule:** Never modify `.copilot/config.yml` unless architecture changes