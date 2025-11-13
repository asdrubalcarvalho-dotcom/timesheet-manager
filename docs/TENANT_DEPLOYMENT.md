# 🏢 TimePerk SaaS - Multitenant Deployment Guide

## 📋 Table of Contents
- [Overview](#overview)
- [Architecture](#architecture)
- [Installation](#installation)
- [Configuration](#configuration)
- [Tenant Onboarding](#tenant-onboarding)
- [Database Management](#database-management)
- [API Access Patterns](#api-access-patterns)
- [Artisan Commands](#artisan-commands)
- [Troubleshooting](#troubleshooting)

---

## 🎯 Overview

TimePerk is now a **multi-tenant SaaS platform** where each company (tenant) operates in complete isolation with its own database and data.

### Key Features:
- **Central Database**: Stores tenant metadata (`tenants`, `domains`, `companies` tables only)
- **Tenant Databases**: Each tenant has an isolated database (`timesheet_<slug>`) with full schema
- **Flexible Access**: Supports subdomain-based and header-based tenant identification
- **Auto-Provisioning**: Automated tenant database creation and migration
- **Role Isolation**: Each tenant has independent roles, permissions, and users

---

## 🏗️ Architecture

### Two-Layer Database Model

```
┌─────────────────────────────────────────────────────────────┐
│                   CENTRAL DATABASE (mysql)                   │
│                                                              │
│  ┌──────────────┐  ┌──────────────┐  ┌──────────────┐      │
│  │   tenants    │  │   domains    │  │  companies   │      │
│  │              │  │              │  │              │      │
│  │ - id (ULID)  │  │ - domain     │  │ - tenant_id  │      │
│  │ - slug       │  │ - tenant_id  │  │ - name       │      │
│  │ - name       │  │              │  │ - settings   │      │
│  │ - status     │  │              │  │              │      │
│  └──────────────┘  └──────────────┘  └──────────────┘      │
│                                                              │
└─────────────────────────────────────────────────────────────┘

                              ↓↓↓

┌─────────────────────────────────────────────────────────────┐
│            TENANT DATABASE (timesheet_<slug>)                │
│                                                              │
│  ┌─────────┐  ┌───────────┐  ┌──────────┐  ┌──────────┐   │
│  │  users  │  │  projects │  │timesheet │  │ expenses │   │
│  ├─────────┤  ├───────────┤  ├──────────┤  ├──────────┤   │
│  │  tasks  │  │ locations │  │technician│  │  events  │   │
│  └─────────┘  └───────────┘  └──────────┘  └──────────┘   │
│                                                              │
│  + Full application schema (40+ tables)                      │
│  + Roles & Permissions (Spatie)                             │
│  + Audit logs (spatie/laravel-activitylog)                  │
└─────────────────────────────────────────────────────────────┘
```

### Tenant Identification

**Three methods (in order of precedence):**

1. **Subdomain**: `https://acme.app.timeperk.com/api/projects`
2. **X-Tenant Header**: `curl -H "X-Tenant: acme" https://app.timeperk.com/api/projects`
3. **Query Parameter**: `https://app.timeperk.com/api/projects?tenant=acme`

---

## 📦 Installation

### 1. Install Dependencies

```bash
cd backend
composer install

cd ../frontend
npm install
```

### 2. Configure Environment

Copy `.env.example` to `.env` and configure:

```bash
# Central Database (stores tenant metadata only)
DB_CONNECTION=mysql
DB_HOST=database
DB_PORT=3306
DB_DATABASE=timesheet_central
DB_USERNAME=timesheet
DB_PASSWORD=secret

# Tenancy Configuration
CENTRAL_DOMAINS="127.0.0.1,localhost,app.timeperk.localhost"
TENANCY_HEADER=X-Tenant
TENANCY_QUERY_PARAMETER=tenant
TENANCY_BASE_DOMAIN=app.timeperk.localhost
TENANCY_AUTO_PROVISION_DOMAINS=false
TENANCY_OPS_EMAIL=ops@yourdomain.com
TENANCY_AUTO_REGISTER_ON_REQUEST=false
TENANCY_ALLOW_CENTRAL_FALLBACK=true
TENANCY_FALLBACK_ENVIRONMENTS="local,development,testing"
TENANCY_CENTRAL_CONNECTION=mysql
TENANCY_TENANT_CONNECTION=tenant
TENANCY_DATABASE_PREFIX=timesheet_
TENANCY_DATABASE_SUFFIX=

# Tenant Database Template Connection
TENANT_DB_HOST=${DB_HOST}
TENANT_DB_PORT=${DB_PORT}
TENANT_DB_USERNAME=${DB_USERNAME}
TENANT_DB_PASSWORD=${DB_PASSWORD}
```

### 3. Run Migrations (Central DB)

```bash
php artisan migrate
```

This creates:
- `tenants` table
- `domains` table  
- `companies` table

### 4. Rebuild Docker Containers

```bash
docker-compose up --build -d
```

---

## ⚙️ Configuration

### config/tenancy.php

Key settings:

```php
return [
    // Use ULID for tenant IDs
    'id_generator' => App\Support\Tenancy\UlidGenerator::class,
    
    // Central domains (no tenant context)
    'central_domains' => ['127.0.0.1', 'localhost', 'app.timeperk.localhost'],
    
    // Tenant identification
    'identification' => [
        'header' => 'X-Tenant',
        'query_parameter' => 'tenant',
    ],
    
    // Domain settings
    'domains' => [
        'base' => 'app.timeperk.localhost',
        'auto_provision' => false, // Set true if DNS automation is available
        'ops_email' => 'ops@example.com',
    ],
    
    // Enable DatabaseTenancyBootstrapper for separate tenant DBs
    'bootstrappers' => [
        Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\CacheTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\FilesystemTenancyBootstrapper::class,
        Stancl\Tenancy\Bootstrappers\QueueTenancyBootstrapper::class,
    ],
    
    // Database naming
    'database' => [
        'prefix' => 'timesheet_',
        'suffix' => '',
    ],
];
```

---

## 🚀 Tenant Onboarding

### Method 1: API Registration (Recommended)

**Endpoint**: `POST /api/tenants/register`

**Request**:
```json
{
  "company_name": "Acme Corporation",
  "slug": "acme",
  "admin_name": "John Doe",
  "admin_email": "john@acme.com",
  "admin_password": "SecurePassword123!",
  "admin_password_confirmation": "SecurePassword123!",
  "industry": "Technology",
  "country": "US",
  "timezone": "America/New_York",
  "plan": "trial"
}
```

**Response** (201 Created):
```json
{
  "message": "Tenant created successfully",
  "tenant": {
    "id": "01HX...",
    "slug": "acme",
    "name": "Acme Corporation",
    "domain": "acme.app.timeperk.localhost",
    "status": "active",
    "trial_ends_at": "2025-11-25T10:00:00.000000Z"
  },
  "admin_email": "john@acme.com",
  "next_steps": {
    "login_url": "https://acme.app.timeperk.localhost/login",
    "api_access": "Include X-Tenant: acme header in API requests"
  }
}
```

**What Happens**:
1. Creates `Tenant` record in central DB
2. Creates `Domain` record (`acme.app.timeperk.localhost`)
3. Creates `Company` record linked to tenant
4. Creates new MySQL database (`timesheet_acme`)
5. Runs all tenant migrations in new DB
6. Creates Admin user with password
7. Seeds roles and permissions
8. Returns access credentials

### Method 2: Artisan Command

```bash
# Create tenant manually via CLI
php artisan tinker

$tenant = App\Models\Tenant::create([
    'name' => 'Demo Company',
    'slug' => 'demo',
    'owner_email' => 'admin@demo.com',
    'status' => 'active',
    'plan' => 'standard',
]);

// Create domain
Stancl\Tenancy\Database\Models\Domain::create([
    'domain' => 'demo.app.timeperk.localhost',
    'tenant_id' => $tenant->id,
]);

// Run migrations for this tenant
php artisan tenants:migrate demo --seed
```

---

## 🗄️ Database Management

### Migration Commands

```bash
# Migrate all tenants
php artisan tenants:migrate --all

# Migrate specific tenant
php artisan tenants:migrate acme

# Fresh migration with seed
php artisan tenants:migrate acme --fresh --seed

# Seed all tenants
php artisan tenants:seed --all

# Seed specific tenant
php artisan tenants:seed acme

# List all tenants
php artisan tenants:list

# Filter by status
php artisan tenants:list --status=active
```

### Tenant Migrations Location

- **Central migrations**: `database/migrations/` (tenants, domains, companies)
- **Tenant migrations**: `database/migrations/tenant/` (users, projects, timesheets, etc.)

**Moving migrations to tenant folder**:
```bash
# Move business logic migrations to tenant folder
mv database/migrations/*_create_users_table.php database/migrations/tenant/
mv database/migrations/*_create_projects_table.php database/migrations/tenant/
mv database/migrations/*_create_timesheets_table.php database/migrations/tenant/
# ... repeat for all tenant-scoped tables
```

---

## 🔌 API Access Patterns

### Authentication Flow

**1. Login (Tenant-Scoped)**

```bash
# Option 1: Subdomain
curl -X POST https://acme.app.timeperk.localhost/api/login \
  -H "Content-Type: application/json" \
  -d '{"email":"john@acme.com","password":"password123"}'

# Option 2: Header-based
curl -X POST https://app.timeperk.localhost/api/login \
  -H "X-Tenant: acme" \
  -H "Content-Type: application/json" \
  -d '{"email":"john@acme.com","password":"password123"}'

# Option 3: Query parameter
curl -X POST "https://app.timeperk.localhost/api/login?tenant=acme" \
  -H "Content-Type: application/json" \
  -d '{"email":"john@acme.com","password":"password123"}'
```

**Response**:
```json
{
  "token": "1|abc123...",
  "user": {
    "id": 1,
    "name": "John Doe",
    "email": "john@acme.com",
    "tenant_id": "01HX...",
    "roles": ["Admin"]
  }
}
```

**2. Authenticated Requests**

All subsequent requests must include:
- **Authorization header**: `Bearer <token>`
- **Tenant identification**: subdomain OR `X-Tenant` header OR `?tenant=` param

```bash
curl https://acme.app.timeperk.localhost/api/projects \
  -H "Authorization: Bearer 1|abc123..."

# OR

curl https://app.timeperk.localhost/api/projects \
  -H "Authorization: Bearer 1|abc123..." \
  -H "X-Tenant: acme"
```

### Frontend Integration

**Update `frontend/src/services/api.ts`**:

```typescript
import axios from 'axios';

const api = axios.create({
  baseURL: import.meta.env.VITE_API_URL || 'http://localhost:8080/api',
});

// Extract tenant from subdomain or use stored value
const getTenantSlug = (): string | null => {
  // Try subdomain first
  const host = window.location.hostname;
  const parts = host.split('.');
  if (parts.length > 2 && parts[0] !== 'app') {
    return parts[0]; // e.g., "acme" from "acme.app.timeperk.com"
  }
  
  // Fall back to localStorage
  return localStorage.getItem('tenant_slug');
};

api.interceptors.request.use((config) => {
  const token = localStorage.getItem('auth_token');
  const tenantSlug = getTenantSlug();
  
  if (token) {
    config.headers.Authorization = `Bearer ${token}`;
  }
  
  if (tenantSlug) {
    config.headers['X-Tenant'] = tenantSlug;
  }
  
  return config;
});

export default api;
```

**Update `frontend/src/components/Auth/AuthContext.tsx`**:

```typescript
interface AuthContextData {
  user: User | null;
  tenant: Tenant | null; // Add tenant
  login: (email: string, password: string, tenantSlug: string) => Promise<void>;
  // ...
}

const login = async (email: string, password: string, tenantSlug: string) => {
  const response = await api.post('/login', { email, password }, {
    headers: { 'X-Tenant': tenantSlug }
  });
  
  localStorage.setItem('auth_token', response.data.token);
  localStorage.setItem('tenant_slug', tenantSlug);
  setUser(response.data.user);
  setTenant(response.data.tenant);
};
```

---

## 🛠️ Artisan Commands

### Tenant Management

| Command | Description |
|---------|-------------|
| `php artisan tenants:list` | List all tenants |
| `php artisan tenants:list --status=active` | Filter tenants by status |
| `php artisan tenants:migrate --all` | Migrate all tenant databases |
| `php artisan tenants:migrate acme` | Migrate specific tenant |
| `php artisan tenants:migrate acme --fresh --seed` | Fresh migration + seed |
| `php artisan tenants:seed --all` | Seed all tenant databases |
| `php artisan tenants:seed acme` | Seed specific tenant |
| `php artisan tenants:seed acme --class=DemoSeeder` | Run custom seeder |

### Central Database Commands

```bash
# Run central migrations (tenants, domains, companies)
php artisan migrate

# Seed central database
php artisan db:seed

# Fresh start (WARNING: deletes all data)
php artisan migrate:fresh
```

---

## 🧪 Testing

### Local Development Setup

**1. Edit `/etc/hosts`**:
```
127.0.0.1 app.timeperk.localhost
127.0.0.1 demo.app.timeperk.localhost
127.0.0.1 acme.app.timeperk.localhost
```

**2. Create Demo Tenant**:
```bash
curl -X POST http://localhost:8080/api/tenants/register \
  -H "Content-Type: application/json" \
  -d '{
    "company_name": "Demo Corp",
    "slug": "demo",
    "admin_name": "Demo Admin",
    "admin_email": "admin@demo.com",
    "admin_password": "demo123456",
    "admin_password_confirmation": "demo123456"
  }'
```

**3. Test Login**:
```bash
curl -X POST http://localhost:8080/api/login \
  -H "X-Tenant: demo" \
  -H "Content-Type: application/json" \
  -d '{"email":"admin@demo.com","password":"demo123456"}'
```

---

## 🔧 Troubleshooting

### Issue: "Tenant identifier required"

**Cause**: Request missing `X-Tenant` header, subdomain, or `?tenant=` param

**Solution**:
```bash
# Add X-Tenant header
curl -H "X-Tenant: acme" https://app.timeperk.com/api/projects

# OR use subdomain
curl https://acme.app.timeperk.com/api/projects

# OR use query parameter
curl "https://app.timeperk.com/api/projects?tenant=acme"
```

### Issue: "Database does not exist"

**Cause**: Tenant database not created during onboarding

**Solution**:
```bash
# Manually run migrations for tenant
php artisan tenants:migrate acme
```

### Issue: "No roles assigned to user"

**Cause**: Tenant database not seeded with roles/permissions

**Solution**:
```bash
php artisan tenants:seed acme --class=RolePermissionSeeder
```

### Issue: 403 "Cannot access from central domain"

**Cause**: Trying to access tenant routes from central domain in production

**Solution**:
- Use tenant subdomain: `acme.app.timeperk.com`
- OR add `X-Tenant` header
- OR enable fallback for dev: `TENANCY_ALLOW_CENTRAL_FALLBACK=true`

---

## 📚 Additional Resources

- **Architecture Diagram**: `docs/database/Diagram202511.png`
- **SaaS Instructions**: `docs/saas_multitenant_codex_instructions.md`
- **Copilot Guidelines**: `.github/copilot-instructions.md`
- **Database ERD**: `docs/database/timesheet_core_erd.mmd`

---

## 🚦 Production Checklist

Before deploying to production:

- [ ] Set `TENANCY_ALLOW_CENTRAL_FALLBACK=false`
- [ ] Configure actual domain in `TENANCY_BASE_DOMAIN` (e.g., `app.timeperk.com`)
- [ ] Set up DNS wildcard record: `*.app.timeperk.com → <server-ip>`
- [ ] Configure SSL certificate for wildcard domain
- [ ] Set `TENANCY_AUTO_PROVISION_DOMAINS=true` OR configure Ops email notifications
- [ ] Test tenant onboarding flow end-to-end
- [ ] Verify role/permission seeding works correctly
- [ ] Set up monitoring for tenant database creation
- [ ] Configure backup strategy for both central and tenant databases
- [ ] Test tenant isolation (user from tenant A cannot access tenant B data)
- [ ] Load test with multiple tenants

---

**Version**: 1.0.0  
**Last Updated**: November 11, 2025  
**Maintainer**: TimePerk DevOps Team
