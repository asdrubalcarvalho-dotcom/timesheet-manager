# Finance Role Implementation Guide

**Status:** ✅ Phase 1 Complete  
**Date:** November 10, 2025  
**Version:** 1.0

## Overview

The Finance Role system adds a third independent permission dimension to project member management, enabling granular control over finance-specific expense approvals at the project level.

## Architecture

### Triple-Role System

Each user in a project can have three independent roles:

| Role Dimension | Purpose | Values | Scope |
|---------------|---------|--------|-------|
| `project_role` | Timesheet approvals | `'member'` \| `'manager'` | Timesheets only |
| `expense_role` | Expense manager approvals | `'member'` \| `'manager'` | Expense validation |
| `finance_role` | Finance approvals | `'none'` \| `'member'` \| `'manager'` | Finance workflow stages |

**Key Principle:** Roles are **independent** - a user can be a `'manager'` for expenses but `'member'` for timesheets, and `'manager'` for finance on the same project.

## Database Schema

### Migration
**File:** `backend/database/migrations/2025_11_10_211807_add_finance_role_to_project_members_table.php`

```php
Schema::table('project_members', function (Blueprint $table) {
    $table->enum('finance_role', ['none', 'member', 'manager'])
          ->default('none')
          ->after('expense_role');
});
```

### Final Schema
```php
Schema::create('project_members', function (Blueprint $table) {
    $table->id();
    $table->foreignId('project_id')->constrained()->onDelete('cascade');
    $table->foreignId('user_id')->constrained()->onDelete('cascade');
    $table->enum('project_role', ['member', 'manager'])->default('member');
    $table->enum('expense_role', ['member', 'manager'])->default('member');
    $table->enum('finance_role', ['none', 'member', 'manager'])->default('none');
    $table->timestamps();
    $table->unique(['project_id', 'user_id']);
});
```

## Backend Implementation

### 1. Model Updates

**File:** `backend/app/Models/ProjectMember.php`

```php
protected $fillable = [
    'project_id',
    'user_id',
    'project_role',
    'expense_role',
    'finance_role', // Added
];

protected $casts = [
    'project_role' => 'string',
    'expense_role' => 'string',
    'finance_role' => 'string', // Added
];
```

### 2. Authentication Controller

**File:** `backend/app/Http/Controllers/Api/AuthController.php`

**Changes in `login()` method:**
```php
// Get project memberships with roles
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

return response()->json([
    'token' => $token,
    'user' => [
        // ... existing fields
        'project_memberships' => $projectMemberships,
    ]
]);
```

**Same changes applied to `user()` method** for session restoration.

### 3. Project Controller

**File:** `backend/app/Http/Controllers/ProjectController.php`

**Validation in `addMember()`:**
```php
$validated = $request->validate([
    'user_id' => 'required|exists:users,id',
    'project_role' => 'required|in:member,manager',
    'expense_role' => 'required|in:member,manager',
    'finance_role' => 'required|in:member,manager,none', // Added
]);
```

**Validation in `updateMember()`:**
```php
$validated = $request->validate([
    'project_role' => 'required|in:member,manager',
    'expense_role' => 'required|in:member,manager',
    'finance_role' => 'required|in:member,manager,none', // Added
]);

$member->update([
    'project_role' => $validated['project_role'],
    'expense_role' => $validated['expense_role'],
    'finance_role' => $validated['finance_role'], // Added
]);
```

### 4. Project Model Helpers

**File:** `backend/app/Models/Project.php`

**New method added:**
```php
public function isUserFinanceManager(User $user): bool
{
    return $this->members()
        ->where('user_id', $user->id)
        ->where('finance_role', 'manager')
        ->exists();
}

public function getUserFinanceRole(User $user): string
{
    $member = $this->members()
        ->where('user_id', $user->id)
        ->first();
    
    return $member?->finance_role ?? 'none';
}
```

## Frontend Implementation

### 1. Type Definitions

