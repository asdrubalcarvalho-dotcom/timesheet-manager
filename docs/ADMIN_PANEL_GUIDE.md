# Admin Panel Documentation

## Overview
The TimePerk Admin Panel provides comprehensive management of master data tables including Projects, Tasks, Locations, and Technicians.

## Access
- **URL**: Login at http://localhost:3000
- **Credentials**:
  - Email: `admin@timeperk.com`
  - Password: `admin123`
  - ⚠️ **Important**: Change this password in production!

## Features

### 1. Admin Dashboard
- Central hub with quick access to all admin modules
- Four management cards:
  - **Projects**: Manage projects and assignments
  - **Tasks**: Manage tasks and categories
  - **Locations**: Manage work locations
  - **Technicians**: Manage team members

### 2. Projects Manager (`/admin/admin-projects`)
- **CRUD Operations**: Create, Read, Update, Delete projects
- **Fields**:
  - Name (required)
  - Description
  - Start Date
  - End Date
  - Status (Active/Completed/On Hold)
- **Features**:
  - MUI DataGrid with pagination (10/25/50 rows)
  - Inline search and filtering
  - Status color coding (Active=Green, Completed=Blue, On Hold=Orange)

### 3. Tasks Manager (`/admin/admin-tasks`)
- **CRUD Operations**: Full task management
- **Fields**:
  - Name (required)
  - Description
  - Project (optional foreign key)
- **Features**:
  - Project assignment dropdown
  - DataGrid with project name display

### 4. Locations Manager (`/admin/admin-locations`)
- **CRUD Operations**: Manage work locations
- **Fields**:
  - Name (required)
  - Address (optional)
- **Features**:
  - Simple, clean interface
  - Multi-line address input

### 5. Technicians Manager (`/admin/admin-technicians`)
- **CRUD Operations**: Team member management
- **Fields**:
  - Name (required)
  - Email (required, unique)
  - Role (Technician/Manager/Admin)
  - Hourly Rate (optional)
  - Password (required on create, optional on update)
- **Features**:
  - Role badges with color coding
  - User account creation (linked to users table)
  - Password management

## User Interface

### Layout
- **Gradient Purple Header**: Consistent branding across all admin pages
- **Breadcrumbs**: Home → Administration → Current Page
- **Container**: Responsive maxWidth="xl" for all content
- **DataGrid**: Professional table with hover effects, pagination, search

### Color Scheme
- **Projects**: Purple gradient (#667eea → #764ba2)
- **Tasks**: Green (#43a047)
- **Locations**: Orange (#ff9800)
- **Technicians**: Pink (#e91e63)

### Mobile Responsive
- Tables collapse gracefully on mobile
- Dialogs become full-width on small screens
- Touch-friendly buttons and inputs

## Navigation

### Accessing Admin Panel
1. Login as admin user
2. Click **Administration** in the side menu (visible only to Admin role)
3. Expand the Administration submenu
4. Select desired module:
   - Dashboard
   - Projects
   - Tasks
   - Locations
   - Technicians

### Menu Structure
```
Main Navigation
├── Dashboard
├── Timesheets
├── Expenses
├── Approvals
├── Management (collapsible)
│   ├── Projects
│   ├── Team
│   └── AI Insights
└── Administration (collapsible, Admin only)
    ├── Dashboard
    ├── Projects
    ├── Tasks
    ├── Locations
    └── Technicians
```

## API Endpoints

### Projects
- `GET /api/projects` - List all projects
- `POST /api/projects` - Create project
- `PUT /api/projects/{id}` - Update project
- `DELETE /api/projects/{id}` - Delete project

### Tasks
- `GET /api/tasks` - List all tasks
- `POST /api/tasks` - Create task
- `PUT /api/tasks/{id}` - Update task
- `DELETE /api/tasks/{id}` - Delete task

### Locations
- `GET /api/locations` - List all locations
- `POST /api/locations` - Create location
- `PUT /api/locations/{id}` - Update location
- `DELETE /api/locations/{id}` - Delete location

### Technicians
- `GET /api/technicians` - List all technicians
- `POST /api/technicians` - Create technician (with user account)
- `PUT /api/technicians/{id}` - Update technician
- `DELETE /api/technicians/{id}` - Delete technician

## Security

### Role-Based Access Control
- **Admin Role**: Full access to all administration features
- **Manager Role**: No access to administration panel (management features only)
- **Technician Role**: No access to administration panel

### Authorization
- All admin routes protected by `isAdmin()` check in AuthContext
- Side menu Administration section only visible to Admin users
- Backend API endpoints protected by Spatie Permission middleware

### Data Validation
- Form validation on frontend (required fields, email format)
- Backend validation via Laravel Form Requests
- Snackbar notifications for success/error feedback

## Best Practices

### Creating Technicians
1. Always set a strong password (required on creation)
2. Choose appropriate role (Technician/Manager/Admin)
3. Set hourly rate if billing is enabled
4. Verify email is unique before submission

### Managing Projects
1. Set realistic start/end dates
2. Use status transitions: Active → Completed/On Hold
3. Link tasks to projects for better organization
4. Assign manager if project-based permissions needed

### Data Integrity
- Delete operations show confirmation dialog
- Foreign key relationships preserved (tasks linked to projects)
- Cascade deletes handled by backend policies

## Troubleshooting

### Cannot Access Admin Panel
- Verify user has Admin role: Check user roles in database
- Re-login to refresh permissions
- Clear browser cache/cookies

### API Errors
- Check browser console for error details
- Verify Docker containers are running: `docker-compose ps`
- Check backend logs: `docker-compose logs app`

### DataGrid Not Loading
- Check network tab for failed API calls
- Verify API endpoints return 200 status
- Ensure user is authenticated (token in localStorage)

## Technical Stack

### Frontend
- **React 18** with TypeScript
- **MUI DataGrid** for tables
- **React Query** for API state management
- **Axios** for HTTP requests

### Backend
- **Laravel 11** API
- **Spatie Laravel Permission** for roles
- **MySQL 8.0** database
- **Docker** containerization

### Libraries Reused
- MUI components (TextField, Dialog, Button, Snackbar)
- Existing AdminLayout for consistent branding
- AuthContext for role checking (isAdmin())
- API service for HTTP calls

## Future Enhancements
- Bulk operations (multi-select delete)
- Export to CSV/Excel
- Advanced filtering and search
- Audit logs for admin actions
- Manager assignment to projects (projects.manager_id FK)
