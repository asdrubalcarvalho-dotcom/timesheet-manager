# Tenant Registration & Login Fix - November 2025

## Problem Summary

Multi-tenant registration was failing with database connection errors, and subsequent login was blocked by middleware issues.

---

## Issues Identified

### 1. Array Merge Error in DatabaseConfig.php
**Error**: `array_merge(): Argument #1 must be of type array, null given`  
**Location**: `vendor/stancl/tenancy/src/DatabaseConfig.php:116`

**Root Cause**: Stancl's `DatabaseTenancyBootstrapper` expects full database connection configuration in tenant's internal keys, but only `db_name` and `db_driver` were being set.

**Solution**: Extended `Tenant::internalKeys()` to include all required database credentials:

```php
// backend/app/Models/Tenant.php
public static function internalKeys(): array
{
    return [
        'db_name',
        'db_driver',
        'db_host',      // ✅ ADDED
        'db_port',      // ✅ ADDED
        'db_username',  // ✅ ADDED
        'db_password',  // ✅ ADDED
    ];
}
```

### 2. Email Verification NULL Issue
**Error**: `email_verified_at` remained NULL despite being set in `User::create()`  
**Location**: `backend/app/Models/User.php`

**Root Cause**: Laravel's mass assignment protection blocked the field because it wasn't in `$fillable`.

**Solution**: Added `email_verified_at` to fillable array:

```php
// backend/app/Models/User.php
protected $fillable = [
    'name',
    'email',
    'password',
    'email_verified_at', // ✅ ADDED
];
```

### 3. Tenant Identification on Login
**Error**: `"Tenant could not be identified by request data"`  
**Location**: Login endpoint `/api/login`

**Root Cause**: Middleware `InitializeTenancyByRequestData` executing globally or tenant resolver not recognizing `tenant_slug` in request body.

**Status**: ⚠️ UNDER INVESTIGATION - Suspect model event or observer interfering after User save.

---

## Manual Database Connection Workaround

While debugging, a manual DB connection pattern was implemented to bypass Stancl bootstrapper issues:

### Tenant Registration Pattern
```php
// backend/app/Http/Controllers/Api/TenantController.php
public function register(Request $request)
{
    // ... validation ...
    
    // Create tenant
    $tenant = Tenant::create([...]);
    
    // Get database name
    $databaseName = $tenant->getInternal('db_name');
    
    // Manual DB config (workaround)
    config(['database.connections.tenant' => [
        'driver' => 'mysql',
        'host' => config('database.connections.mysql.host'),
        'port' => config('database.connections.mysql.port'),
        'database' => $databaseName,
        'username' => config('database.connections.mysql.username'),
        'password' => config('database.connections.mysql.password'),
        'charset' => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
        'prefix' => '',
        'strict' => true,
    ]]);
    
    // Create database
    DB::statement("CREATE DATABASE IF NOT EXISTS `{$databaseName}` ...");
    
    // Boot tenant context
    $tenant->run(function () use ($request, $tenant, &$adminToken) {
        // Migrations, seeders, user creation
    });
}
```

### Login Pattern
```php
// backend/app/Http/Controllers/Api/AuthController.php
public function login(Request $request)
{
    // ... find tenant ...
    
    $tenant->run(function () use ($request, $tenant) {
        $databaseName = $tenant->getInternal('db_name');
        
        // Manual DB connection
        config(['database.connections.tenant.database' => $databaseName]);
        DB::purge('tenant');
        DB::reconnect('tenant');
        DB::setDefaultConnection('tenant');
        
        // Authentication logic
    });
}
```

---

## Successful Tenant Creation

**Test Tenant Created**:
- **Slug**: `success1763470193`
- **Database**: `timesheet_01KABG7D81MQQY6WHJDKJSFZHW`
- **Owner User**: `admin@success.com`
- **Password**: `password123`
- **Email Verified**: `2025-11-18 12:50:00` ✅
- **Role**: Owner ✅
- **Tables**: 26 tenant tables created successfully ✅

**Verification**:
```bash
# Database exists
docker exec timesheet_mysql mysql -u timesheet -psecret \
  -e "SHOW DATABASES LIKE 'timesheet_01%';"
# ✅ timesheet_01KABG7D81MQQY6WHJDKJSFZHW

# Owner user created
docker exec timesheet_mysql mysql -u timesheet -psecret \
  -D timesheet_01KABG7D81MQQY6WHJDKJSFZHW \
  -e "SELECT id, email, email_verified_at FROM users WHERE email = 'admin@success.com';"
# ✅ ID: 1, email_verified_at: 2025-11-18 12:50:00
```

---

## Outstanding Issues

### ~~Login Authentication Failure~~ ✅ RESOLVED

**Previous Error**: `"Tenant could not be identified by request data"`

**Root Cause**: Laravel configuration cache persisting the enabled `DatabaseTenancyBootstrapper` even after it was disabled in `config/tenancy.php`.

**Solution**:
1. Disable `DatabaseTenancyBootstrapper` in `backend/config/tenancy.php`
2. Physically remove cache files: `rm -rf bootstrap/cache/*.php`
3. Clear configuration cache: `php artisan config:clear`

**Verification**:
```bash
curl -X POST http://api.localhost/api/login \
  -H "Content-Type: application/json" \
  -H "X-Tenant: success1763470193" \
  -d '{"email":"admin@success.com","password":"password123","tenant_slug":"success1763470193"}'
```

**Response**: ✅ Returns Sanctum token + full user object with 21 permissions

**Key Learnings**:
- Laravel config cache is aggressive - `php artisan config:clear` alone is insufficient
- Must physically delete `bootstrap/cache/*.php` files to force config reload
- Manual DB connection pattern in controllers is now the ONLY method (no Stancl bootstrapper)