**File:** `frontend/src/components/Auth/AuthContext.tsx`

```typescript
interface User {
  id: number;
  name: string;
  email: string;
  role: 'Technician' | 'Manager' | 'Admin';
  roles: string[];
  permissions: string[];
  is_manager: boolean;
  is_technician: boolean;
  is_admin: boolean;
  managed_projects: number[];
  project_memberships?: Array<{
    project_id: number;
    project_role: 'member' | 'manager';
    expense_role: 'member' | 'manager';
    finance_role: 'none' | 'member' | 'manager';
  }>;
}
```

**File:** `frontend/src/types/index.ts`

```typescript
export interface ProjectMember {
  id: number;
  project_id: number;
  user_id: number;
  user?: {
    id: number;
    name: string;
    email: string;
  };
  project_role: 'member' | 'manager';
  expense_role: 'member' | 'manager';
  finance_role: 'member' | 'manager' | 'none';
}
```

### 2. Authentication Context

**File:** `frontend/src/components/Auth/AuthContext.tsx`

```typescript
const normalizeUser = useCallback((userData: any): User => {
  const roles: string[] = Array.isArray(userData?.roles) ? userData.roles : [];
  const permissions: string[] = Array.isArray(userData?.permissions) ? userData.permissions : [];
  const managedProjectsRaw = Array.isArray(userData?.managed_projects) ? userData.managed_projects : [];
  const projectMemberships = Array.isArray(userData?.project_memberships) ? userData.project_memberships : [];

  return {
    id: Number(userData?.id),
    name: userData?.name ?? '',
    email: userData?.email ?? '',
    role: (userData?.role ?? 'Technician') as User['role'],
    roles,
    permissions,
    is_manager: Boolean(userData?.is_manager ?? roles.includes('Manager')),
    is_technician: Boolean(userData?.is_technician ?? roles.includes('Technician')),
    is_admin: Boolean(userData?.is_admin ?? roles.includes('Admin')),
    managed_projects: managedProjectsRaw.map((projectId: any) => Number(projectId)).filter((id: number) => !Number.isNaN(id)),
    project_memberships: projectMemberships,
  };
}, []);
```

### 3. Project Members Dialog

**File:** `frontend/src/components/Admin/ProjectMembersDialog.tsx`

**Form State:**
```typescript
const [formData, setFormData] = useState<{
  user_id: number | null;
  project_role: 'member' | 'manager';
  expense_role: 'member' | 'manager';
  finance_role: 'none' | 'member' | 'manager';
}>({
  user_id: null,
  project_role: 'member',
  expense_role: 'member',
  finance_role: 'none',
});
```

**UI Layout (3 columns):**
```tsx
<Grid container spacing={2}>
  {/* Timesheet Role */}
  <Grid item xs={4}>
    <TextField
      select
      fullWidth
      label="Timesheet Role"
      value={member.project_role}
      onChange={(e) => handleMemberRoleChange(member.id, 'project_role', e.target.value)}
    >
      <MenuItem value="member">Member</MenuItem>
      <MenuItem value="manager">Manager</MenuItem>
    </TextField>
  </Grid>
  
  {/* Expense Role */}
  <Grid item xs={4}>
    <TextField
      select
      fullWidth
      label="Expense Role"
      value={member.expense_role}
      onChange={(e) => handleMemberRoleChange(member.id, 'expense_role', e.target.value)}
    >
      <MenuItem value="member">Member</MenuItem>
      <MenuItem value="manager">Manager</MenuItem>
    </TextField>
  </Grid>
  
  {/* Finance Role */}
  <Grid item xs={4}>
    <TextField
      select
      fullWidth
      label="Finance Role"
      value={member.finance_role || 'none'}
      onChange={(e) => handleMemberRoleChange(member.id, 'finance_role', e.target.value)}
    >
      <MenuItem value="none">None</MenuItem>
      <MenuItem value="member">Member</MenuItem>
      <MenuItem value="manager">Manager</MenuItem>
    </TextField>
  </Grid>
</Grid>
```

