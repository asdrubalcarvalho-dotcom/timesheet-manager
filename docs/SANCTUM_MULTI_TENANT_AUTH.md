# Sanctum Multi-Tenant Authentication Solution

## Problem
Laravel Sanctum's `PersonalAccessToken::findToken()` is a **static method** that queries the database **before** Laravel's middleware pipeline initializes tenancy. This causes authentication to fail in multi-database tenancy setups because:

1. `auth:sanctum` middleware runs early in the pipeline
2. Tenant identification middlewares (`tenant.initialize`, `tenant.context`) run after auth
3. Sanctum queries `personal_access_tokens` table in **central database** (doesn't exist there)
4. Result: `Table 'timesheet.personal_access_tokens' doesn't exist` error

## Solution Architecture

### Custom PersonalAccessToken Model
**File:** `backend/app/Models/PersonalAccessToken.php`

**Key Features:**
1. **Extends** `Laravel\Sanctum\PersonalAccessToken`
2. **Overrides** `findToken()` static method to detect tenant from `X-Tenant` header
3. **Dynamically configures** database connection before querying
4. **Falls back** to parent implementation if no tenant header present

### Implementation Details

```php
public static function findToken($token)
{
    // 1. Extract tenant slug from X-Tenant header
    $request = request();
    $tenantSlug = $request ? $request->header('X-Tenant') : null;
    
    if ($tenantSlug) {
        // 2. Find tenant model by slug
        $tenant = \App\Models\Tenant::where('slug', $tenantSlug)->first();
        
        if ($tenant) {
            // 3. Get tenant database name
            $databaseName = $tenant->getInternal('tenancy_db_name') 
                ?: 'timesheet_' . $tenant->getTenantKey();
            
            // 4. Create temporary connection config
            config(['database.connections.tenant_temp' => [
                'driver' => 'mysql',
                'host' => config('database.connections.mysql.host'),
                'database' => $databaseName,
                // ... other mysql config
            ]]);
            
            // 5. Query using temporary connection
            return static::on('tenant_temp')
                ->where('token', hash('sha256', $token))
                ->first();
        }
    }
    
    // 6. Fallback to central database for non-tenant requests
    return parent::findToken($token);
}
```

### Registration in AppServiceProvider
**File:** `backend/app/Providers/AppServiceProvider.php`

```php
use Laravel\Sanctum\Sanctum;
use App\Models\PersonalAccessToken;

public function boot(): void
{
    Sanctum::usePersonalAccessTokenModel(PersonalAccessToken::class);
}
```

## Why This Works

### Before (BROKEN)
```
HTTP Request → auth:sanctum → PersonalAccessToken::findToken()
                                ↓
                            Query: timesheet.personal_access_tokens
                                ↓
                            ERROR: Table doesn't exist
```

### After (WORKING)
```
HTTP Request + X-Tenant header → auth:sanctum → Custom findToken()
                                                  ↓
                                              Read X-Tenant: "upg2-ai-solutions"
                                                  ↓
                                              Find Tenant model
                                                  ↓
                                              Configure connection: tenant_temp
                                                  ↓
                                              Query: timesheet_01K9TN....personal_access_tokens
                                                  ↓
                                              ✅ Token found, user authenticated
```

## Testing Results

### API Login Test
```bash
curl -X POST http://localhost:8080/api/login \
  -H "Content-Type: application/json" \
  -H "X-Tenant: upg2-ai-solutions" \
  -d '{"email":"admin@example.com","password":"secret","tenant_slug":"upg2-ai-solutions"}'

# Response:
{
  "token": "17|mSi8LSJ9KkTvlXNMAxxx...",
  "user": {
    "id": 1,
    "name": "Asdrubal",
    "email": "acarvalho@upg2ai.com",
    "tenant": {
      "id": "01K9TNKJYFZM6RDNX6F6WYTES6",
      "slug": "upg2-ai-solutions",
      "name": "UPG2 AI Solutions"
    }
  }
}
```

### Protected Endpoint Test
```bash
curl -X GET http://localhost:8080/api/user \
  -H "Authorization: Bearer 17|mSi8LSJ9KkTvlXNMAxxx..." \
  -H "X-Tenant: upg2-ai-solutions"

# Response:
{
  "id": 1,
  "name": "Asdrubal",
  "email": "acarvalho@upg2ai.com",
  "tenant": {
    "slug": "upg2-ai-solutions"
  }
}
```

### Tenant Isolation Test
```bash
# Query projects endpoint - returns only tenant's data
curl -X GET http://localhost:8080/api/projects \
  -H "Authorization: Bearer 17|..." \
  -H "X-Tenant: upg2-ai-solutions"

# Response: [] (empty array for new tenant)
# ✅ Tenant isolation confirmed
```

## Critical Requirements

### Frontend Must Send X-Tenant Header
**All API requests** from frontend must include `X-Tenant` header with tenant slug.

**Axios Configuration:**
```typescript
// frontend/src/services/api.ts
api.interceptors.request.use((config) => {
  const tenantSlug = localStorage.getItem('tenant_slug');
  if (tenantSlug) {
    config.headers['X-Tenant'] = tenantSlug;
  }
  return config;
});
```

### Database Table Location
- **Central DB (`timesheet`)**: `tenants`, `domains`, `migrations`
- **Tenant DB (`timesheet_{id}`)**: `users`, `personal_access_tokens`, `projects`, `timesheets`, etc.

**Migration folders:**
- `database/migrations/` → Central database tables
- `database/migrations/tenant/` → Tenant database tables (includes `personal_access_tokens`)

## Alternative Approaches (NOT USED)

### ❌ Middleware Before Auth
**Problem:** Laravel pipeline executes nested middleware groups from inside-out, so `auth:sanctum` runs before tenant middlewares even if placed in outer group.

### ❌ Custom Sanctum Guard
**Problem:** Requires replacing entire Guard implementation, more complex than overriding single method.

### ❌ Tenancy Bootstrapper
**Problem:** Bootstrappers run AFTER tenant initialization, but Sanctum needs connection BEFORE initialization.

## Advantages of This Solution

1. **Minimal code changes** - Single model override
2. **No breaking changes** - Fallback maintains compatibility with central DB requests
3. **Transparent to controllers** - All existing code works unchanged
4. **Maintains Sanctum features** - Token abilities, expiration, etc. all work
5. **Tenant isolation** - Each tenant's tokens only work with their database

## Future Improvements

### Performance Optimization
- Cache tenant lookup by slug (reduce DB queries)
- Connection pooling for frequently accessed tenants

### Security Enhancements
- Rate limit by tenant (not just global)
- Add tenant-specific token scopes
- Audit log for cross-tenant access attempts

## Related Documentation
- [Multi-Database Tenancy Fixes](./MULTI_DATABASE_TENANCY_FIXES.md)
- [Tenant Registration Flow](./TENANT_REGISTRATION.md)
- [Stancl Tenancy Documentation](https://tenancyforlaravel.com/)
- [Laravel Sanctum Documentation](https://laravel.com/docs/11.x/sanctum)

## Changelog

### 2025-11-12 - Initial Implementation
- Created custom `PersonalAccessToken` model
- Implemented dynamic connection configuration in `findToken()`
- Registered custom model with Sanctum
- All authentication tests passing ✅