---

## DatabaseTenancyBootstrapper Status

**Current State**: ❌ PERMANENTLY DISABLED in `backend/config/tenancy.php`

**History**:
- Initially enabled (default)
- Disabled during debugging (caused array_merge error)
- Re-enabled after extending `Tenant::internalKeys()` (still failed due to config cache)
- **FINAL: Permanently disabled** - manual DB connection pattern adopted

**Performance**: Manual DB connection pattern works reliably. Code duplication is acceptable tradeoff for stability.

**Final Decision**: ✅ Option A - Keep manual pattern
- **Reason**: DatabaseTenancyBootstrapper unreliable with dynamic tenant databases
- **Tradeoff**: Code duplication in controllers vs stability and predictability
- **Pattern**: Every tenant-scoped method uses manual `config()` + `DB::purge()` + `DB::reconnect()`

---

## Authenticated Request Testing ✅ RESOLVED (2025-11-18)

### Problem
Sanctum was querying `timesheet.personal_access_tokens` (central DB) instead of tenant-specific database.

**Error**: `SQLSTATE[42S02]: Base table or view not found: 1146 Table 'timesheet.personal_access_tokens' doesn't exist`

### Root Cause
`auth:sanctum` middleware was executing **before** tenant DB connection was configured. In Laravel 11, middleware aliases defined in `bootstrap/app.php` don't automatically execute - they must be:
1. Used explicitly in routes (`->middleware(['alias'])`)
2. OR added to middleware group via `prependToGroup()`

### Solution
Added `SetSanctumTenantConnection` middleware globally to API group:

```php
// backend/bootstrap/app.php
->withMiddleware(function (Middleware $middleware) {
    // ... middleware aliases ...
    
    // CRITICAL: Prepend SetSanctumTenantConnection to API middleware group
    // This MUST run BEFORE auth:sanctum to configure tenant DB connection
    $middleware->prependToGroup('api', \App\Http\Middleware\SetSanctumTenantConnection::class);
})
```

### How It Works
1. `SetSanctumTenantConnection` runs **first** for ALL `/api/*` routes
2. Reads `X-Tenant` header from request
3. Finds tenant in central DB (using `mysql` connection)
4. Configures `tenant` connection with tenant's database name
5. Sets `DB::setDefaultConnection('tenant')` and `Config::set('database.default', 'tenant')`
6. **Then** `auth:sanctum` executes and queries `tenant.personal_access_tokens` ✅

### Test Result
```bash
curl -X GET http://api.localhost/api/user \
  -H "X-Tenant: success1763470193" \
  -H "Authorization: Bearer 2|c1jV7MfCyupwI0EXBjrLN2mG9lB4HlAfEeHENQGs7fc13587"

# Response: 200 OK
{
  "id": 1,
  "name": "Admin Success",
  "email": "admin@success.com",
  "role": "Technician",
  "roles": ["Owner"],
  "permissions": [21 permissions array],
  "tenant": {
    "id": "01KABG7D81MQQY6WHJDKJSFZHW",
    "slug": "success1763470193",
    "name": "Success Test",
    "status": "active"
  }
}
```

### Files Created/Modified
- **NEW**: `backend/app/Http/Middleware/SetSanctumTenantConnection.php` - Configures tenant DB before Sanctum
- **MODIFIED**: `backend/bootstrap/app.php` - Added `prependToGroup('api', ...)` for global execution
- **MODIFIED**: `backend/routes/api.php` - Simplified middleware stack (removed redundant alias)

---

## Code Cleanup Completed ✅

### Removed
- Debug logs from `SetSanctumTenantConnection.php`
- Debug logs from `InitializeTenancyBySlug.php`
- Unused custom guard code in `AuthServiceProvider.php`
- Unused `TenantSanctumGuard.php` (not needed with global middleware approach)

### Cleaned Up
- `TenantController.php` - Kept essential tenant creation logs only
- `AuthController.php` - Kept essential login flow logs only

---

## Files Modified

1. **Tenant Model**: `backend/app/Models/Tenant.php` - Extended `internalKeys()` for ULID support
2. **User Model**: `backend/app/Models/User.php` - Added `email_verified_at` to `$fillable`
3. **Tenant Controller**: `backend/app/Http/Controllers/Api/TenantController.php` - Manual DB connection in `register()`
4. **Auth Controller**: `backend/app/Http/Controllers/Api/AuthController.php` - Manual DB connection in `login()`
5. **Tenancy Config**: `backend/config/tenancy.php` - Permanently disabled `DatabaseTenancyBootstrapper`
6. **NEW - Sanctum Middleware**: `backend/app/Http/Middleware/SetSanctumTenantConnection.php` - Configures tenant DB before auth
7. **Bootstrap Config**: `backend/bootstrap/app.php` - Added global API middleware for Sanctum tenant connection
8. **API Routes**: `backend/routes/api.php` - Simplified protected route middleware stack

---

## Testing Checklist

- [x] Tenant registration creates database with ULID name
- [x] Tenant database has 26 tables
- [x] Owner user created with correct email
- [x] Owner user has email_verified_at populated
- [x] Owner role assigned
- [x] Login with Owner credentials succeeds
- [x] Login returns Sanctum token with 21 permissions
- [x] **Authenticated GET /api/user works with tenant context** ✅
- [x] **Sanctum queries correct tenant database** ✅
- [ ] Frontend integration test
- [ ] Multi-tenant isolation verification
- [ ] Second tenant creation + isolation test

---

**Date**: 2025-11-18  
**Status**: ✅ **FULLY FUNCTIONAL - REGISTRATION, LOGIN, AND AUTHENTICATED REQUESTS WORKING**  
**Next Action**: Frontend integration testing and multi-tenant isolation verification