### 4. Approval Manager

**File:** `frontend/src/components/Approvals/ApprovalManager.tsx`

**Finance Role Detection:**
```typescript
// Determine user role for expenses
const hasFinancePermissions = user?.permissions?.some(p => 
  p === 'approve-finance-expenses' || p === 'mark-expenses-paid' || p === 'review-finance-expenses'
);
const isFinanceRole = user?.roles?.includes('Finance');

// Check if user has finance_role: 'manager' in ANY project
const hasFinanceRoleInProjects = user?.project_memberships?.some(
  membership => membership.finance_role === 'manager'
);

const expenseUserRole = isAdmin() 
  ? 'admin' 
  : (hasFinancePermissions || isFinanceRole || hasFinanceRoleInProjects ? 'finance' : 'manager');
```

**Priority Order:**
1. **Admin** → Full access
2. **Finance** (Global permissions OR Global role OR Project-level finance_role) → Finance stages
3. **Manager** → Expense validation only

### 5. Expense Approval Panel

**File:** `frontend/src/components/Approvals/ExpenseApprovalPanel.tsx`

**Selection Logic:**
```typescript
const canSelectExpense = (expense: Expense): boolean => {
  if (userRole === 'admin') return true;
  
  if (userRole === 'manager') {
    return expense.status === 'submitted';
  }
  
  if (userRole === 'finance') {
    return expense.status === 'finance_review' || expense.status === 'finance_approved';
  }
  
  return false;
};
```

## User Experience Flow

### Finance Manager Workflow

1. **Assignment**
   - Admin opens Project Members dialog
   - Selects user and project
   - Sets Finance Role to "Manager"
   - Saves changes

2. **Authentication**
   - User logs in
   - Backend includes `project_memberships` in auth response
   - Frontend stores in User object

3. **Detection**
   - ApprovalManager component loads
   - Checks `user.project_memberships` for `finance_role === 'manager'`
   - Sets `expenseUserRole = 'finance'`

4. **Approval**
   - ExpenseApprovalPanel renders Kanban board
   - Finance stages (`finance_review`, `finance_approved`) show selectable cards
   - User can approve/reject and mark as paid

### Collapsed Sidebar Enhancement

**File:** `frontend/src/components/Layout/SideMenu.tsx`

**Problem Solved:** Management and Administration sections disappeared when sidebar collapsed.

**Solution:**
```tsx
{/* Show icons when collapsed, or full items when expanded */}
{collapsed ? (
  // Show only icons when collapsed
  managementItems.filter(item => item.show).map((item) => (
    <ListItem key={item.id} disablePadding sx={{ mb: 0.5 }}>
      <ListItemButton onClick={() => handleItemClick(item.path)}>
        <ListItemIcon>{item.icon}</ListItemIcon>
      </ListItemButton>
    </ListItem>
  ))
) : (
  // Show collapsible list when expanded
  <Collapse in={managementOpen} timeout="auto" unmountOnExit>
    {/* Full items with text */}
  </Collapse>
)}
```

## Testing Guide

### Backend Tests

```bash
# Test authentication response includes project_memberships
curl -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"user@example.com","password":"password"}' \
  | jq '.user.project_memberships'

# Test user endpoint
curl http://localhost:8080/api/user \
  -H "Authorization: Bearer YOUR_TOKEN" \
  | jq '.project_memberships'
```

### Frontend Tests

1. **Assign Finance Role**
   - Navigate to Admin → Projects
   - Click "Manage Members" on any project
   - Add member with Finance Role = "Manager"
   - Verify API payload includes `finance_role`

2. **Verify User Object**
   - Open DevTools Console
   - Type: `localStorage.getItem('auth_token')`
   - Decode JWT or inspect `/api/user` response
   - Confirm `project_memberships` array exists

3. **Test Expense Approval**
   - Log in as finance manager
   - Navigate to Approvals → Expenses tab
   - Verify Kanban board shows selectable cards in finance columns
   - Test approve/reject actions

