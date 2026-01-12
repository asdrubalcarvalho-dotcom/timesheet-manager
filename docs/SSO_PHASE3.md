# SSO Phase 3 — SSO-only (Tenant Feature Flag)

SSO-3 adds **opt-in SSO-only enforcement** per tenant.
It does **not** affect existing tenants by default and does **not** change public registration.

## What is SSO-only?

When enabled for a tenant:
- Password login is **blocked** for most users.
- Only SSO (Google/Microsoft) is allowed.
- Enforcement is **backend-side** (frontend is UX only).

## Feature flag

Central tenant flag:
- `tenants.require_sso` (boolean)
- Default: `false`
- Reversible: set back to `false`

Migration:
- `backend/database/migrations/2026_01_09_000001_add_require_sso_to_tenants_table.php`

## Backend enforcement

### Password login (`POST /api/login`)

Flow:
1. Resolve tenant from `tenant_slug`.
2. If `tenant.require_sso === true`:
   - Do **not** validate password
   - Do **not** issue token
   - Return HTTP `403` with message:
     - `This organization requires Single Sign-On. Please sign in using SSO.`

Structured log (no PII):

```json
{
  "tenant": "<slug>",
  "reason": "sso_only_enforced",
  "auth_method": "password"
}
```

### SSO login

SSO remains allowed regardless of `require_sso`:
- SSO-1 (login by email) unchanged
- SSO-2 (account linking) unchanged

### Sanctum tokens

Token issuance is unchanged:
- Same token name
- Same abilities
- No impact on existing API clients

## Admin safety net (lock-out prevention)

Even when `require_sso=true`, password login is allowed if:
- User has role `Owner`, OR
- User has permission `auth.password.bypass`

## How to enable / disable

Enable (central DB):
- Set `tenants.require_sso = 1` for the target tenant.

Disable (rollback):
- Set `tenants.require_sso = 0`.

## Tests

Backend tests:
- `backend/tests/Feature/Auth/SsoOnlyEnforcementTest.php`

Run in Docker:

```bash
docker-compose exec -T app php artisan test --no-ansi tests/Feature/Auth/SsoOnlyEnforcementTest.php
```