4. **Test Sidebar Collapse**
   - Click collapse button (◀/▶)
   - Verify Management section shows 6 icons
   - Verify Administration section shows 2 icons (if admin)
   - Click icons to navigate

## Migration Path

### For Existing Installations

1. **Run Migration**
   ```bash
   docker exec -it timesheet_app php artisan migrate
   ```

2. **Update Existing Members**
   ```sql
   -- Set default finance_role for all existing project members
   UPDATE project_members SET finance_role = 'none';
   ```

3. **Assign Finance Roles**
   - Use Project Members dialog to assign roles
   - Or bulk update via SQL (with caution)

4. **Verify Auth Endpoints**
   - Test `/api/login` returns `project_memberships`
   - Test `/api/user` returns `project_memberships`

5. **Clear Frontend Cache**
   - Users must logout/login to get new auth structure
   - Or force token refresh

## API Reference

### Endpoints Modified

#### `POST /api/login`
**Response:**
```json
{
  "token": "...",
  "user": {
    "id": 1,
    "name": "John Doe",
    "project_memberships": [
      {
        "project_id": 5,
        "project_role": "member",
        "expense_role": "manager",
        "finance_role": "manager"
      }
    ]
  }
}
```

#### `GET /api/user`
**Response:** Same structure as login

#### `POST /api/projects/{project}/members`
**Request:**
```json
{
  "user_id": 2,
  "project_role": "member",
  "expense_role": "member",
  "finance_role": "manager"
}
```

#### `PATCH /api/projects/{project}/members/{member}`
**Request:**
```json
{
  "project_role": "member",
  "expense_role": "manager",
  "finance_role": "manager"
}
```

## Common Issues

### Issue 1: Finance Role Not Saving
**Symptom:** Finance role dropdown resets to "None" after save  
**Cause:** Backend validation missing `finance_role`  
**Fix:** Add to ProjectController validation rules

### Issue 2: Cards Still Disabled
**Symptom:** Finance manager sees disabled cards in expense approval  
**Cause:** `project_memberships` not loaded in user object  
**Fix:** Verify `/api/user` response includes memberships

### Issue 3: Sidebar Icons Missing
**Symptom:** Management/Administration sections disappear when collapsed  
**Cause:** Collapse component uses `unmountOnExit` with `in={!collapsed}`  
**Fix:** Use conditional rendering instead of Collapse-only approach

## Future Enhancements

### Phase 2 (Planned)
- [ ] Finance role permission inheritance
- [ ] Finance dashboard with payment tracking
- [ ] Bulk finance approval actions
- [ ] Finance role audit logs
- [ ] Project-specific finance settings

### Phase 3 (Planned)
- [ ] Multi-level finance approval chains
- [ ] Finance role delegation
- [ ] Automated payment integrations
- [ ] Finance reporting and analytics

## Rollback Procedure

If issues arise, rollback steps:

1. **Database:**
   ```bash
   docker exec -it timesheet_app php artisan migrate:rollback --step=1
   ```

2. **Backend Code:**
   - Revert `AuthController.php` changes
   - Revert `ProjectController.php` validation
   - Revert `ProjectMember.php` fillable/casts

3. **Frontend Code:**
   - Revert `AuthContext.tsx` User interface
   - Revert `ApprovalManager.tsx` finance detection
   - Revert `ProjectMembersDialog.tsx` UI

4. **Clear User Sessions:**
   ```bash
   docker exec -it timesheet_redis redis-cli FLUSHDB
   ```

## Support

For issues or questions:
- Check `docs/EXPENSE_WORKFLOW_SPEC.md` for workflow details
- Review `.github/copilot-instructions.md` for architecture
- Inspect browser DevTools Network tab for API responses
- Check Laravel logs: `backend/storage/logs/laravel.log`

---

**Last Updated:** November 10, 2025  
**Author:** Development Team  
**Version:** 1.0
